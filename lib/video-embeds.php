<?php
declare(strict_types=1);

function fridg3_external_video_embed_data(string $rawUrl): ?array
{
    $url = html_entity_decode(trim($rawUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    $host = preg_replace('/^(?:www\.|m\.|music\.)/', '', $host) ?? $host;
    $path = (string)($parts['path'] ?? '');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);

    $provider = '';
    $id = '';
    if ($host === 'youtu.be') {
        $id = trim($path, '/');
        $provider = 'youtube';
    } elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
        if ($path === '/watch') {
            $id = (string)($query['v'] ?? '');
        } elseif (preg_match('~^/(?:shorts|live|embed)/([^/?#]+)~', $path, $match)) {
            $id = $match[1];
        }
        $provider = 'youtube';
    } elseif ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
        if (preg_match('~/(?:video/)?([0-9]+)(?:$|/)~', $path, $match)) {
            $id = $match[1];
        }
        $provider = 'vimeo';
    } elseif ($host === 'dai.ly') {
        $id = trim($path, '/');
        $provider = 'dailymotion';
    } elseif ($host === 'dailymotion.com') {
        if (preg_match('~/(?:video|embed/video)/([a-zA-Z0-9]+)~', $path, $match)) {
            $id = $match[1];
        }
        $provider = 'dailymotion';
    }

    if ($provider === 'youtube' && preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $id)) {
        return ['provider' => 'youtube', 'title' => 'YouTube video', 'url' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id)];
    }
    if ($provider === 'vimeo' && preg_match('/^[0-9]{5,15}$/', $id)) {
        return ['provider' => 'vimeo', 'title' => 'Vimeo video', 'url' => 'https://player.vimeo.com/video/' . rawurlencode($id)];
    }
    if ($provider === 'dailymotion' && preg_match('/^[a-zA-Z0-9]{5,20}$/', $id)) {
        return ['provider' => 'dailymotion', 'title' => 'Dailymotion video', 'url' => 'https://www.dailymotion.com/embed/video/' . rawurlencode($id)];
    }

    return null;
}

function fridg3_external_video_embed_html(array $video): string
{
    return '<div class="external-video-embed" data-video-provider="' . htmlspecialchars((string)$video['provider'], ENT_QUOTES, 'UTF-8') . '">'
        . '<iframe src="' . htmlspecialchars((string)$video['url'], ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars((string)$video['title'], ENT_QUOTES, 'UTF-8') . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>'
        . '</div>';
}

function fridg3_embed_plain_video_urls_in_text(string $text): string
{
    return preg_replace_callback('~https?://[^\s<]+~iu', static function (array $match): string {
        $candidate = (string)$match[0];
        $url = rtrim($candidate, '.,!?;:)');
        $suffix = substr($candidate, strlen($url));
        $video = fridg3_external_video_embed_data($url);
        return $video === null ? $candidate : fridg3_external_video_embed_html($video) . $suffix;
    }, $text) ?? $text;
}

function fridg3_embed_plain_video_links_in_html(string $html): string
{
    $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $html;
    }

    $depth = 0;
    $voidTags = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    foreach ($parts as $index => $part) {
        if ($part === '' || $part[0] !== '<') {
            if ($depth === 0) {
                $parts[$index] = fridg3_embed_plain_video_urls_in_text($part);
            }
            continue;
        }

        if (preg_match('/^<\s*\/\s*([a-z0-9]+)/i', $part)) {
            $depth = max(0, $depth - 1);
            continue;
        }
        if (!preg_match('/^<\s*([a-z0-9]+)/i', $part, $match)) {
            continue;
        }
        $tag = strtolower($match[1]);
        if (!in_array($tag, $voidTags, true) && !preg_match('/\/\s*>$/', $part)) {
            $depth++;
        }
    }

    $result = implode('', $parts);
    return preg_replace(
        '~(<div class="external-video-embed"[^>]*>\s*<iframe\b[^>]*></iframe>\s*</div>)(?:<br\s*/?>)~i',
        '$1',
        $result
    ) ?? $result;
}
