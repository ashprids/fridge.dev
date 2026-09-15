<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/seo.php';

function seo_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/fridge-seo-' . bin2hex(random_bytes(8));
try {
    mkdir($root . '/data/feed', 0700, true);
    mkdir($root . '/data/journal/drafts', 0700, true);
    mkdir($root . '/wiki', 0700, true);
    file_put_contents($root . '/data/feed/123.txt', "v2\n@alice\n2026-09-15 12:00:00\nA **real update** with [a link](https://example.com) and café. {content} </script><script>alert(1)</script>");
    file_put_contents($root . '/data/journal/12.md', "v2\n---\ntitle: A journal story\ndescription: A public journal description\ndate: 2026-09-15\ncard_image: /data/images/story.jpg\n---\nPublic body");
    file_put_contents($root . '/data/journal/drafts/secret.md', 'private draft');
    file_put_contents($root . '/wiki/Setup.md', "# Developer setup\n\nInstall PHP and configure your local environment.");
    file_put_contents($root . '/wiki/_Sidebar.md', 'navigation only');
    foreach (fridge_sitemap_public_content_routes($root) as $path => $file) {
        $metadata = fridge_seo_metadata($root, $path);
        seo_check($metadata['public'], 'sitemap includes a noindex content page: ' . $path);
        seo_check($metadata['canonical'] === 'https://fridge.dev' . $path, 'sitemap and canonical disagree: ' . $path);
    }
    seo_check(!str_contains(fridge_sitemap_xml($root), '_Sidebar'), 'wiki fragment included');
    $shell = '<html><head><title>{title} | fridge.dev</title><meta name="description" content="{description}"></head><body>{content}</body></html>';
    foreach (['/', '/index.php', 'https://m.fridge.dev/', 'https://www.fridge.dev/'] as $uri) {
        $html = fridge_seo_template($shell, $root, $uri);
        seo_check(str_contains($html, '<title data-fridge-seo>fridge.dev</title>'), 'home title incorrect');
        seo_check(str_contains($html, 'rel="canonical" href="https://fridge.dev/"'), 'home canonical incorrect');
        seo_check(!str_contains($html, 'm.fridge.dev'), 'mobile URL leaked into metadata');
    }
    $cases = [
        '/feed/?page=2&utm_source=test' => '/feed/?page=2',
        '/feed/index.php?page=1' => '/feed/',
        '/feed/posts/?=123' => '/feed/posts/123',
        '/feed/posts/123.txt/' => '/feed/posts/123',
        '/journal/posts/index.php?post=12' => '/journal/posts/12',
        '/wiki?page=Setup&utm_source=test' => '/wiki/?page=Setup',
        '/wiki?page=Home' => '/wiki/',
    ];
    foreach ($cases as $uri => $path) seo_check(fridge_seo_path($uri) === $path, 'canonical mismatch: ' . $uri);
    foreach (['/account/admin/', '/settings/guests/', '/chat/abc123/', '/journal/create/preview/', '/music/upload/', '/bookmarks/', '/notifications/', '/feed/?q=secret', '/tools/mdpaste/s/abcd/', '/new-private-route/', '/feed/posts/missing', '/journal/posts/secret'] as $uri) {
        $html = fridge_seo_template($shell, $root, $uri);
        seo_check(str_contains($html, 'content="noindex, follow"'), 'private/search page indexable: ' . $uri);
        seo_check(!str_contains($html, 'application/ld+json'), 'private page has rich metadata: ' . $uri);
    }
    $post = fridge_seo_metadata($root, '/feed/posts/123');
    seo_check($post['public'] && str_contains($post['description'], 'real update') && str_contains($post['description'], 'café'), 'feed snippet damaged');
    seo_check(!str_contains($post['description'], 'alert(1)'), 'script content in snippet');
    foreach (['/', '/feed/posts/123', '/journal/posts/12', '/wiki?page=Setup'] as $uri) {
        $html = fridge_seo_template($shell, $root, $uri);
        // Simulate the later route-level placeholder substitutions.
        $html = str_replace(['{content}', '{title}', '{description}'], ['<p>Rendered page</p>', 'Page', 'Description'], $html);
        preg_match('~<script data-fridge-seo type="application/ld\+json">(.*?)</script>~s', $html, $match);
        $schema = json_decode($match[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        seo_check(count($schema['@graph']) >= 2, 'missing structured data');
        seo_check(substr_count($html, 'rel="canonical"') === 1, 'duplicate canonical');
        seo_check(!str_contains($html, '<script>alert'), 'unsafe metadata output');
        $again = fridge_seo_template($html, $root, $uri);
        seo_check(substr_count($again, 'application/ld+json') === 1, 'duplicate structured data after reapplication');
    }
    $journal = fridge_seo_metadata($root, '/journal/posts/12');
    seo_check($journal['image'] === 'https://fridge.dev/data/images/story.jpg', 'journal image not absolute');
    seo_check(isset($journal['published']), 'article publication date missing');
    seo_check(str_contains(fridge_seo_template($shell, $root, '/', true), 'noindex, follow'), 'development homepage indexable');
    http_response_code(404);
    seo_check(str_contains(fridge_seo_template($shell, $root, '/'), 'noindex, follow'), '404 response indexable');
    http_response_code(200);
    seo_check(!str_contains(fridge_sitemap_xml($root), 'secret'), 'draft leaked into sitemap');
    echo "Canonical aliases, mobile consolidation, pagination, private pages, snippets, JSON-LD safety, and public content checks passed.\n";
} finally {
    if (is_dir($root)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
