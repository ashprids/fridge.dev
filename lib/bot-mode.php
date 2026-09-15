<?php
require_once __DIR__ . '/toast.php';

function fridge_bot_mode_path_allowed(string $path): bool {
    $path = rawurldecode(explode('?', $path, 2)[0]);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '..') array_pop($parts);
        elseif ($part !== '' && $part !== '.') $parts[] = $part;
    }
    $path = '/' . implode('/', $parts);
    $path = preg_replace('~/index\.php$~', '', $path) ?: '/';
    if (in_array($path, ['/', '/others', '/others/toast-discord-bot', '/others/toast-discord-bot/chat/history'], true)) return true;
    foreach (['/feed', '/settings', '/account', '/others/toast-discord-bot/messages'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
    }
    // Services needed by the permitted pages and persistent player; each retains its own authorization.
    return in_array($path, ['/api/debug-process-logs', '/api/settings', '/api/toast-credentials', '/api/toast-models', '/api/toast-feed-generate', '/api/toast-feed-reply', '/api/feed-post', '/api/feed-usernames', '/api/page-view', '/api/themes', '/api/account/is-admin', '/api/discord-bot-status', '/api/stream-proxy', '/api/ip-restriction', '/api/hard-ban-check', '/api/discord-bot-control', '/api/discord-bot-control/status'], true);
}

function fridge_enforce_bot_mode(): void {
    if (!fridge_toast_is_current_user() || fridge_bot_mode_path_allowed((string)($_SERVER['REQUEST_URI'] ?? '/'))) return;
    header('Cache-Control: no-store');
    if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'bot mode is active', 'botMode' => true]);
    } else {
        header('Location: /?bot_mode_blocked=1', true, 303);
    }
    exit;
}

function fridge_bot_mode_disable_links(string $html, array $allowed): string {
    return preg_replace_callback('~<a\b([^>]*href="([^"]+)"[^>]*)>(.*?)</a>~is', static function(array $m) use ($allowed): string {
        $path = rtrim((string)parse_url(html_entity_decode($m[2]), PHP_URL_PATH), '/') ?: '/';
        if (in_array($path, $allowed, true)) return $m[0];
        $tip = "this page is not accessible while you're logged in as toast";
        $body = preg_replace('/data-tooltip="[^"]*"/', '', $m[3]);
        return '<a' . $m[1] . ' aria-disabled="true" data-bot-disabled="1" data-tooltip="' . $tip . '" style="opacity:.4;cursor:not-allowed">' . $body . '</a>';
    }, $html);
}

function fridge_bot_mode_template(string $template): string {
    $active = fridge_toast_is_current_user();
    $runtime = '<meta name="fridge-bot-mode" content="' . ($active ? '1' : '0') . '"><script defer src="/js/bot-mode.js?v=20260915-namespace-1"></script>';
    $template = str_replace('</head>', $runtime . '</head>', $template);
    if (!$active) return $template;
    $template = fridge_bot_mode_disable_links($template, ['/', '/feed', '/settings', '/account', '/account/logout', '/others']);
    if (preg_match('/<body\b[^>]*\bclass=["\']/', $template)) {
        $template = preg_replace('/(<body\b[^>]*\bclass=["\'])/', '$1bot-mode ', $template, 1);
    } else {
        $template = preg_replace('/<body\b/', '<body class="bot-mode"', $template, 1);
    }
    $banner = '<span id="bot-mode-banner" data-tooltip="Bot mode was enabled for you because you\'re logged in as Toast. While bot mode is active, you can only access certain pages that Toast needs to function."><i class="fa-solid fa-robot" aria-hidden="true"></i> <b>bot mode</b></span>';
    return str_contains($template, 'id="maintenance-banner"')
        ? preg_replace('/(<span id="maintenance-banner")/', $banner . '$1', $template, 1)
        : str_replace('<div id="header">', '<div id="header">' . $banner, $template);
}
