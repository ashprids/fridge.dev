<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();

$renderHelperPath = null;
$searchDir = __DIR__;
$previousDir = '';
while ($searchDir !== $previousDir) {
    $candidate = $searchDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'render.php';
    if (is_file($candidate)) {
        $renderHelperPath = $candidate;
        break;
    }
    $previousDir = $searchDir;
    $searchDir = dirname($searchDir);
}
if ($renderHelperPath) {
    require_once $renderHelperPath;
}

function sysinfo_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sysinfo_format_bytes($bytes): string
{
    if (!is_numeric($bytes)) {
        return 'unknown';
    }

    $bytes = (float)$bytes;
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) {
        $bytes /= 1024;
        $unit++;
    }

    $precision = $unit === 0 ? 0 : 1;
    return number_format($bytes, $precision) . ' ' . $units[$unit];
}

function sysinfo_format_seconds($seconds): string
{
    if (!is_numeric($seconds)) {
        return 'unknown';
    }

    $seconds = (int)round((float)$seconds);
    if ($seconds < 60) {
        return $seconds . ' seconds';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 || $hours > 0 || $days > 0) {
        $parts[] = $minutes . 'm';
    }
    $parts[] = $seconds . 's';

    return implode(' ', $parts);
}

function sysinfo_format_value($value): string
{
    if ($value === null || $value === '') {
        return 'unknown';
    }

    if (is_array($value) && isset($value['html'])) {
        return (string)$value['html'];
    }

    return sysinfo_h((string)$value);
}

function sysinfo_badge(string $value, string $tone = 'neutral'): array
{
    return ['html' => '<span class="sysinfo-pill sysinfo-pill-' . sysinfo_h($tone) . '">' . sysinfo_h($value) . '</span>'];
}

function sysinfo_table(array $rows): string
{
    $html = '<table class="sysinfo-table">';
    foreach ($rows as $label => $value) {
        $html .= '<tr>'
            . '<th>' . sysinfo_h((string)$label) . '</th>'
            . '<td>' . sysinfo_format_value($value) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function sysinfo_card(string $title, string $subtitle, array $rows): string
{
    return '<section class="sysinfo-card">'
        . '<h3>' . sysinfo_h($title) . '</h3>'
        . ($subtitle !== '' ? '<p class="sysinfo-card-subtitle">' . sysinfo_h($subtitle) . '</p>' : '')
        . sysinfo_table($rows)
        . '</section>';
}

function sysinfo_glob_count(string $pattern): ?int
{
    $items = glob($pattern);
    if (!is_array($items)) {
        return null;
    }
    return count($items);
}

function sysinfo_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function sysinfo_collect_environment(): array
{
    $rootDir = dirname(__DIR__, 2);
    $toastConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'toast.json';
    $wipPath = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wip';
    $toastData = sysinfo_read_json_file($toastConfigPath);

    $uptime = null;
    if (is_file('/proc/uptime')) {
        $uptimeRaw = trim((string)@file_get_contents('/proc/uptime'));
        $parts = preg_split('/\s+/', $uptimeRaw);
        if (isset($parts[0]) && is_numeric($parts[0])) {
            $uptime = (float)$parts[0];
        }
    }

    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $meminfo = [];
    if (is_file('/proc/meminfo')) {
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^([A-Za-z0-9_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                    $meminfo[$matches[1]] = (int)$matches[2] * 1024;
                }
            }
        }
    }

    $cpuModel = null;
    if (is_file('/proc/cpuinfo')) {
        $lines = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^model name\s*:\s*(.+)$/i', $line, $matches)) {
                    $cpuModel = trim($matches[1]);
                    break;
                }
            }
        }
    }

    $rootTotal = @disk_total_space('/');
    $rootFree = @disk_free_space('/');
    $rootUsed = (is_numeric($rootTotal) && is_numeric($rootFree)) ? max(0, (float)$rootTotal - (float)$rootFree) : null;

    return [
        'system' => [
            'hostname' => gethostname() ?: 'unknown',
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'kernel' => php_uname('v') ?: 'unknown',
            'arch' => php_uname('m') ?: 'unknown',
            'cpu_model' => $cpuModel,
            'runtime_user' => function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (($info = @posix_getpwuid(@posix_geteuid())) && isset($info['name']) ? (string)$info['name'] : 'unknown')
                : get_current_user(),
            'uptime' => $uptime,
            'load' => is_array($load) ? $load : null,
            'cpu_model' => null,
            'memory_total' => $meminfo['MemTotal'] ?? null,
            'memory_available' => $meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? null),
            'memory_swap_total' => $meminfo['SwapTotal'] ?? null,
            'memory_swap_free' => $meminfo['SwapFree'] ?? null,
            'root_total' => $rootTotal !== false ? $rootTotal : null,
            'root_free' => $rootFree !== false ? $rootFree : null,
            'root_used' => $rootUsed,
        ],
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_vars' => ini_get('max_input_vars'),
            'timezone' => date_default_timezone_get(),
            'ini' => php_ini_loaded_file() ?: 'none',
            'opcache' => function_exists('opcache_get_status') ? (ini_get('opcache.enable') ? 'enabled' : 'disabled') : 'unavailable',
            'php_memory' => memory_get_usage(true),
            'php_peak' => memory_get_peak_usage(true),
        ],
        'web' => [
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ],
        'website' => [
            'maintenance_enabled' => is_file($wipPath) ? fridg3_is_truthy_value((string)@file_get_contents($wipPath)) : false,
            'data_writable' => is_dir($rootDir . DIRECTORY_SEPARATOR . 'data') ? (is_writable($rootDir . DIRECTORY_SEPARATOR . 'data') ? 'yes' : 'no') : 'missing',
            'sitemap_exists' => is_file($rootDir . DIRECTORY_SEPARATOR . 'sitemap.xml'),
            'toast' => is_array($toastData) ? $toastData : null,
            'counts' => [
                'accounts' => sysinfo_read_json_file($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json'),
                'feed_posts' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed' . DIRECTORY_SEPARATOR . '*.txt'),
                'journal_posts' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . '*.txt'),
                'guestbook_entries' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook' . DIRECTORY_SEPARATOR . '*.txt'),
                'chat_threads' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . '*.json'),
                'images' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . '*'),
                'mdpaste' => sysinfo_glob_count($rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . '*.json'),
            ],
        ],
        'extensions' => array_map('strtolower', get_loaded_extensions()),
    ];
}

function sysinfo_render_extension_row(array $extensions, string $name): array
{
    return [
        'label' => $name,
        'value' => in_array(strtolower($name), $extensions, true) ? sysinfo_badge('available', 'good') : sysinfo_badge('missing', 'warn'),
    ];
}

function sysinfo_render_content(array $data): string
{
    $system = $data['system'];
    $php = $data['php'];
    $web = $data['web'];
    $website = $data['website'];
    $extensions = $data['extensions'];
    $toast = is_array($website['toast'] ?? null) ? $website['toast'] : [];
    $counts = is_array($website['counts'] ?? null) ? $website['counts'] : [];
    $accountsData = is_array($counts['accounts'] ?? null) ? $counts['accounts'] : null;
    $accountCount = is_array($accountsData['accounts'] ?? null) ? count($accountsData['accounts']) : null;
    $adminCount = 0;
    if (is_array($accountsData['accounts'] ?? null)) {
        foreach ($accountsData['accounts'] as $account) {
            if (!empty($account['isAdmin'])) {
                $adminCount++;
            }
        }
    }

    $phpExtensions = [
        sysinfo_render_extension_row($extensions, 'curl'),
        sysinfo_render_extension_row($extensions, 'json'),
        sysinfo_render_extension_row($extensions, 'mbstring'),
        sysinfo_render_extension_row($extensions, 'openssl'),
        sysinfo_render_extension_row($extensions, 'zip'),
        sysinfo_render_extension_row($extensions, 'gd'),
        sysinfo_render_extension_row($extensions, 'intl'),
        sysinfo_render_extension_row($extensions, 'fileinfo'),
    ];

    $summaryCards = '<div class="sysinfo-summary-grid">'
        . sysinfo_card('server', 'host and runtime basics', [
            'hostname' => $system['hostname'],
            'operating system' => $system['os'],
            'kernel' => $system['kernel'],
            'architecture' => $system['arch'],
            'cpu model' => $system['cpu_model'] ?? 'unknown',
            'runtime user' => $system['runtime_user'],
            'uptime' => sysinfo_format_seconds($system['uptime']),
        ])
        . sysinfo_card('web server', 'request and PHP front-end', [
            'server software' => $web['server_software'],
            'server name' => $web['server_name'],
            'host' => $web['host'],
            'request' => $web['request_method'] . ' ' . $web['request_uri'],
            'document root' => $web['document_root'],
            'script file' => $web['script_filename'],
        ])
        . sysinfo_card('php runtime', 'interpreter and limits', [
            'version' => $php['version'],
            'sapi' => $php['sapi'],
            'timezone' => $php['timezone'],
            'memory limit' => $php['memory_limit'],
            'upload max filesize' => $php['upload_max_filesize'],
            'post max size' => $php['post_max_size'],
            'max execution time' => $php['max_execution_time'],
        ])
        . '</div>';

    $systemSection = sysinfo_card('system diagnostics', 'cpu, memory, and disk', [
        'load average' => is_array($system['load']) ? implode(' / ', array_map(static fn($value) => number_format((float)$value, 2), $system['load'])) : 'unknown',
        'memory total' => sysinfo_format_bytes($system['memory_total']),
        'memory available' => sysinfo_format_bytes($system['memory_available']),
        'swap total' => sysinfo_format_bytes($system['memory_swap_total']),
        'swap free' => sysinfo_format_bytes($system['memory_swap_free']),
        'root filesystem used' => is_numeric($system['root_used']) && is_numeric($system['root_total'])
            ? sysinfo_format_bytes($system['root_used']) . ' / ' . sysinfo_format_bytes($system['root_total'])
            : 'unknown',
        'php process memory' => sysinfo_format_bytes($php['php_memory']),
        'php peak memory' => sysinfo_format_bytes($php['php_peak']),
    ]);

    $websiteSection = sysinfo_card('website diagnostics', 'fridge.dev-specific state', [
        'maintenance mode' => sysinfo_badge(!empty($website['maintenance_enabled']) ? 'enabled' : 'disabled', !empty($website['maintenance_enabled']) ? 'warn' : 'good'),
        'data directory writable' => sysinfo_badge((string)$website['data_writable'], $website['data_writable'] === 'yes' ? 'good' : ($website['data_writable'] === 'missing' ? 'warn' : 'neutral')),
        'sitemap.xml present' => sysinfo_badge(!empty($website['sitemap_exists']) ? 'yes' : 'no', !empty($website['sitemap_exists']) ? 'good' : 'warn'),
        'toast bot status' => sysinfo_badge((string)($toast['bot']['status'] ?? 'unknown'), (($toast['bot']['status'] ?? '') === 'online') ? 'good' : 'warn'),
        'toast stream' => (string)($toast['stream']['name'] ?? 'unknown'),
        'toast stream url' => (string)($toast['stream']['url'] ?? 'unknown'),
        'accounts' => $accountCount !== null ? (string)$accountCount : 'unknown',
        'admin accounts' => (string)$adminCount,
        'feed posts' => (string)($counts['feed_posts'] ?? 'unknown'),
        'journal posts' => (string)($counts['journal_posts'] ?? 'unknown'),
        'guestbook entries' => (string)($counts['guestbook_entries'] ?? 'unknown'),
        'chat threads' => (string)($counts['chat_threads'] ?? 'unknown'),
        'images' => (string)($counts['images'] ?? 'unknown'),
        'mdpaste records' => (string)($counts['mdpaste'] ?? 'unknown'),
    ]);

    $phpSection = sysinfo_card('php capabilities', 'useful extensions and config', [
        'loaded ini' => $php['ini'],
        'opcache' => $php['opcache'],
        'max input vars' => $php['max_input_vars'],
        'extensions: curl' => $phpExtensions[0]['value'],
        'extensions: json' => $phpExtensions[1]['value'],
        'extensions: mbstring' => $phpExtensions[2]['value'],
        'extensions: openssl' => $phpExtensions[3]['value'],
        'extensions: zip' => $phpExtensions[4]['value'],
        'extensions: gd' => $phpExtensions[5]['value'],
        'extensions: intl' => $phpExtensions[6]['value'],
        'extensions: fileinfo' => $phpExtensions[7]['value'],
    ]);

    $viewer = sysinfo_card('current viewer', 'who is looking at this page', [
        'session user' => isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : 'guest',
        'is admin' => sysinfo_badge(!empty($_SESSION['user']['isAdmin']) ? 'yes' : 'no', !empty($_SESSION['user']['isAdmin']) ? 'good' : 'warn'),
        'request scheme' => !empty($web['https']) ? 'https' : 'http',
    ]);

    $notes = '<div class="sysinfo-note">'
        . '<strong>note:</strong> values marked unknown mean the host does not expose that metric or PHP cannot read it.'
        . ' this page is intentionally admin-only because it surfaces the web server and runtime shape.'
        . '</div>';

    return $notes . $summaryCards . '<div class="sysinfo-secondary-grid">' . $systemSection . $websiteSection . $phpSection . $viewer . '</div>';
}

function sysinfo_render_access_denied(): string
{
    return '<div class="sysinfo-page">'
        . '<h1>system info</h1>'
        . '<h2>admin access required.</h2>'
        . '<p>this page exposes server and website diagnostics, so only admins can open it.</p>'
        . '<p><a href="/settings">back to settings</a></p>'
        . '</div>';
}

$isAdmin = isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;
if (!$isAdmin) {
    http_response_code(403);
    $title = 'system info';
    $description = 'admin-only system diagnostics.';
    $content = sysinfo_render_access_denied();
} else {
    $diagnostics = sysinfo_collect_environment();

    $title = 'system info';
    $description = 'admin-only diagnostics for the current server and fridge.dev runtime.';
    $content = '<style>
.sysinfo-page{display:flex;flex-direction:column;gap:16px;}
.sysinfo-page h1,.sysinfo-page h2{margin-bottom:0;}
.sysinfo-note{padding:12px 14px;border:1px solid var(--border);background:color-mix(in srgb, var(--bg) 92%, var(--fg) 8%);color:var(--subtle);border-radius:8px;}
.sysinfo-summary-grid,.sysinfo-secondary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;}
.sysinfo-card{border:1px solid var(--border);background:color-mix(in srgb, var(--bg) 88%, var(--fg) 12%);border-radius:8px;padding:14px 16px;}
.sysinfo-card h3{margin:0 0 6px 0;}
.sysinfo-card-subtitle{margin:0 0 12px 0;color:var(--subtle);}
.sysinfo-table{width:100%;border-collapse:collapse;}
.sysinfo-table th,.sysinfo-table td{padding:6px 0;vertical-align:top;text-align:left;border-top:1px solid color-mix(in srgb, var(--border) 55%, transparent);}
.sysinfo-table tr:first-child th,.sysinfo-table tr:first-child td{border-top:none;padding-top:0;}
.sysinfo-table th{width:42%;color:var(--subtle);font-weight:normal;}
.sysinfo-pill{display:inline-block;padding:1px 8px;border:1px solid var(--border);border-radius:999px;background:var(--bg);font-size:0.9em;}
.sysinfo-pill-good{color:var(--links);}
.sysinfo-pill-warn{color:var(--subtle);}
.sysinfo-pill-neutral{color:inherit;}
</style>'
        . '<div class="sysinfo-page">'
        . '<h1>system info</h1>'
        . '<h2>current server, web stack, and fridge.dev diagnostics.</h2>'
        . '<p class="sysinfo-note">updated live from the current request. this is the admin dashboard for debugging the host, the php runtime, and the website state.</p>'
        . sysinfo_render_content($diagnostics)
        . '</div>';
}

function find_template_file(string $filename): ?string
{
    $dir = __DIR__;
    $prevDir = '';

    while ($dir !== $prevDir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prevDir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

$templateName = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$templatePath = find_template_file($templateName);
if (!$templatePath && $templateName !== 'template.html') {
    $templatePath = find_template_file('template.html');
}
if (!$templatePath) {
    http_response_code(500);
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = file_get_contents($templatePath);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

$contentPath = find_template_file('content.html');
if (!$contentPath) {
    http_response_code(500);
    die('content.html not found. report this issue to me@fridge.dev.');
}

$contentTemplate = file_get_contents($contentPath);
$contentTemplate = str_replace('{sysinfo_content}', $content, $contentTemplate);
$contentTemplate = str_replace('{sysinfo_title}', $title, $contentTemplate);
$contentTemplate = str_replace('{sysinfo_description}', $description, $contentTemplate);

$html = str_replace('{content}', $contentTemplate, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

$userGreeting = '';
if (isset($_SESSION['user']) && isset($_SESSION['user']['name'])) {
    $userName = htmlspecialchars((string)$_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $userGreeting = '<div id="user-greeting">Hello, ' . $userName . '!</div>';
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountBtn, $logoutBtn, $html);
}
$html = str_replace('{user_greeting}', $userGreeting, $html);
echo $html;
