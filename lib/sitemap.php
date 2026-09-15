<?php
declare(strict_types=1);

require_once __DIR__ . '/journal.php';

/**
 * Static, canonical pages that an anonymous visitor can open and that are
 * suitable for search indexing. Keeping this list explicit prevents a newly
 * added account, staff, parameter-only, or private route from being published
 * merely because it has an index.php file.
 *
 * @return array<string, string> URL path => repository-relative index file
 */
function fridge_sitemap_public_static_routes(): array
{
    return [
        '/' => 'index.php',
        '/contact/' => 'contact/index.php',
        '/discord/' => 'discord/index.php',
        '/feed/' => 'feed/index.php',
        '/gallery/' => 'gallery/index.php',
        '/guestbook/' => 'guestbook/index.php',
        '/journal/' => 'journal/index.php',
        '/merch/' => 'merch/index.php',
        '/music/' => 'music/index.php',
        '/others/' => 'others/index.php',
        '/others/firefox-theme/' => 'others/firefox-theme/index.php',
        '/others/fridge-builds-websites/' => 'others/fridge-builds-websites/index.php',
        '/others/minecraft-archive/' => 'others/minecraft-archive/index.php',
        '/others/toast-discord-bot/' => 'others/toast-discord-bot/index.php',
        '/tools/' => 'tools/index.php',
        '/tools/discord-export-viewer/' => 'tools/discord-export-viewer/index.php',
        '/tools/upload/' => 'tools/upload/index.php',
        '/wiki/' => 'wiki/index.php',
    ];
}

/** @return array<string, string> URL path => source content file */
function fridge_sitemap_public_content_routes(string $root): array
{
    $routes = [];
    $feedDirectory = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
    foreach (glob($feedDirectory . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $file) {
        if (!is_file($file) || !is_readable($file)) continue;
        $routes['/feed/posts/' . rawurlencode(pathinfo($file, PATHINFO_FILENAME))] = $file;
    }

    $journalDirectory = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
    foreach (fridge_journal_post_files($journalDirectory) as $file) {
        if (!is_file($file) || !is_readable($file)) continue;
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) continue;
        if (fridge_journal_parse_post((string)file_get_contents($file)) === null) continue;
        $routes['/journal/posts/' . rawurlencode($slug)] = $file;
    }

    // Wiki documents are public content, but sidebar/footer fragments are not pages.
    foreach (glob($root . '/wiki/*.md') ?: [] as $file) {
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if (!is_file($file) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $slug)) continue;
        $routes[$slug === 'Home' ? '/wiki/' : '/wiki/?page=' . rawurlencode($slug)] = $file;
    }

    return $routes;
}

/** Pure XML generation shared by the public endpoint and the admin button. */
function fridge_sitemap_xml(string $root): string
{
    $routes = [];
    foreach (fridge_sitemap_public_static_routes() as $path => $file) {
        $file = $root . '/' . $file;
        if (is_file($file)) $routes[$path] = $file;
    }
    $routes = array_replace($routes, fridge_sitemap_public_content_routes($root));
    ksort($routes, SORT_STRING);
    $xml = ['<?xml version="1.0" encoding="UTF-8"?>',
        '<!-- This sitemap was automatically generated.' . "\n" . date('d/m/y H:i:s') . ' -->',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
    foreach ($routes as $path => $file) {
        $xml[] = '  <url><loc>' . htmlspecialchars('https://fridge.dev' . $path, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
        // Use real content mtimes, never the sitemap regeneration time. Static
        // shells have no reliable content modification time, so omit lastmod.
        if (str_starts_with($file, $root . '/data/') || str_ends_with($file, '.md')) {
            $xml[] = '    <lastmod>' . gmdate(DATE_ATOM, filemtime($file)) . '</lastmod>';
        }
        $xml[] = '  </url>';
    }
    $xml[] = '</urlset>';
    return implode("\n", $xml) . "\n";
}
