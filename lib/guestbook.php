<?php

if (!function_exists('fridg3_guestbook_dir')) {
    function fridg3_guestbook_dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook';
    }
}

if (!function_exists('fridg3_guestbook_ip_index_path')) {
    function fridg3_guestbook_ip_index_path(): string
    {
        return fridg3_guestbook_dir() . DIRECTORY_SEPARATOR . 'ip_index.json';
    }
}

if (!function_exists('fridg3_guestbook_parse_entry')) {
    function fridg3_guestbook_parse_entry(string $raw, string $filename = ''): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        if (!is_array($lines) || count($lines) < 2) {
            return null;
        }

        $bodyOffset = 2;
        $ip = '';
        if (isset($lines[2]) && str_starts_with(trim((string)$lines[2]), 'IP:')) {
            $candidateIp = trim(substr(trim((string)$lines[2]), 3));
            if ($candidateIp === '' || filter_var($candidateIp, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidateIp;
                $bodyOffset = 3;
            }
        }

        return [
            'file' => basename($filename),
            'timestamp' => trim((string)($lines[0] ?? '')),
            'name' => trim((string)($lines[1] ?? '')),
            'ip' => $ip,
            'message' => trim(implode("\n", array_slice($lines, $bodyOffset))),
        ];
    }
}

if (!function_exists('fridg3_guestbook_load_entry')) {
    function fridg3_guestbook_load_entry(string $filename): ?array
    {
        $safeFilename = basename($filename);
        if ($safeFilename === '' || preg_match('/\.txt$/i', $safeFilename) !== 1) {
            return null;
        }

        $path = fridg3_guestbook_dir() . DIRECTORY_SEPARATOR . $safeFilename;
        $postsReal = realpath(fridg3_guestbook_dir());
        $pathReal = realpath($path);
        if ($postsReal === false || $pathReal === false || !str_starts_with($pathReal, $postsReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $raw = @file_get_contents($pathReal);
        if ($raw === false) {
            return null;
        }

        $entry = fridg3_guestbook_parse_entry($raw, $safeFilename);
        if ($entry === null) {
            return null;
        }
        $entry['path'] = $pathReal;
        return $entry;
    }
}

if (!function_exists('fridg3_guestbook_write_entry')) {
    function fridg3_guestbook_write_entry(string $path, string $timestamp, string $name, string $message, string $ip = ''): bool
    {
        $lines = [$timestamp, $name];
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $lines[] = 'IP:' . $ip;
        }
        $lines[] = $message;

        return @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_guestbook_remove_index_filename')) {
    function fridg3_guestbook_remove_index_filename(string $filename): void
    {
        $path = fridg3_guestbook_ip_index_path();
        $index = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($index)) {
            $index = [];
        }
        foreach ($index as $ip => $mappedFilename) {
            if ((string)$mappedFilename === $filename) {
                unset($index[$ip]);
            }
        }
        @file_put_contents($path, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (!function_exists('fridg3_guestbook_delete_entry')) {
    function fridg3_guestbook_delete_entry(string $filename, string $expectedIp = ''): bool
    {
        $entry = fridg3_guestbook_load_entry($filename);
        if ($entry === null) {
            return false;
        }
        $entryIp = (string)$entry['ip'];
        if ($entryIp === '' && $expectedIp !== '') {
            $index = is_file(fridg3_guestbook_ip_index_path())
                ? json_decode((string)@file_get_contents(fridg3_guestbook_ip_index_path()), true)
                : [];
            if (is_array($index) && (string)($index[$expectedIp] ?? '') === (string)$entry['file']) {
                $entryIp = $expectedIp;
            }
        }
        if ($expectedIp !== '' && !hash_equals($expectedIp, $entryIp)) {
            return false;
        }
        if (!@unlink((string)$entry['path'])) {
            return false;
        }
        fridg3_guestbook_remove_index_filename((string)$entry['file']);
        return true;
    }
}

if (!function_exists('fridg3_guestbook_collect_entries_by_ip')) {
    function fridg3_guestbook_collect_entries_by_ip(): array
    {
        $entriesByIp = [];
        $index = is_file(fridg3_guestbook_ip_index_path())
            ? json_decode((string)@file_get_contents(fridg3_guestbook_ip_index_path()), true)
            : [];
        $ipByFilename = [];
        foreach (is_array($index) ? $index : [] as $ip => $filename) {
            if (filter_var((string)$ip, FILTER_VALIDATE_IP) !== false) {
                $ipByFilename[basename((string)$filename)] = (string)$ip;
            }
        }
        foreach (glob(fridg3_guestbook_dir() . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $path) {
            $entry = fridg3_guestbook_load_entry(basename($path));
            $ip = trim((string)($entry['ip'] ?? ''));
            if ($ip === '' && $entry !== null) {
                $ip = $ipByFilename[(string)$entry['file']] ?? '';
                $entry['ip'] = $ip;
            }
            if ($entry === null || $ip === '') {
                continue;
            }
            $entriesByIp[$ip][] = $entry;
        }
        foreach ($entriesByIp as &$entries) {
            usort($entries, static fn (array $a, array $b): int => strcmp((string)$b['timestamp'], (string)$a['timestamp']));
        }
        unset($entries);
        ksort($entriesByIp, SORT_NATURAL);
        return $entriesByIp;
    }
}

if (!function_exists('fridg3_guestbook_purge_entries_by_ip')) {
    function fridg3_guestbook_purge_entries_by_ip(string $ip): array
    {
        $targetIp = trim($ip);
        $deleted = 0;
        $failed = 0;
        if (filter_var($targetIp, FILTER_VALIDATE_IP) === false) {
            return ['deleted' => 0, 'failed' => 0];
        }

        foreach (fridg3_guestbook_collect_entries_by_ip()[$targetIp] ?? [] as $entry) {
            if (fridg3_guestbook_delete_entry((string)$entry['file'], $targetIp)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
