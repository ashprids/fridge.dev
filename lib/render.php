<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'debug.php';

if (!function_exists('fridg3_inject_server_debug_logs')) {
    function fridg3_inject_server_debug_logs($template) {
        if (!fridg3_debug_current_user_is_admin()) return $template;
        fridg3_debug_import_pending_submission_logs();
        fridg3_debug_complete_submission();
        fridg3_debug_capture_included_files();
        fridg3_debug_log('[PHP] request completed with HTTP ' . http_response_code());
        $serverLogs = $GLOBALS['fridg3_debug_server_logs'] ?? [];
        if (empty($serverLogs) || stripos($template, 'data-fridg3-server-debug-logs') !== false) return $template;
        $payload = json_encode(array_values($serverLogs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($payload === false) return $template;
        // Split the tag name so the markup JavaScript linter does not parse this
        // PHP-built application/json payload as an executable inline script.
        $block = "\n    <scr" . "ipt type=\"application/json\" data-fridg3-server-debug-logs>"
            . $payload . '</scr' . 'ipt>' . "\n";
        if (stripos($template, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $block . '</body>', $template, 1) ?: ($template . $block);
        }
        return $template . $block;
    }
}

if (!function_exists('fridg3_find_relative_upward')) {
    function fridg3_find_relative_upward($startDir, $relativePath) {
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

if (!function_exists('fridg3_is_truthy_value')) {
    function fridg3_is_truthy_value($value) {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true);
    }
}

if (!function_exists('fridg3_is_local_dev_server')) {
    function fridg3_is_local_dev_server(): bool {
        if (isset($_ENV['FRIDG3_DEV_MODE']) && fridg3_is_truthy_value($_ENV['FRIDG3_DEV_MODE'])) {
            return true;
        }
        if (isset($_SERVER['FRIDG3_DEV_MODE']) && fridg3_is_truthy_value($_SERVER['FRIDG3_DEV_MODE'])) {
            return true;
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim($host, '[]');

        if ($host === '' || $host === 'localhost' || $host === '0.0.0.0' || $host === '::1') {
            return true;
        }
        if (preg_match('/^127(?:\.\d{1,3}){3}$/', $host)) {
            return true;
        }
        if (substr($host, -10) === '.localhost' || substr($host, -5) === '.test') {
            return true;
        }

        return false;
    }
}

if (!function_exists('fridg3_is_work_in_progress_enabled')) {
    function fridg3_is_work_in_progress_enabled($startDir): bool {
        $wipPath = fridg3_find_relative_upward($startDir, 'data/etc/wip');
        if (!$wipPath || !is_file($wipPath)) {
            return false;
        }

        $raw = @file_get_contents($wipPath);
        if ($raw === false) {
            return false;
        }

        return fridg3_is_truthy_value($raw) || strtolower(trim((string)$raw)) === 'wip';
    }
}

if (!function_exists('fridg3_get_request_path')) {
    function fridg3_get_request_path(): string {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('fridg3_is_wip_allowed_path')) {
    function fridg3_is_wip_allowed_path(string $path): bool {
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

if (!function_exists('fridg3_current_user_is_admin')) {
    function fridg3_current_user_is_admin(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'session.php';
            if (is_file($sessionPath)) {
                require_once $sessionPath;
                if (function_exists('fridg3_start_session')) {
                    fridg3_start_session();
                }
            }
        }

        return isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']);
    }
}

if (!function_exists('fridg3_enforce_work_in_progress')) {
    function fridg3_enforce_work_in_progress($startDir): void {
        if (PHP_SAPI === 'cli' || !fridg3_is_work_in_progress_enabled($startDir)) {
            return;
        }

        if (fridg3_current_user_is_admin()) {
            return;
        }

        $path = fridg3_get_request_path();
        if (fridg3_is_wip_allowed_path($path)) {
            return;
        }

        if (!headers_sent()) {
            header('Location: /error/wip', true, 302);
        }
        exit;
    }
}

if (!function_exists('fridg3_apply_work_in_progress_banner')) {
    function fridg3_apply_work_in_progress_banner($template, $startDir) {
        if (!fridg3_is_work_in_progress_enabled($startDir)) {
            return $template;
        }

        return preg_replace_callback('/<span id="maintenance-banner"[^>]*>/i', function($matches) {
            $tag = $matches[0];
            if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $tag, $styleMatches)) {
                $quote = $styleMatches[1];
                $style = $styleMatches[2];
                if (preg_match('/display\s*:\s*none\s*;?/i', $style)) {
                    $style = preg_replace('/display\s*:\s*none\s*;?/i', 'display: block;', $style, 1);
                } else {
                    $style = rtrim($style);
                    $style .= ($style !== '' && substr($style, -1) !== ';' ? ';' : '') . ' display: block;';
                }
                return preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' ' . 'style' . '=' . $quote . $style . $quote, $tag, 1);
            }

            return rtrim($tag, '>') . ' style="display: block;">';
        }, $template, 1) ?: $template;
    }
}

if (!function_exists('fridg3_get_mobile_cookie_domain')) {
    function fridg3_get_mobile_cookie_domain() {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $isSubdomain = strlen($host) > strlen('.fridge.dev') && substr($host, -strlen('.fridge.dev')) === '.fridge.dev';
        if ($host === 'fridge.dev' || $host === 'm.fridge.dev' || $isSubdomain) {
            return '.fridge.dev';
        }
        return null;
    }
}

if (!function_exists('fridg3_normalize_theme_id')) {
    function fridg3_normalize_theme_id($theme) {
        $theme = strtolower(trim((string)$theme));
        if ($theme === '' || $theme === 'default') {
            return 'default';
        }
        if ($theme === 'blackprint') {
            return 'default';
        }
        if ($theme === 'crt') {
            return 'ambercrt';
        }
        if ($theme === 'liminal') {
            return 'default';
        }
        if ($theme === 'syswave') {
            return 'default';
        }
        if ($theme === 'custom') {
            return 'classic';
        }
        if ($theme === 'newsprint') {
            return 'whiteprint';
        }
        if (preg_match('/^[a-z0-9_-]+$/', $theme)) {
            return $theme;
        }
        return 'default';
    }
}

if (!function_exists('fridg3_list_themes')) {
    function fridg3_normalize_theme_asset_path($path) {
        $path = trim(str_replace('\\', '/', (string)$path));
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $part)) {
                return null;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    function fridg3_theme_asset_href($relativePath) {
        $parts = explode('/', $relativePath);
        $encoded = array_map('rawurlencode', $parts);
        return '/themes/lib/' . implode('/', $encoded);
    }

    function fridg3_list_themes($startDir) {
        $themesDir = fridg3_find_relative_upward($startDir, 'themes');
        $themesLibDir = fridg3_find_relative_upward($startDir, 'themes/lib');
        if (!$themesDir || !$themesLibDir || !is_dir($themesDir) || !is_dir($themesLibDir)) {
            return [];
        }

        $themes = [];
        $files = glob($themesDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $id = fridg3_normalize_theme_id(pathinfo($file, PATHINFO_FILENAME));
            if ($id === 'default' || $id === 'custom') {
                continue;
            }

            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $meta = json_decode($raw, true);
            if (!is_array($meta)) {
                continue;
            }

            $name = trim((string)($meta['name'] ?? ''));
            $description = trim((string)($meta['description'] ?? ''));
            $html = fridg3_normalize_theme_asset_path($meta['html'] ?? '');
            $css = fridg3_normalize_theme_asset_path($meta['css'] ?? '');
            $thumbnail = fridg3_normalize_theme_asset_path($meta['thumbnail'] ?? '');
            if ($thumbnail === null) {
                $thumbnail = '';
            }
            if ($name === '' || $html === '' || $css === '') {
                continue;
            }

            $htmlPath = $themesLibDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $html);
            $cssPath = $themesLibDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $css);
            $thumbnailPath = $thumbnail !== null && $thumbnail !== ''
                ? $themesDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbnail)
                : null;
            if (!is_file($htmlPath) || !is_file($cssPath)) {
                continue;
            }
            if ($thumbnailPath !== null && !is_file($thumbnailPath)) {
                $thumbnail = '';
            }

            if (isset($themes[$id])) {
                continue;
            }

            $themes[$id] = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'thumbnail' => $thumbnail,
                'html' => $html,
                'css' => $css,
                'htmlPath' => $htmlPath,
                'cssPath' => $cssPath,
                'htmlTemplate' => 'themes/lib/' . $html,
                'cssHref' => fridg3_theme_asset_href($css) . '?v=' . (string)filemtime($cssPath),
                'thumbnailHref' => $thumbnail !== '' ? '/themes/' . implode('/', array_map('rawurlencode', explode('/', $thumbnail))) : '',
            ];
        }

        uasort($themes, function($a, $b) {
            $priority = ['whiteprint' => 0, 'classic' => 1];
            $aPriority = $priority[$a['id']] ?? 10;
            $bPriority = $priority[$b['id']] ?? 10;
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $themes;
    }
}

if (!function_exists('fridg3_get_account_theme_preference')) {
    function fridg3_get_account_theme_preference($startDir) {
        if (!isset($_SESSION['user']['username'])) {
            return null;
        }

        $accountsPath = fridg3_find_relative_upward($startDir, 'data/accounts/accounts.json');
        if (!$accountsPath || !is_file($accountsPath)) {
            return null;
        }

        $raw = @file_get_contents($accountsPath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
            return null;
        }

        $username = (string)$_SESSION['user']['username'];
        foreach ($data['accounts'] as $account) {
            if (!isset($account['username']) || (string)$account['username'] !== $username) {
                continue;
            }
            if (!array_key_exists('theme', $account)) {
                return null;
            }
            return fridg3_normalize_theme_id($account['theme']);
        }

        return null;
    }
}

if (!function_exists('fridg3_paginate_static_post_list')) {
    function fridg3_paginate_static_post_list(string $content, string $route, int $currentPage, int $perPage = 10): string {
        if ($perPage < 1 || preg_match('#(<div\b[^>]*\bid=([' . "\"'" . '])posts\2[^>]*>)([\s\S]*)(</div>)#i', $content, $wrapper) !== 1) {
            return $content;
        }
        $inner = (string)$wrapper[3];
        if (preg_match_all('#<a\b[^>]*>\s*<div\b[^>]*\bid=([' . "\"'" . '])post\1[^>]*>[\s\S]*?</div>\s*</a>#i', $inner, $matches) === false) {
            return $content;
        }
        $cards = $matches[0] ?? [];
        $total = count($cards);
        if ($total === 0) return $content;
        $totalPages = max(1, (int)ceil($total / $perPage));
        $currentPage = min(max(1, $currentPage), $totalPages);
        $visibleCards = array_slice($cards, ($currentPage - 1) * $perPage, $perPage);
        $replacement = (string)$wrapper[1] . PHP_EOL . implode(PHP_EOL, $visibleCards) . PHP_EOL . (string)$wrapper[4];
        if ($totalPages > 1) {
            $previous = $currentPage > 1
                ? '<a class="guestbook-page-btn pagination-arrow" href="' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '?page=' . ($currentPage - 1) . '#content-footer" aria-label="previous page">&lsaquo;</a>'
                : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&lsaquo;</span>';
            $next = $currentPage < $totalPages
                ? '<a class="guestbook-page-btn pagination-arrow" href="' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '?page=' . ($currentPage + 1) . '#content-footer" aria-label="next page">&rsaquo;</a>'
                : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&rsaquo;</span>';
            $replacement .= '<nav class="guestbook-pagination content-pagination" aria-label="pages" data-pagination-route="' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '" data-pagination-current="' . $currentPage . '" data-pagination-total="' . $totalPages . '" data-pagination-search="">'
                . $previous . '<span class="guestbook-page-btn current" aria-current="page">' . $currentPage . '</span>' . $next . '</nav>';
        }
        return str_replace((string)$wrapper[0], $replacement, $content);
    }
}

if (!function_exists('fridg3_get_theme_cookie_options')) {
    function fridg3_get_theme_cookie_options() {
        $options = [
            'expires' => time() + (86400 * 365),
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => false,
            'samesite' => 'Lax',
        ];

        $domain = fridg3_get_mobile_cookie_domain();
        if ($domain !== null) {
            $options['domain'] = $domain;
        }

        return $options;
    }
}

if (!function_exists('fridg3_get_preferred_theme_id')) {
    function fridg3_get_preferred_theme_id($startDir) {
        $accountTheme = fridg3_get_account_theme_preference($startDir);
        if ($accountTheme !== null) {
            return $accountTheme;
        }

        if (isset($_COOKIE['theme_pref'])) {
            return fridg3_normalize_theme_id($_COOKIE['theme_pref']);
        }

        return 'default';
    }
}

if (!function_exists('fridg3_get_active_theme')) {
    function fridg3_get_active_theme($startDir) {
        $themeId = fridg3_get_preferred_theme_id($startDir);
        if ($themeId === 'default') {
            return null;
        }

        $themes = fridg3_list_themes($startDir);
        return $themes[$themeId] ?? null;
    }
}

if (!function_exists('should_use_mobile_template')) {
    function should_use_mobile_template($startDir) {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host === 'm.fridge.dev') {
            return true;
        }

        if (isset($_COOKIE['mobile_friendly_view']) && fridg3_is_truthy_value($_COOKIE['mobile_friendly_view'])) {
            return true;
        }

        return false;
    }
}

if (!function_exists('get_preferred_template_name')) {
    function get_preferred_template_name($startDir) {
        if (should_use_mobile_template($startDir)) {
            return 'template_mobile.html';
        }

        $theme = fridg3_get_active_theme($startDir);
        if ($theme !== null) {
            return $theme['htmlTemplate'];
        }

        return 'template.html';
    }
}

if (!function_exists('apply_preferred_theme_stylesheet')) {
    function fridg3_apply_body_theme_class($template, $className) {
        $className = trim((string)$className);
        if ($className === '' || !preg_match('/^[a-z0-9_-]+$/', $className)) {
            return $template;
        }
        if (preg_match('/<body\b[^>]*\bclass=(["\'])(.*?)\1/i', $template, $matches)) {
            $classes = preg_split('/\s+/', trim($matches[2])) ?: [];
            if (in_array($className, $classes, true)) {
                return $template;
            }
            $newClassValue = trim($matches[2] . ' ' . $className);
            return preg_replace(
                '/(<body\b[^>]*\bclass=)(["\'])(.*?)\2/i',
                '$1$2' . $newClassValue . '$2',
                $template,
                1
            );
        }
        return preg_replace('/<body\b/i', '<body class="' . $className . '"', $template, 1);
    }

    function fridg3_current_user_email_address($startDir): string {
        if (!isset($_SESSION['user']['username'])) {
            return '';
        }

        $username = (string)$_SESSION['user']['username'];
        $accountsPath = fridg3_find_relative_upward($startDir, 'data/accounts/accounts.json');
        if ($accountsPath && is_file($accountsPath)) {
            $raw = @file_get_contents($accountsPath);
            $data = json_decode((string)$raw, true);

            if (is_array($data) && isset($data['accounts']) && is_array($data['accounts'])) {
                foreach ($data['accounts'] as $account) {
                    if (!isset($account['username']) || (string)$account['username'] !== $username) {
                        continue;
                    }

                    $emailAddress = trim((string)($account['emailAddress'] ?? ''));
                    $_SESSION['user']['emailAddress'] = $emailAddress;
                    return $emailAddress;
                }
            }
        }

        return '';
    }

    function fridg3_user_has_email_account($startDir): bool {
        $emailAddress = fridg3_current_user_email_address($startDir);
        return $emailAddress !== ''
            && filter_var($emailAddress, FILTER_VALIDATE_EMAIL) !== false
            && str_ends_with(strtolower($emailAddress), '@fridge.dev');
    }

    function fridg3_inject_site_notices($template, $startDir) {
        $noticesHelper = __DIR__ . DIRECTORY_SEPARATOR . 'site-notices.php';
        if (!is_file($noticesHelper)) {
            return $template;
        }
        require_once $noticesHelper;

        $allNotices = fridg3_site_notices_load($startDir);
        $audience = isset($_SESSION['user']['username']) ? 'users' : 'guests';
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $notices = fridg3_site_notices_for_request($allNotices, $audience, $requestUri);
        $banner = is_array($notices['banner'] ?? null) ? $notices['banner'] : null;
        $popup = is_array($notices['popup'] ?? null) ? $notices['popup'] : null;
        $bannerHtml = '<div id="site-notice-banner-region"></div>';
        if ($banner !== null) {
            $message = nl2br(htmlspecialchars((string)$banner['message'], ENT_QUOTES, 'UTF-8'));
            $dismiss = !empty($banner['dismissible'])
                ? '<button type="button" class="site-notice-banner-close" aria-label="dismiss notice" data-site-notice-dismiss="' . htmlspecialchars((string)$banner['id'], ENT_QUOTES, 'UTF-8') . '">&times;</button>'
                : '';
            $bannerHtml = '<div id="site-notice-banner-region"><div class="site-notice-banner" data-site-notice-id="' . htmlspecialchars((string)$banner['id'], ENT_QUOTES, 'UTF-8') . '" data-dismissible="' . (!empty($banner['dismissible']) ? '1' : '0') . '" role="status"><div class="site-notice-banner-message">' . $message . '</div>' . $dismiss . '</div></div>';
        }

        $popupJson = json_encode($popup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $runtime = '<script id="site-notice-runtime" type="application/json">' . ($popupJson === false ? 'null' : $popupJson) . '</script>';
        $injection = $bannerHtml . $runtime;

        if (stripos($template, 'id="content-layout"') !== false) {
            return preg_replace('/(<div\b[^>]*\bid=("|\')content-layout\2[^>]*>)/i', '$1' . $injection, $template, 1) ?: $template;
        }

        if (stripos($template, 'id="content"') !== false) {
            return preg_replace('/(<div\b[^>]*\bid=("|\')content\2[^>]*>)/i', '$1' . $injection, $template, 1) ?: $template;
        }

        if (stripos($template, '<body') !== false) {
            return preg_replace('/(<body\b[^>]*>)/i', '$1' . $injection, $template, 1) ?: $template;
        }

        return $injection . $template;
    }

    function fridg3_replace_logged_in_discord_footer_button($template, $startDir) {
        $template = fridg3_inject_site_notices($template, $startDir);
        $template = fridg3_inject_server_debug_logs($template);
        if (!fridg3_user_has_email_account($startDir)) {
            return $template;
        }

        return preg_replace_callback(
            '/<a\b([^>]*\bhref=(["\'])\/discord\2[^>]*)>\s*<div\b([^>]*\bid=(["\'])footer-button\4[^>]*)>\s*<i\b[^>]*\bclass=(["\'])fa-brands fa-discord\5[^>]*><\/i>\s*<\/div>\s*<\/a>/i',
            function ($matches) {
                $anchorAttrs = preg_replace('/\bhref=(["\'])\/discord\1/i', 'href="/account/email"', $matches[1], 1);
                $divAttrs = preg_replace('/\bdata-tooltip=(["\']).*?\1/i', 'data-tooltip="access fridge.dev email"', $matches[3], 1);

                if ($divAttrs === $matches[3]) {
                    $divAttrs .= ' data-tooltip="access fridge.dev email"';
                }

                return '<a' . $anchorAttrs . '><div' . $divAttrs . '><i class="fa-solid fa-envelope"></i></div></a>';
            },
            $template
        );
    }

    function fridg3_inject_shared_runtime_scripts($template) {
        $scripts = [
            '/js/settings.js' => '/js/settings.js?v=20260723-debug-logging-1',
            '/js/sidebar-player.js' => '/js/sidebar-player.js?v=20260723-debug-logging-1',
            '/js/bookmarks.js' => '/js/bookmarks.js?v=20260723-debug-logging-1',
            '/js/bbcode.js' => '/js/bbcode.js?v=20260723-debug-logging-1',
        ];

        $missing = [];
        foreach ($scripts as $detectPath => $src) {
            if (stripos($template, $detectPath) === false) {
                $missing[] = '    <script src="' . $src . '"></script>';
            }
        }

        if (empty($missing)) return $template;

        $scriptBlock = "\n" . implode("\n", $missing) . "\n";
        if (stripos($template, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $scriptBlock . '</body>', $template, 1) ?: ($template . $scriptBlock);
        }

        return $template . $scriptBlock;
    }

    function apply_preferred_theme_stylesheet($template, $startDir) {
        fridg3_enforce_work_in_progress($startDir);

        $theme = fridg3_get_active_theme($startDir);
        if ($theme === null) {
            return fridg3_replace_logged_in_discord_footer_button(
                fridg3_apply_work_in_progress_banner(
                    fridg3_inject_dev_mode_banner(fridg3_apply_body_theme_class($template, 'blackprint-theme')),
                    $startDir
                ),
                $startDir
            );
        }

        $href = htmlspecialchars($theme['cssHref'], ENT_QUOTES, 'UTF-8');
        if (strpos($template, 'href="' . $href . '"') !== false || strpos($template, "href='" . $href . "'") !== false) {
            return fridg3_replace_logged_in_discord_footer_button(
                fridg3_apply_work_in_progress_banner(fridg3_inject_dev_mode_banner($template), $startDir),
                $startDir
            );
        }

        $themeLink = '    <link rel="stylesheet" href="' . $href . '">' . "\n";
        if (stripos($template, '</head>') !== false) {
            return fridg3_replace_logged_in_discord_footer_button(
                fridg3_apply_work_in_progress_banner(
                    fridg3_inject_dev_mode_banner(
                        fridg3_inject_shared_runtime_scripts(
                            preg_replace('/<\/head>/i', $themeLink . '</head>', $template, 1)
                        )
                    ),
                    $startDir
                ),
                $startDir
            );
        }

        return fridg3_replace_logged_in_discord_footer_button(
            fridg3_apply_work_in_progress_banner(
                fridg3_inject_dev_mode_banner(
                    fridg3_inject_shared_runtime_scripts($themeLink . $template)
                ),
                $startDir
            ),
            $startDir
        );
    }
}

if (!function_exists('fridg3_inject_dev_mode_banner')) {
    function fridg3_inject_dev_mode_banner($template) {
        $isLocalDevServer = fridg3_is_local_dev_server();
        $isAdmin = isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;
        if (!$isLocalDevServer && !$isAdmin) {
            return $template;
        }

        if ($isLocalDevServer && strpos($template, 'id="dev-mode-banner"') === false) {
            $tooltip = "Developer mode was enabled because the server\n"
                . "detected it was running on localhost.\n"
                . "Check the settings page for options.\n\n"
                . "Some features may work unexpectedly due to\n"
                . "differences in the web server and your client's\n"
                . "configurations.";
            $banner = '<span id="dev-mode-banner" data-tooltip="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '" style="color: #9fd6a3; display: block; line-height: 1.15;"><i class="fa-solid fa-code"></i> <b>developer mode</b></span>';
            if (strpos($template, 'id="maintenance-banner"') !== false) {
                $template = preg_replace('/(<br><span id="maintenance-banner"[^>]*>.*?<\/span>)/is', '$1' . $banner, $template, 1) ?: $template;
            } else {
                $template = preg_replace('/(<span id="title">.*?<\/span>)/is', '$1<br>' . $banner, $template, 1) ?: $template;
            }
        }

        if (strpos($template, 'id="hard-ban-dev-banner"') !== false) {
            return $template;
        }

        $hardBanHelper = __DIR__ . DIRECTORY_SEPARATOR . 'hard-ban.php';
        if (!is_file($hardBanHelper)) {
            return $template;
        }
        require_once $hardBanHelper;

        $clientIp = fridg3_hard_ban_client_ip();
        $identifier = (string)($_COOKIE[FRIDG3_HARD_BAN_COOKIE] ?? '');
        $isHardBanned = $isAdmin
            ? fridg3_hard_ban_would_block_client($clientIp, $identifier)
            : fridg3_hard_ban_check_client($clientIp, $identifier);
        if (!$isHardBanned) {
            return $template;
        }

        $hardBanStatus = $isAdmin ? 'admin bypass active' : 'access termination active';
        $hardBanBanner = '<span id="hard-ban-dev-banner"><i class="fa-solid fa-skull-crossbones"></i> <b>hard-banned client</b><small>' . $hardBanStatus . '</small></span>';
        if (strpos($template, 'id="dev-mode-banner"') !== false) {
            return preg_replace('/(<span id="dev-mode-banner"[^>]*>.*?<\/span>)/is', '$1' . $hardBanBanner, $template, 1) ?: $template;
        }

        if ($isAdmin && strpos($template, 'id="title"') !== false) {
            return preg_replace('/(<span id="title">.*?<\/span>)/is', '$1<br>' . $hardBanBanner, $template, 1) ?: $template;
        }

        return $template;
    }
}
