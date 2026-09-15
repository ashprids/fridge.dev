<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'debug.php';

if (!function_exists('fridge_guestbook_dir')) {
    function fridge_guestbook_dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook';
    }
}

if (!function_exists('fridge_guestbook_relative_time')) {
    function fridge_guestbook_relative_time(string $timestamp): string
    {
        $date = DateTime::createFromFormat('Y/m/d H:i:s', $timestamp);
        if (!$date) return '';
        $seconds = max(0, (new DateTime('now'))->getTimestamp() - $date->getTimestamp());
        if ($seconds < 60) return $seconds . 's ago';
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) return $minutes . 'm ago';
        $hours = intdiv($minutes, 60);
        if ($hours < 24) return $hours . 'h ago';
        $days = intdiv($hours, 24);
        if ($days < 7) return $days . 'd ago';
        $weeks = intdiv($days, 7);
        if ($weeks < 5) return $weeks . 'w ago';
        $months = intdiv($days, 30);
        if ($months < 12) return $months . 'mo ago';
        return intdiv($days, 365) . 'y ago';
    }
}

if (!function_exists('fridge_guestbook_ip_index_path')) {
    function fridge_guestbook_ip_index_path(): string
    {
        return fridge_guestbook_dir() . DIRECTORY_SEPARATOR . 'ip_index.json';
    }
}

if (!function_exists('fridge_guestbook_filtered_originals_path')) {
    function fridge_guestbook_filtered_originals_path(): string
    {
        return fridge_guestbook_dir() . DIRECTORY_SEPARATOR . 'filtered_originals.json';
    }

    function fridge_guestbook_load_filtered_originals(): array
    {
        $decoded = is_file(fridge_guestbook_filtered_originals_path())
            ? json_decode((string)@file_get_contents(fridge_guestbook_filtered_originals_path()), true)
            : [];
        return is_array($decoded) ? $decoded : [];
    }

    function fridge_guestbook_filtered_original(string $filename): string
    {
        return trim((string)(fridge_guestbook_load_filtered_originals()[basename($filename)] ?? ''));
    }

    function fridge_guestbook_store_filtered_original(string $filename, string $original): void
    {
        $records = fridge_guestbook_load_filtered_originals();
        $key = basename($filename);
        if (trim($original) === '') unset($records[$key]);
        else $records[$key] = $original;
        @file_put_contents(fridge_guestbook_filtered_originals_path(), json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if (!function_exists('fridge_guestbook_parse_entry')) {
    function fridge_guestbook_parse_entry(string $raw, string $filename = ''): ?array
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

if (!function_exists('fridge_guestbook_load_entry')) {
    function fridge_guestbook_load_entry(string $filename): ?array
    {
        $safeFilename = basename($filename);
        if ($safeFilename === '' || preg_match('/\.txt$/i', $safeFilename) !== 1) {
            return null;
        }

        $path = fridge_guestbook_dir() . DIRECTORY_SEPARATOR . $safeFilename;
        $postsReal = realpath(fridge_guestbook_dir());
        $pathReal = realpath($path);
        if ($postsReal === false || $pathReal === false || !str_starts_with($pathReal, $postsReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $raw = @file_get_contents($pathReal);
        if ($raw === false) {
            return null;
        }

        $entry = fridge_guestbook_parse_entry($raw, $safeFilename);
        if ($entry === null) {
            return null;
        }
        $entry['path'] = $pathReal;
        return $entry;
    }
}

if (!function_exists('fridge_guestbook_write_entry')) {
    function fridge_guestbook_write_entry(string $path, string $timestamp, string $name, string $message, string $ip = ''): bool
    {
        $lines = [$timestamp, $name];
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $lines[] = 'IP:' . $ip;
        }
        $lines[] = $message;

        return @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false;
    }
}

if (!function_exists('fridge_guestbook_remove_index_filename')) {
    function fridge_guestbook_remove_index_filename(string $filename): void
    {
        $path = fridge_guestbook_ip_index_path();
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

if (!function_exists('fridge_guestbook_delete_entry')) {
    function fridge_guestbook_delete_entry(string $filename, string $expectedIp = ''): bool
    {
        $entry = fridge_guestbook_load_entry($filename);
        if ($entry === null) {
            return false;
        }
        $entryIp = (string)$entry['ip'];
        if ($entryIp === '') {
            $index = is_file(fridge_guestbook_ip_index_path())
                ? json_decode((string)@file_get_contents(fridge_guestbook_ip_index_path()), true)
                : [];
            foreach (is_array($index) ? $index : [] as $indexedIp => $indexedFile) {
                if ((string)$indexedFile === (string)$entry['file'] && filter_var((string)$indexedIp, FILTER_VALIDATE_IP)) {
                    $entryIp = (string)$indexedIp;
                    break;
                }
            }
        }
        if ($expectedIp !== '' && !hash_equals($expectedIp, $entryIp)) {
            return false;
        }
        if (!@unlink((string)$entry['path'])) {
            return false;
        }
        fridge_feed_archive_ip_content($entryIp, 'guestbook', (string)$entry['file'], [
            'username' => (string)($entry['name'] ?? 'Anonymous'),
            'date' => (string)($entry['timestamp'] ?? ''),
            'body' => (string)($entry['message'] ?? ''),
            'file' => (string)$entry['file'],
        ]);
        fridge_guestbook_remove_index_filename((string)$entry['file']);
        fridge_guestbook_store_filtered_original((string)$entry['file'], '');
        return true;
    }
}

if (!function_exists('fridge_guestbook_collect_entries_by_ip')) {
    function fridge_guestbook_collect_entries_by_ip(): array
    {
        $entriesByIp = [];
        $index = is_file(fridge_guestbook_ip_index_path())
            ? json_decode((string)@file_get_contents(fridge_guestbook_ip_index_path()), true)
            : [];
        $ipByFilename = [];
        foreach (is_array($index) ? $index : [] as $ip => $filename) {
            if (filter_var((string)$ip, FILTER_VALIDATE_IP) !== false) {
                $ipByFilename[basename((string)$filename)] = (string)$ip;
            }
        }
        foreach (glob(fridge_guestbook_dir() . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $path) {
            $entry = fridge_guestbook_load_entry(basename($path));
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

if (!function_exists('fridge_guestbook_purge_entries_by_ip')) {
    function fridge_guestbook_purge_entries_by_ip(string $ip): array
    {
        $targetIp = trim($ip);
        $deleted = 0;
        $failed = 0;
        if (filter_var($targetIp, FILTER_VALIDATE_IP) === false) {
            return ['deleted' => 0, 'failed' => 0];
        }

        foreach (fridge_guestbook_collect_entries_by_ip()[$targetIp] ?? [] as $entry) {
            if (fridge_guestbook_delete_entry((string)$entry['file'], $targetIp)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
