<?php

if (!function_exists('fridg3_debug_log')) {
    function fridg3_debug_log($value): void
    {
        $GLOBALS['fridg3_debug_server_logs'] ??= [];
        $text = is_string($value) ? $value : print_r($value, true);
        $GLOBALS['fridg3_debug_server_logs'][] = substr($text, 0, 2000);
    }
}

if (!function_exists('fridg3_debug_submission_log')) {
    function fridg3_debug_submission_log(string $message): void
    {
        fridg3_debug_log($message);
        $GLOBALS['fridg3_debug_submission_events'] ??= [];
        $GLOBALS['fridg3_debug_submission_events'][] = $message;
    }
}

if (!function_exists('fridg3_debug_file_value_at_path')) {
    function fridg3_debug_file_value_at_path($value, array $path)
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) return null;
            $value = $value[$key];
        }
        return $value;
    }
}

if (!function_exists('fridg3_debug_capture_file_field')) {
    function fridg3_debug_capture_file_field(string $field, array $file, $errors, array $path = []): int
    {
        if (is_array($errors)) {
            $count = 0;
            foreach ($errors as $key => $child) {
                $count += fridg3_debug_capture_file_field($field, $file, $child, [...$path, $key]);
            }
            return $count;
        }
        $error = (int)$errors;
        if ($error === UPLOAD_ERR_NO_FILE) return 0;
        $mime = (string)(fridg3_debug_file_value_at_path($file['type'] ?? '', $path) ?? '');
        $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime) === 1 ? strtolower($mime) : 'unknown';
        $size = max(0, (int)(fridg3_debug_file_value_at_path($file['size'] ?? 0, $path) ?? 0));
        $slot = $field . ($path ? '[' . implode('][', array_map('strval', $path)) . ']' : '');
        fridg3_debug_submission_log('[ATTACHMENT] field=' . $slot . ' type=' . $mime . ' bytes=' . $size . ' upload_error=' . $error);
        return 1;
    }
}

if (!function_exists('fridg3_debug_capture_submission')) {
    function fridg3_debug_capture_submission(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) return;
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $bytes = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
        fridg3_debug_submission_log('[SUBMISSION] ' . $method . ' ' . $path . ' received bytes=' . $bytes);
        $attachments = 0;
        foreach ($_FILES as $field => $file) {
            if (!is_array($file)) continue;
            $attachments += fridg3_debug_capture_file_field((string)$field, $file, $file['error'] ?? UPLOAD_ERR_NO_FILE);
        }
        fridg3_debug_submission_log('[SUBMISSION] ' . $path . ' attachments=' . $attachments);
    }
}

if (!function_exists('fridg3_debug_complete_submission')) {
    function fridg3_debug_complete_submission(): void
    {
        if (!empty($GLOBALS['fridg3_debug_submission_completed'])) return;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) return;
        $GLOBALS['fridg3_debug_submission_completed'] = true;
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $username = isset($_SESSION['user']['username']) ? ' user=@' . substr((string)$_SESSION['user']['username'], 0, 100) : ' user=guest';
        $status = (int)http_response_code();
        if ($status < 100) $status = 200;
        fridg3_debug_submission_log('[SUBMISSION] ' . $path . ' completed HTTP ' . $status . $username);
    }
}

if (!function_exists('fridg3_debug_import_pending_submission_logs')) {
    function fridg3_debug_import_pending_submission_logs(): void
    {
        if (!isset($_SESSION['fridg3_debug_pending_submission_logs']) || !is_array($_SESSION['fridg3_debug_pending_submission_logs'])) return;
        foreach (array_slice($_SESSION['fridg3_debug_pending_submission_logs'], -100) as $message) {
            if (is_string($message)) fridg3_debug_log($message);
        }
        unset($_SESSION['fridg3_debug_pending_submission_logs']);
    }
}

if (!function_exists('fridg3_debug_capture_included_files')) {
    function fridg3_debug_capture_included_files(): void
    {
        $root = realpath(dirname(__DIR__));
        $seen = $GLOBALS['fridg3_debug_seen_php_files'] ?? [];
        foreach (get_included_files() as $file) {
            $resolved = realpath($file);
            if ($resolved === false || $root === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1));
            if (isset($seen[$relative])) continue;
            $seen[$relative] = true;
            fridg3_debug_log('[PHP] loaded ' . $relative);
        }
        $GLOBALS['fridg3_debug_seen_php_files'] = $seen;
    }
}

if (!function_exists('fridg3_debug_response_is_json')) {
    function fridg3_debug_response_is_json(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) return true;
        }
        return false;
    }
}

if (!function_exists('fridg3_debug_current_user_is_admin')) {
    function fridg3_debug_current_user_is_admin(): bool
    {
        return isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;
    }
}

if (!function_exists('fridg3_debug_header_logs')) {
    function fridg3_debug_header_logs(): array
    {
        $selected = [];
        $bytes = 2;
        $logs = array_reverse($GLOBALS['fridg3_debug_server_logs'] ?? []);
        foreach ($logs as $log) {
            $cost = strlen((string)$log) + 4;
            if (($bytes + $cost) > 4500) break;
            array_unshift($selected, $log);
            $bytes += $cost;
        }
        return $selected;
    }
}

if (!function_exists('fridg3_access_log_path')) {
    function fridg3_access_log_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'access.json';
    }
}

if (!function_exists('fridg3_access_normalize_path')) {
    function fridg3_access_normalize_path(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('fridg3_access_is_error_path')) {
    function fridg3_access_is_error_path(string $path): bool
    {
        return preg_match('#^/error(?:/|$)#i', fridg3_access_normalize_path($path)) === 1;
    }
}

if (!function_exists('fridg3_access_compact_entries')) {
    function fridg3_access_compact_entries(array $entries): array
    {
        $compacted = [];
        $lastPathByVisitor = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $entry['path'] = fridg3_access_normalize_path((string)($entry['path'] ?? '/'));
            if (fridg3_access_is_error_path($entry['path'])) continue;
            if (preg_match('#^/chat(?:/|$)#i', $entry['path']) === 1) continue;
            $visitor = (string)($entry['ip'] ?? 'unknown') . "\0" . strtolower((string)($entry['username'] ?? ''));
            if (($lastPathByVisitor[$visitor] ?? null) === $entry['path']) continue;
            $lastPathByVisitor[$visitor] = $entry['path'];
            $compacted[] = $entry;
        }
        return $compacted;
    }
}

if (!function_exists('fridg3_read_access_logs')) {
    function fridg3_read_access_logs(): array
    {
        $path = fridg3_access_log_path();
        if (!is_file($path) || !is_readable($path)) return [];
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? array_slice(fridg3_access_compact_entries($decoded), -10000) : [];
    }
}

if (!function_exists('fridg3_access_is_page_navigation')) {
    function fridg3_access_is_page_navigation(): bool
    {
        $fetchDestination = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
        if ($fetchDestination === 'document') return true;
        if ((string)($_SERVER['HTTP_X_FRIDG3_PAGE_NAVIGATION'] ?? '') === '1') return true;

        // Older browsers may not send Fetch Metadata. In that case, accept only a
        // non-XHR request which explicitly accepts HTML as a document response.
        $acceptsHtml = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false;
        $isXmlHttpRequest = strcasecmp(
            (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''),
            'XMLHttpRequest'
        ) === 0;
        return $fetchDestination === '' && $acceptsHtml && !$isXmlHttpRequest;
    }
}

if (!function_exists('fridg3_write_access_log')) {
    function fridg3_write_access_log(): void
    {
        if (PHP_SAPI === 'cli' || defined('FRIDG3_SKIP_ACCESS_LOG')) return;
        if (!fridg3_access_is_page_navigation()) return;
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (strtolower(basename($script)) !== 'index.php') return;
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = fridg3_access_normalize_path(is_string($path) && $path !== '' ? substr($path, 0, 1000) : '/');
        if (preg_match('#^/api(?:/|$)#i', $path) === 1) return;
        if (preg_match('#^/chat(?:/|$)#i', $path) === 1) return;
        if (fridg3_access_is_error_path($path)) return;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = 'unknown';
        $username = isset($_SESSION['user']['username']) ? substr((string)$_SESSION['user']['username'], 0, 100) : '';
        $role = $username === '' ? 'guest' : (!empty($_SESSION['user']['isAdmin']) ? 'admin' : 'user');
        $status = (int)http_response_code();
        if ($status < 100) $status = 200;
        $record = json_encode([
            'timestamp' => gmdate('c'),
            'ip' => $ip,
            'path' => $path,
            'status' => $status,
            'username' => $username,
            'role' => $role,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($record === false) return;

        $logPath = fridg3_access_log_path();
        $directory = dirname($logPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        $lock = @fopen($logPath . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return;
        }
        $entries = fridg3_read_access_logs();
        $decodedRecord = json_decode($record, true);
        if (is_array($decodedRecord)) $entries[] = $decodedRecord;
        $entries = array_slice(fridg3_access_compact_entries($entries), -10000);
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $temporary = $logPath . '.tmp.' . getmypid();
            if (@file_put_contents($temporary, $json . "\n") !== false) {
                @chmod($temporary, 0600);
                @rename($temporary, $logPath);
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (empty($GLOBALS['fridg3_debug_runtime_started'])) {
    $GLOBALS['fridg3_debug_runtime_started'] = true;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? PHP_SAPI));
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '[command line]';
    fridg3_debug_log('[PHP] ' . $method . ' ' . $path . ' request initialized');
    fridg3_debug_capture_submission();

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        $labels = [
            E_WARNING => 'warning', E_USER_WARNING => 'warning',
            E_NOTICE => 'notice', E_USER_NOTICE => 'notice',
            E_DEPRECATED => 'warning', E_USER_DEPRECATED => 'warning',
            E_RECOVERABLE_ERROR => 'error', E_USER_ERROR => 'error',
        ];
        $label = $labels[$severity] ?? 'error';
        fridg3_debug_log('[PHP] ' . $label . ': ' . substr($message, 0, 500) . ' in ' . basename($file) . ':' . $line);
        return false;
    });

    register_shutdown_function(static function (): void {
        $lastError = error_get_last();
        if (is_array($lastError) && in_array((int)$lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            fridg3_debug_log('[PHP] fatal error: ' . substr((string)$lastError['message'], 0, 500) . ' in ' . basename((string)$lastError['file']) . ':' . (int)$lastError['line']);
        }
        fridg3_debug_complete_submission();
        $status = http_response_code();
        if ($status >= 300 && $status < 400 && fridg3_debug_current_user_is_admin() && session_status() === PHP_SESSION_ACTIVE && !empty($GLOBALS['fridg3_debug_submission_events'])) {
            $_SESSION['fridg3_debug_pending_submission_logs'] = array_slice($GLOBALS['fridg3_debug_submission_events'], -100);
        }
        fridg3_debug_capture_included_files();
        fridg3_debug_log('[PHP] request completed with HTTP ' . http_response_code());
        fridg3_write_access_log();

        if (
            !headers_sent()
            && fridg3_debug_response_is_json()
            && fridg3_debug_current_user_is_admin()
            && (string)($_SERVER['HTTP_X_FRIDG3_DEBUG'] ?? '') === '1'
        ) {
            $json = json_encode(fridg3_debug_header_logs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) header('X-Fridg3-Debug-Logs: ' . base64_encode($json));
        }
    });
}
