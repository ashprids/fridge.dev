<?php
declare(strict_types=1);

const FRIDG3_HARD_BAN_COOKIE = 'fridg3_hard_ban_id';

if (!function_exists('fridg3_hard_ban_path')) {
    function fridg3_hard_ban_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-banned-ips.txt';
    }
}

if (!function_exists('fridg3_hard_ban_source_directory')) {
    function fridg3_hard_ban_source_directory(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'banlists';
    }
}

if (!function_exists('fridg3_hard_ban_parse')) {
    function fridg3_hard_ban_parse(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ips = [];
        $invalid = [];

        foreach ($tokens as $token) {
            $candidate = trim((string)$token);
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                $invalid[$candidate] = true;
                continue;
            }

            $packed = @inet_pton($candidate);
            $key = $packed === false ? strtolower($candidate) : bin2hex($packed);
            if (!isset($ips[$key])) {
                $ips[$key] = $candidate;
            }
        }

        return [
            'ips' => array_values($ips),
            'invalid' => array_keys($invalid),
        ];
    }
}

if (!function_exists('fridg3_hard_ban_normalize_cidr')) {
    function fridg3_hard_ban_normalize_cidr(string $candidate): ?string
    {
        $parts = explode('/', trim($candidate));
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || !ctype_digit($parts[1])) {
            return null;
        }

        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }

        $prefix = (int)$parts[1];
        $maximumPrefix = strlen($packed) * 8;
        if ($prefix < 0 || $prefix > $maximumPrefix) {
            return null;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $length = strlen($packed);
        for ($index = $wholeBytes; $index < $length; $index++) {
            if ($index === $wholeBytes && $remainingBits !== 0) {
                $packed[$index] = chr(ord($packed[$index]) & (0xff << (8 - $remainingBits)));
                continue;
            }
            $packed[$index] = "\0";
        }

        $network = @inet_ntop($packed);
        return $network === false ? null : $network . '/' . $prefix;
    }
}

if (!function_exists('fridg3_hard_ban_load')) {
    function fridg3_hard_ban_load(): array
    {
        $path = fridg3_hard_ban_path();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        return fridg3_hard_ban_parse($raw)['ips'];
    }
}

if (!function_exists('fridg3_hard_ban_source_paths')) {
    function fridg3_hard_ban_source_paths(): array
    {
        $directory = fridg3_hard_ban_source_directory();
        if (!is_dir($directory)) {
            return [];
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
            $indexDirectory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index';
            $filteredIterator = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (SplFileInfo $item) use ($indexDirectory): bool {
                    return !($item->isDir() && $item->getPathname() === $indexDirectory);
                }
            );
            $iterator = new RecursiveIteratorIterator(
                $filteredIterator,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        try {
            $paths = [];
            foreach ($iterator as $file) {
                if (!$file->isFile() || !$file->isReadable() || strtolower($file->getExtension()) !== 'txt') {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        } catch (UnexpectedValueException) {
            return [];
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}

if (!function_exists('fridg3_hard_ban_source_tokens')) {
    function fridg3_hard_ban_source_tokens(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $carry = '';
        $discardOversizedToken = false;
        $maximumTokenLength = 128;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                if ($discardOversizedToken) {
                    if (!preg_match('/\s/', $chunk, $match, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }
                    $matchedWhitespace = (string)$match[0][0];
                    $chunk = substr($chunk, (int)$match[0][1] + strlen($matchedWhitespace));
                    $discardOversizedToken = false;
                }

                $buffer = $carry . $chunk;
                $carry = '';
                $endsWithWhitespace = preg_match('/\s\z/', $buffer) === 1;
                $tokens = preg_split('/\s+/', $buffer, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                if (!$endsWithWhitespace && $tokens !== []) {
                    $carry = (string)array_pop($tokens);
                    if (strlen($carry) > $maximumTokenLength) {
                        $carry = '';
                        $discardOversizedToken = true;
                    }
                }

                foreach ($tokens as $token) {
                    if (strlen($token) <= $maximumTokenLength) {
                        yield $token;
                    }
                }
            }

            if (!$discardOversizedToken && $carry !== '') {
                yield $carry;
            }
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('fridg3_hard_ban_entry_contains_packed')) {
    function fridg3_hard_ban_entry_contains_packed(string $entry, string $packedCandidate): bool
    {
        if (!str_contains($entry, '/')) {
            $normalizedCandidate = (string)@inet_ntop($packedCandidate);
            if ($entry === $normalizedCandidate) {
                return true;
            }

            $candidateIsIpv6 = strlen($packedCandidate) === 16;
            if (!$candidateIsIpv6 || !str_contains($entry, ':')) {
                return false;
            }

            $packedEntry = @inet_pton($entry);
            return $packedEntry !== false
                && strlen($packedEntry) === strlen($packedCandidate)
                && hash_equals($packedEntry, $packedCandidate);
        }

        $cidr = fridg3_hard_ban_normalize_cidr($entry);
        if ($cidr === null) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $packedNetwork = @inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedCandidate)) {
            return false;
        }

        $wholeBytes = intdiv((int)$prefix, 8);
        $remainingBits = (int)$prefix % 8;
        if ($wholeBytes > 0 && !hash_equals(substr($packedNetwork, 0, $wholeBytes), substr($packedCandidate, 0, $wholeBytes))) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainingBits);
        return (ord($packedNetwork[$wholeBytes]) & $mask) === (ord($packedCandidate[$wholeBytes]) & $mask);
    }
}

if (!function_exists('fridg3_hard_ban_source_signature')) {
    function fridg3_hard_ban_source_signature(array $paths): string
    {
        $sources = [];
        foreach ($paths as $path) {
            $stat = @stat($path);
            if ($stat === false) {
                continue;
            }
            $sources[] = [
                'path' => $path,
                'inode' => (int)($stat['ino'] ?? 0),
                'size' => (int)($stat['size'] ?? 0),
                'modified' => (int)($stat['mtime'] ?? 0),
                'changed' => (int)($stat['ctime'] ?? 0),
            ];
        }

        return hash('sha256', (string)json_encode([
            'format' => 2,
            'sources' => $sources,
        ], JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('fridg3_hard_ban_index_cache_directory')) {
    function fridg3_hard_ban_index_cache_directory(): string
    {
        return fridg3_hard_ban_source_directory() . DIRECTORY_SEPARATOR . 'index';
    }
}

if (!function_exists('fridg3_hard_ban_remove_directory')) {
    function fridg3_hard_ban_remove_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($path);
        } catch (UnexpectedValueException) {
            return;
        }
    }
}

if (!function_exists('fridg3_hard_ban_clean_index_directory')) {
    function fridg3_hard_ban_clean_index_directory(string $cacheDirectory, ?string $currentSignature = null): void
    {
        $entries = @scandir($cacheDirectory);
        if ($entries === false) {
            return;
        }

        $oldIndexCutoff = time() - 3600;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.lock') {
                continue;
            }

            $path = $cacheDirectory . DIRECTORY_SEPARATOR . $entry;
            if (str_starts_with($entry, '.build-')) {
                fridg3_hard_ban_remove_directory($path);
                continue;
            }

            if (
                $currentSignature !== null
                && $entry !== $currentSignature
                && preg_match('/^[a-f0-9]{64}$/', $entry) === 1
                && is_file($path . DIRECTORY_SEPARATOR . '.ready')
                && (int)@filemtime($path . DIRECTORY_SEPARATOR . '.ready') < $oldIndexCutoff
            ) {
                fridg3_hard_ban_remove_directory($path);
            }
        }
    }
}

if (!function_exists('fridg3_hard_ban_entry_range')) {
    function fridg3_hard_ban_entry_range(string $entry): ?array
    {
        if (!str_contains($entry, '/')) {
            $packed = @inet_pton($entry);
            return $packed === false ? null : [$packed, $packed];
        }

        $cidr = fridg3_hard_ban_normalize_cidr($entry);
        if ($cidr === null) {
            return null;
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $start = @inet_pton($network);
        if ($start === false) {
            return null;
        }

        $prefix = (int)$prefixRaw;
        $end = $start;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $length = strlen($end);
        for ($index = $wholeBytes; $index < $length; $index++) {
            if ($index === $wholeBytes && $remainingBits !== 0) {
                $end[$index] = chr(ord($end[$index]) | (0xff >> $remainingBits));
                continue;
            }
            $end[$index] = "\xff";
        }

        return [$start, $end];
    }
}

if (!function_exists('fridg3_hard_ban_write_merged_records')) {
    function fridg3_hard_ban_write_merged_records(array $records, int $recordLength, $handle): bool
    {
        if ($records === []) {
            return true;
        }

        $remainderLength = intdiv($recordLength, 2);
        usort($records, static function (string $left, string $right) use ($remainderLength): int {
            $startComparison = strcmp(substr($left, 0, $remainderLength), substr($right, 0, $remainderLength));
            return $startComparison !== 0
                ? $startComparison
                : strcmp(substr($left, $remainderLength), substr($right, $remainderLength));
        });

        $currentStart = substr($records[0], 0, $remainderLength);
        $currentEnd = substr($records[0], $remainderLength);
        $count = count($records);
        for ($index = 1; $index < $count; $index++) {
            $nextStart = substr($records[$index], 0, $remainderLength);
            $nextEnd = substr($records[$index], $remainderLength);
            if (strcmp($nextStart, $currentEnd) <= 0) {
                if (strcmp($nextEnd, $currentEnd) > 0) {
                    $currentEnd = $nextEnd;
                }
                continue;
            }

            if (fwrite($handle, $currentStart . $currentEnd) !== $recordLength) {
                return false;
            }
            $currentStart = $nextStart;
            $currentEnd = $nextEnd;
        }

        return fwrite($handle, $currentStart . $currentEnd) === $recordLength;
    }
}

if (!function_exists('fridg3_hard_ban_sort_and_merge_bucket')) {
    function fridg3_hard_ban_sort_and_merge_bucket(string $path, int $recordLength): bool
    {
        $sourceHandle = @fopen($path, 'rb');
        if ($sourceHandle === false) {
            return false;
        }

        $runPaths = [];
        $runNumber = 0;
        $succeeded = true;
        try {
            while (!feof($sourceHandle)) {
                $records = [];
                while (count($records) < 50000 && !feof($sourceHandle)) {
                    $record = fread($sourceHandle, $recordLength);
                    if ($record === false || $record === '') {
                        break;
                    }
                    if (strlen($record) !== $recordLength) {
                        $succeeded = false;
                        break 2;
                    }
                    $records[] = $record;
                }

                if ($records === []) {
                    break;
                }

                $runPath = $path . '.run-' . $runNumber++;
                $runHandle = @fopen($runPath, 'wb');
                if ($runHandle === false) {
                    $succeeded = false;
                    break;
                }
                $runSucceeded = fridg3_hard_ban_write_merged_records($records, $recordLength, $runHandle);
                fclose($runHandle);
                if (!$runSucceeded) {
                    @unlink($runPath);
                    $succeeded = false;
                    break;
                }
                $runPaths[] = $runPath;
            }
        } finally {
            fclose($sourceHandle);
        }

        if (!$succeeded || $runPaths === []) {
            foreach ($runPaths as $runPath) {
                @unlink($runPath);
            }
            return false;
        }

        $outputPath = $path . '.sorted';
        $outputHandle = @fopen($outputPath, 'wb');
        if ($outputHandle === false) {
            foreach ($runPaths as $runPath) {
                @unlink($runPath);
            }
            return false;
        }

        $runHandles = [];
        $currentRecords = [];
        $remainderLength = intdiv($recordLength, 2);
        $currentStart = null;
        $currentEnd = null;
        try {
            foreach ($runPaths as $runIndex => $runPath) {
                $runHandles[$runIndex] = @fopen($runPath, 'rb');
                if ($runHandles[$runIndex] === false) {
                    $succeeded = false;
                    break;
                }
                $record = fread($runHandles[$runIndex], $recordLength);
                if ($record !== false && strlen($record) === $recordLength) {
                    $currentRecords[$runIndex] = $record;
                }
            }

            while ($succeeded && $currentRecords !== []) {
                $selectedRun = null;
                $selectedRecord = null;
                foreach ($currentRecords as $runIndex => $record) {
                    if ($selectedRecord === null) {
                        $selectedRun = $runIndex;
                        $selectedRecord = $record;
                        continue;
                    }
                    $startComparison = strcmp(
                        substr($record, 0, $remainderLength),
                        substr($selectedRecord, 0, $remainderLength)
                    );
                    if (
                        $startComparison < 0
                        || ($startComparison === 0 && strcmp(substr($record, $remainderLength), substr($selectedRecord, $remainderLength)) < 0)
                    ) {
                        $selectedRun = $runIndex;
                        $selectedRecord = $record;
                    }
                }

                $nextStart = substr((string)$selectedRecord, 0, $remainderLength);
                $nextEnd = substr((string)$selectedRecord, $remainderLength);
                if ($currentStart === null) {
                    $currentStart = $nextStart;
                    $currentEnd = $nextEnd;
                } elseif (strcmp($nextStart, (string)$currentEnd) <= 0) {
                    if (strcmp($nextEnd, (string)$currentEnd) > 0) {
                        $currentEnd = $nextEnd;
                    }
                } else {
                    if (fwrite($outputHandle, $currentStart . $currentEnd) !== $recordLength) {
                        $succeeded = false;
                        break;
                    }
                    $currentStart = $nextStart;
                    $currentEnd = $nextEnd;
                }

                $nextRecord = fread($runHandles[$selectedRun], $recordLength);
                if ($nextRecord === false || $nextRecord === '') {
                    unset($currentRecords[$selectedRun]);
                } elseif (strlen($nextRecord) !== $recordLength) {
                    $succeeded = false;
                } else {
                    $currentRecords[$selectedRun] = $nextRecord;
                }
            }

            if ($succeeded && $currentStart !== null && fwrite($outputHandle, $currentStart . $currentEnd) !== $recordLength) {
                $succeeded = false;
            }
        } finally {
            fclose($outputHandle);
            foreach ($runHandles as $runHandle) {
                if (is_resource($runHandle)) {
                    fclose($runHandle);
                }
            }
            foreach ($runPaths as $runPath) {
                @unlink($runPath);
            }
        }

        if (!$succeeded || !@rename($outputPath, $path)) {
            @unlink($outputPath);
            return false;
        }

        return true;
    }
}

if (!function_exists('fridg3_hard_ban_build_source_index')) {
    function fridg3_hard_ban_build_source_index(array $paths, string $buildDirectory): bool
    {
        if (!@mkdir($buildDirectory, 0700, true) && !is_dir($buildDirectory)) {
            return false;
        }

        $handles = [];
        $bucketPaths = [];
        $succeeded = true;
        try {
            foreach ($paths as $path) {
                foreach (fridg3_hard_ban_source_tokens($path) as $entry) {
                    $range = fridg3_hard_ban_entry_range($entry);
                    if ($range === null) {
                        continue;
                    }

                    [$start, $end] = $range;
                    $version = strlen($start) === 4 ? '4' : '6';
                    $remainderLength = strlen($start) - 1;
                    $minimumRemainder = str_repeat("\0", $remainderLength);
                    $maximumRemainder = str_repeat("\xff", $remainderLength);
                    $startBucket = ord($start[0]);
                    $endBucket = ord($end[0]);

                    for ($bucket = $startBucket; $bucket <= $endBucket; $bucket++) {
                        $bucketKey = $version . '-' . str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
                        if (!isset($handles[$bucketKey])) {
                            $bucketPath = $buildDirectory . DIRECTORY_SEPARATOR . $bucketKey . '.bin';
                            $handles[$bucketKey] = @fopen($bucketPath, 'ab');
                            if ($handles[$bucketKey] === false) {
                                $succeeded = false;
                                break 3;
                            }
                            $bucketPaths[$bucketKey] = $bucketPath;
                        }

                        $rangeStart = $bucket === $startBucket ? substr($start, 1) : $minimumRemainder;
                        $rangeEnd = $bucket === $endBucket ? substr($end, 1) : $maximumRemainder;
                        $record = $rangeStart . $rangeEnd;
                        if (fwrite($handles[$bucketKey], $record) !== strlen($record)) {
                            $succeeded = false;
                            break 3;
                        }
                    }
                }
            }
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if ($succeeded) {
            foreach ($bucketPaths as $bucketKey => $bucketPath) {
                $recordLength = str_starts_with($bucketKey, '4-') ? 6 : 30;
                if (!fridg3_hard_ban_sort_and_merge_bucket($bucketPath, $recordLength)) {
                    $succeeded = false;
                    break;
                }
            }
        }

        if (!$succeeded || @file_put_contents($buildDirectory . DIRECTORY_SEPARATOR . '.ready', "1\n", LOCK_EX) === false) {
            fridg3_hard_ban_remove_directory($buildDirectory);
            return false;
        }

        return true;
    }
}

if (!function_exists('fridg3_hard_ban_source_index')) {
    function fridg3_hard_ban_source_index(): ?string
    {
        $cacheDirectory = fridg3_hard_ban_index_cache_directory();
        if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0700, true)) {
            return null;
        }

        $paths = fridg3_hard_ban_source_paths();
        $signature = fridg3_hard_ban_source_signature($paths);
        $indexDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . $signature;
        if (is_file($indexDirectory . DIRECTORY_SEPARATOR . '.ready')) {
            return $indexDirectory;
        }

        $lockHandle = @fopen($cacheDirectory . DIRECTORY_SEPARATOR . '.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return null;
        }

        try {
            fridg3_hard_ban_clean_index_directory($cacheDirectory);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $paths = fridg3_hard_ban_source_paths();
                $signature = fridg3_hard_ban_source_signature($paths);
                $indexDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . $signature;
                if (is_file($indexDirectory . DIRECTORY_SEPARATOR . '.ready')) {
                    fridg3_hard_ban_clean_index_directory($cacheDirectory, $signature);
                    return $indexDirectory;
                }

                $buildDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . '.build-' . bin2hex(random_bytes(8));
                if (!fridg3_hard_ban_build_source_index($paths, $buildDirectory)) {
                    return null;
                }

                $currentPaths = fridg3_hard_ban_source_paths();
                if (fridg3_hard_ban_source_signature($currentPaths) !== $signature) {
                    fridg3_hard_ban_remove_directory($buildDirectory);
                    continue;
                }

                if (!@rename($buildDirectory, $indexDirectory) && !is_file($indexDirectory . DIRECTORY_SEPARATOR . '.ready')) {
                    fridg3_hard_ban_remove_directory($buildDirectory);
                    return null;
                }

                fridg3_hard_ban_clean_index_directory($cacheDirectory, $signature);
                return $indexDirectory;
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return null;
    }
}

if (!function_exists('fridg3_hard_ban_source_index_contains')) {
    function fridg3_hard_ban_source_index_contains(string $indexDirectory, string $packedCandidate): bool
    {
        $version = strlen($packedCandidate) === 4 ? '4' : '6';
        $bucket = str_pad(dechex(ord($packedCandidate[0])), 2, '0', STR_PAD_LEFT);
        $path = $indexDirectory . DIRECTORY_SEPARATOR . $version . '-' . $bucket . '.bin';
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $candidateRemainder = substr($packedCandidate, 1);
        $remainderLength = strlen($candidateRemainder);
        $recordLength = $remainderLength * 2;
        try {
            $size = @filesize($path);
            if ($size === false || $size % $recordLength !== 0) {
                return false;
            }

            $low = 0;
            $high = intdiv($size, $recordLength) - 1;
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                if (fseek($handle, $middle * $recordLength) !== 0) {
                    return false;
                }
                $record = fread($handle, $recordLength);
                if ($record === false || strlen($record) !== $recordLength) {
                    return false;
                }

                $start = substr($record, 0, $remainderLength);
                $end = substr($record, $remainderLength);
                if (strcmp($candidateRemainder, $start) < 0) {
                    $high = $middle - 1;
                } elseif (strcmp($candidateRemainder, $end) > 0) {
                    $low = $middle + 1;
                } else {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}

if (!function_exists('fridg3_hard_ban_source_contains')) {
    function fridg3_hard_ban_source_contains(string $candidate): bool
    {
        $packedCandidate = @inet_pton(trim($candidate));
        if ($packedCandidate === false) {
            return false;
        }

        $normalizedCandidate = (string)@inet_ntop($packedCandidate);
        static $results = [];
        if (array_key_exists($normalizedCandidate, $results)) {
            return $results[$normalizedCandidate];
        }

        $indexDirectory = fridg3_hard_ban_source_index();
        if ($indexDirectory !== null) {
            $results[$normalizedCandidate] = fridg3_hard_ban_source_index_contains($indexDirectory, $packedCandidate);
            return $results[$normalizedCandidate];
        }

        foreach (fridg3_hard_ban_source_paths() as $path) {
            foreach (fridg3_hard_ban_source_tokens($path) as $entry) {
                if (fridg3_hard_ban_entry_contains_packed($entry, $packedCandidate)) {
                    $results[$normalizedCandidate] = true;
                    return true;
                }
            }
        }

        $results[$normalizedCandidate] = false;
        return false;
    }
}

if (!function_exists('fridg3_hard_ban_write')) {
    function fridg3_hard_ban_write(array $ips): bool
    {
        $path = fridg3_hard_ban_path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return false;
        }

        $content = $ips === [] ? '' : implode(PHP_EOL, $ips) . PHP_EOL;
        $tempPath = tempnam($directory, 'hard_bans_');
        if ($tempPath === false) {
            return @file_put_contents($path, $content, LOCK_EX) !== false;
        }

        $permissions = @fileperms($path);
        $written = @file_put_contents($tempPath, $content, LOCK_EX) !== false;
        if ($written && $permissions !== false) {
            @chmod($tempPath, $permissions & 0777);
        }
        $saved = $written && @rename($tempPath, $path);
        if (!$saved) {
            @unlink($tempPath);
        }

        return $saved;
    }
}

if (!function_exists('fridg3_hard_ban_client_ip')) {
    function fridg3_hard_ban_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            foreach (explode(',', (string)$_SERVER[$header]) as $part) {
                $candidate = trim($part);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return '';
    }
}

if (!function_exists('fridg3_hard_ban_contains')) {
    function fridg3_hard_ban_contains(string $candidate): bool
    {
        $packedCandidate = @inet_pton(trim($candidate));
        if ($packedCandidate === false) {
            return false;
        }

        $normalizedCandidate = (string)@inet_ntop($packedCandidate);
        return fridg3_hard_ban_list_contains(fridg3_hard_ban_load(), $normalizedCandidate)
            || fridg3_hard_ban_source_contains($normalizedCandidate);
    }
}

if (!function_exists('fridg3_hard_ban_identity_path')) {
    function fridg3_hard_ban_identity_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-ban-identities.json';
    }
}

if (!function_exists('fridg3_hard_ban_valid_identifier')) {
    function fridg3_hard_ban_valid_identifier(string $identifier): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $identifier) === 1;
    }
}

if (!function_exists('fridg3_hard_ban_ips_equal')) {
    function fridg3_hard_ban_ips_equal(string $left, string $right): bool
    {
        $leftPacked = @inet_pton($left);
        $rightPacked = @inet_pton($right);
        return $leftPacked !== false && $rightPacked !== false && hash_equals($leftPacked, $rightPacked);
    }
}

if (!function_exists('fridg3_hard_ban_list_contains')) {
    function fridg3_hard_ban_list_contains(array $ips, string $candidate): bool
    {
        $packedCandidate = @inet_pton(trim($candidate));
        if ($packedCandidate === false) {
            return false;
        }

        foreach ($ips as $ip) {
            if (fridg3_hard_ban_entry_contains_packed((string)$ip, $packedCandidate)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('fridg3_hard_ban_load_identities')) {
    function fridg3_hard_ban_load_identities(): array
    {
        $path = fridg3_hard_ban_identity_path();
        if (!is_file($path)) {
            return ['identities' => []];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['identities']) || !is_array($decoded['identities'])) {
            return ['identities' => []];
        }
        return $decoded;
    }
}

if (!function_exists('fridg3_hard_ban_write_identities')) {
    function fridg3_hard_ban_write_identities(array $data): bool
    {
        $path = fridg3_hard_ban_identity_path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return false;
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        return @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_hard_ban_filter_group_ips')) {
    function fridg3_hard_ban_filter_group_ips(array $hardBans, array $groupIps): array
    {
        return array_values(array_filter($hardBans, static function ($hardBan) use ($groupIps): bool {
            foreach ($groupIps as $groupIp) {
                if (fridg3_hard_ban_ips_equal((string)$hardBan, (string)$groupIp)) {
                    return false;
                }
            }
            return true;
        }));
    }
}

if (!function_exists('fridg3_hard_ban_admin_save')) {
    function fridg3_hard_ban_admin_save(array $requestedIps): bool
    {
        $data = fridg3_hard_ban_load_identities();
        $releasedPrimaries = [];
        foreach ($data['identities'] as $record) {
            $primaryIp = is_array($record) ? (string)($record['primaryIp'] ?? '') : '';
            if (
                $primaryIp !== ''
                && !fridg3_hard_ban_list_contains($requestedIps, $primaryIp)
                && !fridg3_hard_ban_source_contains($primaryIp)
            ) {
                $releasedPrimaries[$primaryIp] = true;
            }
        }

        foreach (array_keys($releasedPrimaries) as $primaryIp) {
            $groupIps = [$primaryIp];
            foreach ($data['identities'] as $identifier => $record) {
                if (!is_array($record) || !fridg3_hard_ban_ips_equal((string)($record['primaryIp'] ?? ''), $primaryIp)) {
                    continue;
                }
                $groupIps = array_merge($groupIps, (array)($record['ips'] ?? []));
                unset($data['identities'][$identifier]);
            }
            $requestedIps = fridg3_hard_ban_filter_group_ips($requestedIps, $groupIps);
        }

        if (!fridg3_hard_ban_write($requestedIps)) {
            return false;
        }
        return fridg3_hard_ban_write_identities($data);
    }
}

if (!function_exists('fridg3_hard_ban_register_identifier')) {
    function fridg3_hard_ban_register_identifier(string $ip, string $identifier): bool
    {
        if (!fridg3_hard_ban_valid_identifier($identifier) || !fridg3_hard_ban_contains($ip)) {
            return false;
        }

        $data = fridg3_hard_ban_load_identities();
        $existingRecord = is_array($data['identities'][$identifier] ?? null) ? $data['identities'][$identifier] : [];
        $primaryIp = (string)($existingRecord['primaryIp'] ?? $ip);
        foreach ($data['identities'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach ((array)($record['ips'] ?? []) as $knownIp) {
                if (fridg3_hard_ban_ips_equal((string)$knownIp, $ip)) {
                    $primaryIp = (string)($record['primaryIp'] ?? $ip);
                    break 2;
                }
            }
        }

        $record = $existingRecord;
        $knownIps = array_values(array_filter(array_map('strval', (array)($record['ips'] ?? [])), static fn(string $knownIp): bool => filter_var($knownIp, FILTER_VALIDATE_IP) !== false));
        if (!fridg3_hard_ban_list_contains($knownIps, $ip)) {
            $knownIps[] = $ip;
        }
        $now = gmdate(DATE_ATOM);
        $data['identities'][$identifier] = [
            'primaryIp' => $primaryIp,
            'ips' => $knownIps,
            'firstSeen' => (string)($record['firstSeen'] ?? $now),
            'lastSeen' => $now,
            'userAgentHash' => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];
        return fridg3_hard_ban_write_identities($data);
    }
}

if (!function_exists('fridg3_hard_ban_check_client')) {
    function fridg3_hard_ban_check_client(string $ip, string $identifier): bool
    {
        if (!fridg3_hard_ban_valid_identifier($identifier)) {
            return fridg3_hard_ban_contains($ip);
        }

        $manualHardBans = fridg3_hard_ban_load();
        $data = fridg3_hard_ban_load_identities();
        $record = $data['identities'][$identifier] ?? null;
        if (!is_array($record)) {
            return fridg3_hard_ban_contains($ip);
        }

        $primaryIp = (string)($record['primaryIp'] ?? '');
        if ($primaryIp === '' || !fridg3_hard_ban_contains($primaryIp)) {
            fridg3_hard_ban_admin_save($manualHardBans);
            return fridg3_hard_ban_contains($ip);
        }

        if (!fridg3_hard_ban_contains($ip)) {
            $manualHardBans[] = $ip;
            if (!fridg3_hard_ban_write($manualHardBans)) {
                return true;
            }
        }
        fridg3_hard_ban_register_identifier($ip, $identifier);
        return true;
    }
}
