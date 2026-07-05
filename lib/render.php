<?php

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
                    $style = preg_replace('/display\s*:\s*none\s*;?/i', 'display: inline;', $style, 1);
                } else {
                    $style = rtrim($style);
                    $style .= ($style !== '' && substr($style, -1) !== ';' ? ';' : '') . ' display: inline;';
                }
                return preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' ' . 'style' . '=' . $quote . $style . $quote, $tag, 1);
            }

            return rtrim($tag, '>') . ' style="display: inline;">';
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

    function fridg3_replace_logged_in_discord_footer_button($template, $startDir) {
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
                    fridg3_inject_dev_mode_banner(preg_replace('/<\/head>/i', $themeLink . '</head>', $template, 1)),
                    $startDir
                ),
                $startDir
            );
        }

        return fridg3_replace_logged_in_discord_footer_button(
            fridg3_apply_work_in_progress_banner(fridg3_inject_dev_mode_banner($themeLink . $template), $startDir),
            $startDir
        );
    }
}

if (!function_exists('fridg3_inject_dev_mode_banner')) {
    function fridg3_inject_dev_mode_banner($template) {
        if (!fridg3_is_local_dev_server() || strpos($template, 'id="dev-mode-banner"') !== false) {
            return $template;
        }

        $banner = '<span id="dev-mode-banner" style="color: #9fd6a3; line-height: 1.15;"><i class="fa-solid fa-code"></i> <b>developer mode</b></span>';
        if (strpos($template, 'id="maintenance-banner"') !== false) {
            return preg_replace('/(<br><span id="maintenance-banner"[^>]*>.*?<\/span>)/is', '$1' . $banner, $template, 1);
        }

        return preg_replace('/(<span id="title">.*?<\/span>)/is', '$1<br>' . $banner, $template, 1);
    }
}
