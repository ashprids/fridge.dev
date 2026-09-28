<?php
declare(strict_types=1);

/** Return the preferred URL for a browser request, or null if already canonical. */
function fridge_canonical_request_target(string $uri): ?string
{
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    $queryString = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
    parse_str($queryString, $query);
    $originalQuery = $query;
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

    // Keep the visitor's own encoding unless a parameter was removed, so
    // equivalent forms such as `q=a+b` and `q=a%20b` do not cause a redirect.
    $targetQuery = $query === $originalQuery
        ? $queryString
        : http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $target = $path . ($targetQuery === '' ? '' : '?' . $targetQuery);
    $original = $originalPath . ($queryString === '' ? '' : '?' . $queryString);
    return $target === $original ? null : $target;
}

/**
 * Whether the executing script serves the page named by REQUEST_URI.
 *
 * Nginx subrequests such as the hard-ban auth_request forward the visitor's
 * REQUEST_URI to a different script. A redirect from those scripts is not a
 * page redirect: auth_request treats any status other than 2xx/401/403 as a
 * 500 for the visitor. API endpoints are not crawlable pages either.
 */
function fridge_canonical_redirect_applies(): bool
{
    if (defined('FRIDGE_SKIP_CANONICAL_REDIRECT')) return false;
    $root = realpath(dirname(__DIR__));
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($root === false || $script === false) return true;
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($script, strlen($root)));
    return !str_starts_with($relative, '/api/');
}

function fridge_redirect_canonical_request(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    if (!fridge_canonical_redirect_applies()) return;
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
