<?php
declare(strict_types=1);

/** Return the preferred URL for a browser request, or null if already canonical. */
function fridge_canonical_request_target(string $uri): ?string
{
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    $queryString = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
    parse_str($queryString, $query);
    $originalPath = $path;

    $publicDirectories = [
        '/contact', '/discord', '/feed', '/gallery', '/guestbook', '/journal',
        '/merch', '/music', '/others', '/others/firefox-theme',
        '/others/fridge-builds-websites', '/others/minecraft-archive',
        '/others/toast-discord-bot', '/tools', '/tools/discord-export-viewer',
        '/tools/upload', '/wiki',
    ];
    $path = preg_replace('~/index\.php$~', '', $path) ?? $path;
    $path = $path === '' ? '/' : $path;
    $pathWithoutSlash = rtrim($path, '/') ?: '/';
    if (in_array($pathWithoutSlash, $publicDirectories, true)) {
        $path = $pathWithoutSlash . '/';
    } elseif (preg_match('~^/(?:feed|journal)/posts/[^/]+/$~', $path)) {
        $path = rtrim($path, '/');
    }

    // This marker only triggered an old-domain popup. It must not create a
    // separate crawlable URL for every page.
    unset($query['legacy_domain']);
    if (in_array($path, ['/feed/', '/gallery/', '/guestbook/', '/journal/'], true)
        && isset($query['page']) && (string)$query['page'] === '1') {
        unset($query['page']);
    }
    if ($path === '/wiki/' && (($query['page'] ?? null) === 'Home' || ($query['page'] ?? null) === '')) {
        unset($query['page']);
    }

    $target = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    $original = $originalPath . ($queryString === '' ? '' : '?' . $queryString);
    return $target === $original ? null : $target;
}

function fridge_redirect_canonical_request(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;
    $target = fridge_canonical_request_target((string)($_SERVER['REQUEST_URI'] ?? '/'));
    if ($target === null) return;
    parse_str((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? ''), $query);
    if (($query['legacy_domain'] ?? null) === 'fridg3.org') {
        setcookie('legacy_domain_notice', '1', [
            'expires' => time() + 300,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . $target, true, 301);
    exit;
}
