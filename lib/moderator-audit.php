<?php

if (!function_exists('fridg3_moderator_audit_path')) {
    function fridg3_moderator_audit_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'moderator-audit.ndjson';
    }
}

if (!function_exists('fridg3_moderator_audit_client_ip')) {
    function fridg3_moderator_audit_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $candidate = trim(explode(',', (string)($_SERVER[$key] ?? ''))[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
        }
        return 'unknown';
    }
}

if (!function_exists('fridg3_moderator_audit_log')) {
    function fridg3_moderator_audit_log(string $action, array $details = [], ?array $before = null, ?array $after = null): bool
    {
        if (empty($_SESSION['user']['isModerator']) || !empty($_SESSION['user']['isAdmin'])) return false;
        $record = [
            'id' => date('YmdHis') . '-' . bin2hex(random_bytes(6)),
            'username' => (string)($_SESSION['user']['username'] ?? 'unknown'),
            'ip' => fridg3_moderator_audit_client_ip(),
            'timestamp' => date(DATE_ATOM),
            'action' => trim($action),
            'details' => $details,
        ];
        if ($before !== null) $record['before'] = $before;
        if ($after !== null) $record['after'] = $after;
        $path = fridg3_moderator_audit_path();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) return false;
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $line !== false && @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_moderator_audit_load')) {
    function fridg3_moderator_audit_load(): array
    {
        $path = fridg3_moderator_audit_path();
        if (!is_file($path)) return [];
        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) $records[] = $record;
        }
        return array_reverse($records);
    }
}
