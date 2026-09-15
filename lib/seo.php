<?php
declare(strict_types=1);

require_once __DIR__ . '/sitemap.php';
require_once __DIR__ . '/journal.php';

function fridge_seo_escape(string $value): string
{
    return str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/** Plain, bounded snippets; never put markup or private metadata into the head. */
function fridge_seo_text(string $value, int $limit = 170): string
{
    $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $value) ?? $value;
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~!\[([^\]]*)\]\([^)]*\)|\[(?:img|audio|video|name)[^\]]*\]~i', ' ', $value) ?? $value;
    $value = preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $value) ?? $value;
    $value = preg_replace('~\[/?[a-z]+(?:=[^\]]*)?\]|[`*_#>|]+~i', '', $value) ?? $value;
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value, 'UTF-8') <= $limit) return $value;
    $short = mb_substr($value, 0, $limit - 1, 'UTF-8');
    $space = mb_strrpos($short, ' ', 0, 'UTF-8');
    if ($space !== false && $space > $limit * 0.65) $short = mb_substr($short, 0, $space, 'UTF-8');
    return rtrim($short) . '…';
}

/** Normalize aliases but retain page numbers and wiki document identity. */
function fridge_seo_path(string $uri): string
{
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    $path = preg_replace('~/index\.php$~', '/', $path) ?? $path;
    $path = '/' . trim($path, '/');
    $queryString = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
    parse_str($queryString, $query);
    if ($path === '/feed/posts' && str_starts_with($queryString, '=')) {
        $path .= '/' . rawurlencode(basename(rawurldecode(substr($queryString, 1))));
    } elseif ($path === '/journal/posts' && is_string($query['post'] ?? null) && preg_match('/^[a-zA-Z0-9_-]+$/', $query['post'])) {
        $path .= '/' . $query['post'];
    }
    if (preg_match('~^/feed/posts/([^/]+)$~', $path, $match)) {
        return '/feed/posts/' . rawurlencode(preg_replace('/\.txt$/i', '', rawurldecode($match[1])) ?? '');
    }
    if (preg_match('~^/journal/posts/([a-zA-Z0-9_-]+)$~', $path)) return $path;
    $path = $path === '/' ? '/' : $path . '/';
    if ($path === '/wiki/' && is_string($query['page'] ?? null) && preg_match('/^[a-zA-Z0-9_-]+$/', $query['page']) && $query['page'] !== 'Home') {
        return $path . '?page=' . rawurlencode($query['page']);
    }
    if (in_array($path, ['/feed/', '/journal/', '/guestbook/', '/gallery/', '/tools/', '/others/'], true)
        && is_scalar($query['page'] ?? null) && ctype_digit((string)$query['page']) && (int)$query['page'] > 1) {
        return $path . '?page=' . (int)$query['page'];
    }
    return $path;
}

/** SEO copy is shared by metadata and the sitemap's public route inventory. */
function fridge_seo_page_copy(): array
{
    return [
        '/' => ['fridge.dev', 'Explore fridge.dev: a personal website and creative community with original music, journal stories, feed updates, web tools, Minecraft worlds, and independent projects.'],
        '/feed/' => ['Feed: updates and conversations', 'Read the latest updates, ideas, photos, and conversations on the fridge.dev feed. Explore individual posts and join the discussion with guest replies.'],
        '/journal/' => ['Journal: stories and long-form posts', 'Explore the fridge.dev journal for longer stories, personal updates, and posts about creative projects. Browse recent entries or search the archive.'],
        '/guestbook/' => ['Guestbook: messages from visitors', 'Read messages from visitors to fridge.dev and leave a note of your own. A guestbook for the people who discover this corner of the independent web.'],
        '/music/' => ['Music by frdg3 and Cactile', 'Listen to original songs, singles, remixes, and albums by frdg3 and Cactile. Explore releases and play music directly on fridge.dev.'],
        '/gallery/' => ['Gallery: images from fridge.dev', 'Browse the fridge.dev image gallery, explore pictures shared across the site, and open full-size images in the viewer.'],
        '/contact/' => ['Contact fridge.dev', 'Get in touch with fridge.dev to share feedback, ask a question, or report a website issue. Send a message using the contact form.'],
        '/discord/' => ['Join the fridge.dev Discord community', 'Join the fridge.dev Discord community to chat, share updates, and listen to Toast radio. Find the invitation and meet other visitors to the site.'],
        '/merch/' => ['Merch: music, vinyl, CDs and clothing', 'Explore fridge.dev merchandise, including frdg3 music on vinyl and CD and clothing inspired by the releases. Find links to the official stores.'],
        '/tools/' => ['Web tools and browser utilities', 'Explore fridge.dev web tools: view Discord exports, transfer files directly between browsers, and write and share Markdown notes with mdpaste.'],
        '/tools/discord-export-viewer/' => ['Discord export viewer', 'View and search Discord JSON exports locally in your browser. Explore messages and attachments without uploading your archive to a server.'],
        '/tools/upload/' => ['Encrypted browser-to-browser file transfer', 'Send files directly between browsers with encrypted peer-to-peer transfers. Create a sharing link without storing uploaded file contents on the server.'],
        '/others/' => ['Projects, downloads and experiments', 'Discover fridge.dev projects, including Firefox themes, archived Minecraft worlds, Toast the Discord bot, website commissions, and developer documentation.'],
        '/others/firefox-theme/' => ['Blackprint Firefox theme', 'Give Firefox a Blackprint-inspired look with square browser chrome and a dark purple-to-teal background. Explore the fridge.dev theme and setup details.'],
        '/others/minecraft-archive/' => ['Minecraft worlds and server archive', 'Explore an archive of Minecraft worlds, builds, survival games, modpacks, and multiplayer servers collected across years of playing and creating.'],
        '/others/fridge-builds-websites/' => ['fridge builds websites: custom web design', 'Commission a custom website for your portfolio, business, or personal project. Explore previous work, web design options, hosting, and maintenance.'],
        '/others/toast-discord-bot/' => ['Toast: Discord bot and 24/7 radio', 'Meet Toast, the fridge.dev Discord bot. Listen to 24/7 radio, explore the bot and its features, and find out how Toast connects the website and community.'],
        '/wiki/' => ['fridge.dev developer wiki', 'Explore the fridge.dev developer wiki: local setup, PHP architecture, themes, data formats, APIs, and deployment guides for working on the website.'],
    ];
}

/** Build metadata independently of session privileges and visitor host. */
function fridge_seo_metadata(string $root, string $uri): array
{
    $path = fridge_seo_path($uri);
    $basePath = (string)parse_url($path, PHP_URL_PATH);
    $copy = fridge_seo_page_copy()[$basePath] ?? null;
    $public = isset(fridge_sitemap_public_static_routes()[$basePath]);
    $meta = ['path' => $path, 'canonical' => 'https://fridge.dev' . $path, 'public' => $public,
        'title' => $copy[0] ?? '', 'description' => $copy[1] ?? '', 'type' => 'WebPage',
        'image' => 'https://fridge.dev/resources/icons/icon-512.png', 'imageAlt' => 'fridge.dev icon'];
    if (preg_match('~^/(feed|journal)/posts/([^/]+)$~', $path, $match)) {
        $section = $match[1];
        $slug = rawurldecode($match[2]);
        if (basename($slug) !== $slug || str_contains($slug, '\\')) return $meta;
        $file = $section === 'journal' ? fridge_journal_post_path($root . '/data/journal', $slug) : $root . '/data/feed/' . $slug . '.txt';
        $raw = $file && is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($raw === false) return $meta;
        if ($section === 'journal') {
            $post = fridge_journal_parse_post($raw);
            if (!$post) return $meta;
            $meta['title'] = fridge_seo_text($post['title'], 100);
            $meta['description'] = fridge_seo_text($post['description'] ?: $post['body']);
            $meta['type'] = 'BlogPosting';
            $body = $post['body'];
            $date = $post['date'];
            $image = $post['cardImage'];
        } else {
            require_once __DIR__ . '/feed.php';
            $post = fridge_feed_parse_post($raw);
            $body = $post['body'];
            $date = $post['date'];
            $meta['author'] = '@' . ltrim($post['username'], '@');
            $summary = fridge_seo_text($body, 65);
            $meta['title'] = ($summary ?: 'Feed post') . ' — ' . $meta['author'];
            $meta['description'] = fridge_seo_text($body) ?: 'View this post by ' . $meta['author'] . ' and join the conversation on fridge.dev.';
            $meta['type'] = 'SocialMediaPosting';
            $image = '';
        }
        $meta['public'] = true;
        if ($image === '' && preg_match('~!\[[^\]]*\]\(([^\s)]+)\)|\[img=([^\]\s]+)\]~i', $body, $imageMatch)) $image = $imageMatch[1] ?: ($imageMatch[2] ?? '');
        if (str_starts_with($image, '/') && !str_starts_with($image, '//')) $image = 'https://fridge.dev' . $image;
        if (preg_match('~^https?://[^\s<>"\']+$~i', $image)) {
            $meta['image'] = preg_replace('~^https?://(?:m\.|www\.)?fridge\.dev/~i', 'https://fridge.dev/', $image);
            $meta['imageAlt'] = $meta['title'];
        }
        if (trim($date) !== '') {
            try { $meta['published'] = (new DateTimeImmutable($date))->format(DATE_ATOM); } catch (Exception $error) { /* omit invalid dates */ }
        }
        $meta['modified'] = gmdate(DATE_ATOM, filemtime($file));
    } elseif ($basePath === '/wiki/' && str_contains($path, '?')) {
        parse_str((string)parse_url($path, PHP_URL_QUERY), $wiki);
        $slug = (string)($wiki['page'] ?? '');
        $file = $root . '/wiki/' . $slug . '.md';
        $meta['public'] = !str_starts_with($slug, '_') && is_file($file);
        if ($meta['public']) {
            $raw = (string)file_get_contents($file);
            $meta['title'] = preg_match('/^#\s+(.+)$/m', $raw, $heading) ? fridge_seo_text($heading[1], 100) : str_replace('-', ' ', $slug);
            $meta['description'] = 'fridge.dev developer guide: ' . fridge_seo_text(preg_replace('/^#+[^\n]*$/m', '', $raw) ?? '', 138);
        }
    }
    parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
    // Search results and personalized/action variants are crawlable, but not indexable.
    foreach (['q', 'search', 'edit_reply', 'action', 'r', 'token', 'download'] as $parameter) {
        if (isset($query[$parameter])) $meta['public'] = false;
    }
    if ($basePath !== '/wiki/' && str_contains($path, '?page=')) {
        parse_str((string)parse_url($path, PHP_URL_QUERY), $page);
        $meta['title'] .= ' — page ' . $page['page'];
        $meta['description'] .= ' Page ' . $page['page'] . '.';
    }
    return $meta;
}

function fridge_seo_template(string $template, string $root, string $uri, bool $development = false): string
{
    if (!str_contains($template, '</head>')) return $template;
    $meta = fridge_seo_metadata($root, $uri);
    $indexable = $meta['public'] && !$development && (http_response_code() ?: 200) < 400;
    // Replace only shell metadata, never page-body content or user input.
    $title = $meta['title'] === '' ? '{title} | fridge.dev' : fridge_seo_escape($meta['title'] . ($meta['path'] === '/' ? '' : ' | fridge.dev'));
    $description = $meta['description'] === '' ? '{description}' : fridge_seo_escape($meta['description']);
    $robots = $indexable ? 'index, follow, max-image-preview:large' : 'noindex, follow';
    $tags = '<title data-fridge-seo>' . $title . '</title>' . "\n"
        . '<meta data-fridge-seo name="description" content="' . $description . '">' . "\n"
        . '<meta data-fridge-seo name="robots" content="' . $robots . '">' . "\n";
    if ($meta['public']) {
        $tags .= '<link data-fridge-seo rel="canonical" href="' . fridge_seo_escape($meta['canonical']) . '">' . "\n";
        $social = ['og:site_name' => 'fridge.dev', 'og:title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'og:description' => $meta['description'], 'og:url' => $meta['canonical'],
            'og:type' => in_array($meta['type'], ['BlogPosting', 'SocialMediaPosting'], true) ? 'article' : 'website',
            'og:image' => $meta['image'], 'og:image:alt' => $meta['imageAlt'], 'og:locale' => 'en_GB'];
        foreach ($social as $property => $value) $tags .= '<meta data-fridge-seo property="' . $property . '" content="' . fridge_seo_escape($value) . '">' . "\n";
        foreach (['card' => 'summary', 'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'), 'description' => $meta['description'], 'image' => $meta['image'], 'image:alt' => $meta['imageAlt']] as $property => $value) {
            $tags .= '<meta data-fridge-seo name="twitter:' . $property . '" content="' . fridge_seo_escape($value) . '">' . "\n";
        }
    }
    if ($indexable) {
        $website = ['@type' => 'WebSite', '@id' => 'https://fridge.dev/#website', 'url' => 'https://fridge.dev/', 'name' => 'fridge.dev'];
        $page = ['@type' => $meta['type'], '@id' => $meta['canonical'] . '#page', 'url' => $meta['canonical'],
            'name' => $meta['title'], 'description' => $meta['description'], 'inLanguage' => 'en',
            'isPartOf' => ['@id' => $website['@id']], 'image' => $meta['image']];
        if (in_array($meta['type'], ['BlogPosting', 'SocialMediaPosting'], true)) {
            $page['headline'] = $meta['title'];
            $page['mainEntityOfPage'] = $meta['canonical'];
            if (isset($meta['author'])) $page['author'] = ['@type' => 'Person', 'name' => $meta['author']];
            if (isset($meta['published'])) $page['datePublished'] = $meta['published'];
            if (isset($meta['modified'])) $page['dateModified'] = $meta['modified'];
        }
        $graph = [$website, $page];
        if ($meta['path'] !== '/') {
            $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'fridge.dev', 'item' => 'https://fridge.dev/']];
            $parent = '/' . explode('/', trim($meta['path'], '/'))[0] . '/';
            if ($parent !== $meta['path'] && isset(fridge_sitemap_public_static_routes()[$parent])) {
                $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => ucfirst(trim($parent, '/')), 'item' => 'https://fridge.dev' . $parent];
            }
            $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $meta['title'], 'item' => $meta['canonical']];
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs];
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        $json = str_replace(['{title}', '{description}', '{content}', '{user_greeting}'], ['\\u007Btitle\\u007D', '\\u007Bdescription\\u007D', '\\u007Bcontent\\u007D', '\\u007Buser_greeting\\u007D'], (string)$json);
        $tags .= '<scr' . 'ipt data-fridge-seo type="application/ld+json">' . $json . '</scr' . 'ipt>' . "\n";
    }
    return preg_replace_callback('~<head\b[^>]*>(.*?)</head>~is', static function ($match) use ($tags) {
        $head = preg_replace('~<script\b[^>]*data-fridge-seo[^>]*>.*?</script>|<title\b[^>]*>.*?</title>|<meta\b[^>]*(?:name=["\'](?:description|robots|twitter:[^"\']+)["\']|property=["\']og:[^"\']+["\'])[^>]*>|<link\b[^>]*rel=["\']canonical["\'][^>]*>~is', '', $match[1]);
        return '<head>' . $head . $tags . '</head>';
    }, $template, 1) ?? $template;
}
