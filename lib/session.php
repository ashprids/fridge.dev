<?php

if (!function_exists('fridg3_get_persistent_login_lifetime')) {
    function fridg3_get_persistent_login_lifetime(): int
    {
        return 60 * 60 * 24 * 90;
    }
}

if (!function_exists('fridg3_session_is_secure_request')) {
    function fridg3_session_is_secure_request(): bool
    {
        if (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) === 'on') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}

if (!function_exists('fridg3_session_cookie_domain')) {
    function fridg3_session_cookie_domain(): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim($host, '[]');

        if ($host === 'fridge.dev' || $host === 'www.fridge.dev' || $host === 'm.fridge.dev' || str_ends_with($host, '.fridge.dev')) {
            return '.fridge.dev';
        }

        return '';
    }
}

if (!function_exists('fridg3_session_cookie_options')) {
    function fridg3_session_cookie_options(?int $expires = null, bool $httpOnly = true): array
    {
        $options = [
            'path' => '/',
            'secure' => fridg3_session_is_secure_request(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];

        if ($expires !== null) {
            $options['expires'] = $expires;
        }

        $domain = fridg3_session_cookie_domain();
        if ($domain !== '') {
            $options['domain'] = $domain;
        }

        return $options;
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
        ini_set('session.cookie_lifetime', (string)$persistentLoginLifetime);
        ini_set('session.gc_maxlifetime', (string)$persistentLoginLifetime);

        session_set_cookie_params(array_merge(
            fridg3_session_cookie_options(null, true),
            ['lifetime' => $persistentLoginLifetime]
        ));

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
        setcookie(
            'is_admin',
            $isAdmin ? '1' : '0',
            fridg3_session_cookie_options(time() + fridg3_get_persistent_login_lifetime(), false)
        );
    }
}
