<?php

if (!function_exists('fridg3_get_persistent_login_lifetime')) {
    function fridg3_get_persistent_login_lifetime(): int
    {
        return 60 * 60 * 24 * 90;
    }
}

if (!function_exists('fridg3_session_truthy_value')) {
    function fridg3_session_truthy_value($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled', 'wip'], true);
    }
}

if (!function_exists('fridg3_session_find_relative_upward')) {
    function fridg3_session_find_relative_upward(string $startDir, string $relativePath): ?string
    {
        $dir = $startDir;
        $prevDir = '';

        while ($dir !== $prevDir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (file_exists($candidate)) {
                return $candidate;
            }
            $prevDir = $dir;
            $dir = dirname($dir);
        }

        return null;
    }
}

if (!function_exists('fridg3_session_request_path')) {
    function fridg3_session_request_path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('fridg3_session_is_wip_allowed_path')) {
    function fridg3_session_is_wip_allowed_path(string $path): bool
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        return in_array($path, [
            '/error/wip',
            '/error/wip/index.html',
            '/error/wip/index.php',
            '/account/login',
            '/account/login/index.php',
        ], true);
    }
}

if (!function_exists('fridg3_session_work_in_progress_enabled')) {
    function fridg3_session_work_in_progress_enabled(): bool
    {
        $startDir = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
        $wipPath = fridg3_session_find_relative_upward((string)$startDir, 'data/etc/wip');
        if (!$wipPath || !is_file($wipPath)) {
            return false;
        }

        $raw = @file_get_contents($wipPath);
        if ($raw === false) {
            return false;
        }

        return fridg3_session_truthy_value($raw);
    }
}

if (!function_exists('fridg3_session_enforce_work_in_progress')) {
    function fridg3_session_enforce_work_in_progress(): void
    {
        if (PHP_SAPI === 'cli' || !fridg3_session_work_in_progress_enabled()) {
            return;
        }

        if (isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin'])) {
            return;
        }

        if (fridg3_session_is_wip_allowed_path(fridg3_session_request_path())) {
            return;
        }

        if (!headers_sent()) {
            header('Location: /error/wip', true, 302);
        }
        exit;
    }
}

if (!function_exists('fridg3_start_session')) {
    function fridg3_start_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $persistentLoginLifetime = fridg3_get_persistent_login_lifetime();

        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.cookie_lifetime', (string)$persistentLoginLifetime);
        ini_set('session.gc_maxlifetime', (string)$persistentLoginLifetime);

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }

        session_start();

        if (
            PHP_SAPI !== 'cli'
            && isset($_SESSION['user'])
            && !empty($_SESSION['user']['mustResetPassword'])
        ) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $normalizedPath = rtrim($requestPath, '/');
            if ($normalizedPath === '') {
                $normalizedPath = '/';
            }

            $allowedPaths = [
                '/account/change-password',
                '/account/change-password/index.php',
                '/account/password',
                '/account/password/index.php',
                '/account/logout',
                '/account/logout/index.php',
            ];

            if (!in_array($normalizedPath, $allowedPaths, true)) {
                header('Location: /account/change-password?first_login=1');
                exit;
            }
        }

        fridg3_session_enforce_work_in_progress();
    }
}

if (!function_exists('fridg3_refresh_is_admin_cookie')) {
    function fridg3_refresh_is_admin_cookie(bool $isAdmin): void
    {
        $secureFlag = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

        setcookie('is_admin', $isAdmin ? '1' : '0', [
            'expires' => time() + fridg3_get_persistent_login_lifetime(),
            'path' => '/',
            'secure' => $secureFlag,
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
    }
}
