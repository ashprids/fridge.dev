<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/sitemap.php';

function sitemap_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$routes = fridge_sitemap_public_static_routes();
$forbidden = [
    '/account/', '/account/admin/', '/chat/', '/feed/create/', '/feed/edit/', '/feed/posts/',
    '/guestbook/edit/', '/journal/create/', '/journal/create/preview/', '/journal/edit/',
    '/journal/edit/preview/', '/journal/posts/', '/music/upload/',
    '/others/toast-discord-bot/chat/history/', '/others/toast-discord-bot/messages/', '/settings/',
];

foreach ($forbidden as $path) sitemap_check(!isset($routes[$path]), "restricted route is sitemap-visible: {$path}");
foreach ($routes as $path => $file) {
    sitemap_check($path === '/' || (str_starts_with($path, '/') && str_ends_with($path, '/')), "route is not canonical: {$path}");
    sitemap_check(is_file($root . '/' . $file), "public sitemap route has no page: {$path}");
}

sitemap_check(isset($routes['/'], $routes['/feed/'], $routes['/journal/'], $routes['/guestbook/']), 'core public route missing');

$fixture = sys_get_temp_dir() . '/fridge-sitemap-' . bin2hex(random_bytes(8));
try {
    mkdir($fixture . '/data/feed/replies', 0700, true);
    mkdir($fixture . '/data/journal/drafts', 0700, true);
    file_put_contents($fixture . '/data/feed/feed post.txt', 'published feed post');
    file_put_contents($fixture . '/data/feed/replies/feed post.json', '{}');
    file_put_contents($fixture . '/data/journal/journal-post.md', "v2\n---\ntitle: Test journal\ndescription: Public story\ndate: 2026-09-15\n---\nContent");
    file_put_contents($fixture . '/data/journal/drafts/private.md', 'draft');

    file_put_contents($fixture . '/data/journal/12.txt', "2026-09-15\nNumeric legacy post\nDescription\nContent");
    file_put_contents($fixture . '/data/journal/invalid.md', 'not a valid post');
    $contentRoutes = fridge_sitemap_public_content_routes($fixture);
    sitemap_check(isset($contentRoutes['/journal/posts/12']), 'numeric legacy journal post missing');
    sitemap_check(!isset($contentRoutes['/journal/posts/invalid']), 'malformed journal entry included');
    $xml = fridge_sitemap_xml($fixture);
    sitemap_check(str_contains($xml, '<loc>https://fridge.dev/feed/posts/feed%20post</loc>'), 'post missing from generated XML');
    sitemap_check(!str_contains($xml, 'm.fridge.dev'), 'mobile host included in sitemap');
    sitemap_check(isset($contentRoutes['/feed/posts/feed%20post']), 'published feed post missing');
    sitemap_check(isset($contentRoutes['/journal/posts/journal-post']), 'published journal post missing');
    sitemap_check(!isset($contentRoutes['/feed/posts/replies']), 'feed reply data was included');
    sitemap_check(!isset($contentRoutes['/journal/posts/private']), 'journal draft was included');
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    if (is_dir($fixture)) rmdir($fixture);
}

echo "Public sitemap allowlist, content routes, and restricted-route exclusions passed.\n";
