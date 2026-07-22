<?php
declare(strict_types=1);

const FRIDG3_SITE_NOTICES_FILE = 'data/etc/site-notices.json';

function fridg3_site_notices_path(string $startDir): ?string
{
    $root = fridg3_find_relative_upward($startDir, 'data/etc');
    return $root === null ? null : $root . DIRECTORY_SEPARATOR . 'site-notices.json';
}

function fridg3_site_notices_empty(): array
{
    return [
        'users' => ['banner' => null, 'popup' => null],
        'guests' => ['banner' => null, 'popup' => null],
        'pages' => [],
    ];
}

function fridg3_site_notices_text($value, int $maxLength): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength, 'UTF-8')
        : substr($value, 0, $maxLength);
}

function fridg3_site_notices_url($value): string
{
    $url = trim((string)$value);
    if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return '';
    }

    return $url;
}

function fridg3_site_notices_page_path($value): string
{
    $path = (string)(parse_url(trim((string)$value), PHP_URL_PATH) ?? '');
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        return '';
    }
    $path = preg_replace('#/+#', '/', $path);
    return $path === '/' ? '/' : rtrim($path, '/');
}

function fridg3_site_notices_normalize($value): array
{
    $notices = fridg3_site_notices_empty();
    if (!is_array($value)) {
        return $notices;
    }

    foreach (['users', 'guests'] as $audience) {
        $source = isset($value[$audience]) && is_array($value[$audience]) ? $value[$audience] : [];

        $banner = isset($source['banner']) && is_array($source['banner']) ? $source['banner'] : [];
        $bannerMessage = fridg3_site_notices_text($banner['message'] ?? '', 1000);
        if ($bannerMessage !== '') {
            $notices[$audience]['banner'] = [
                'id' => preg_match('/^[a-f0-9]{32}$/', (string)($banner['id'] ?? '')) ? (string)$banner['id'] : bin2hex(random_bytes(16)),
                'message' => $bannerMessage,
                'dismissible' => !empty($banner['dismissible']),
            ];
        }

        $popup = isset($source['popup']) && is_array($source['popup']) ? $source['popup'] : [];
        $popupMessage = fridg3_site_notices_text($popup['message'] ?? '', 2000);
        if ($popupMessage !== '') {
            $label = fridg3_site_notices_text($popup['buttonLabel'] ?? '', 80);
            $url = fridg3_site_notices_url($popup['buttonUrl'] ?? '');
            $notices[$audience]['popup'] = [
                'id' => preg_match('/^[a-f0-9]{32}$/', (string)($popup['id'] ?? '')) ? (string)$popup['id'] : bin2hex(random_bytes(16)),
                'title' => fridg3_site_notices_text($popup['title'] ?? '', 120) ?: 'notice',
                'message' => $popupMessage,
                'buttonLabel' => ($label !== '' && $url !== '') ? $label : '',
                'buttonUrl' => ($label !== '' && $url !== '') ? $url : '',
            ];
        }
    }

    $pages = isset($value['pages']) && is_array($value['pages']) ? $value['pages'] : [];
    foreach ($pages as $page) {
        if (!is_array($page)) continue;
        $id = preg_match('/^[a-f0-9]{32}$/', (string)($page['id'] ?? '')) ? (string)$page['id'] : bin2hex(random_bytes(16));
        $path = fridg3_site_notices_page_path($page['path'] ?? '');
        $audienceSource = $page['audiences'] ?? ($page['audience'] ?? []);
        if (!is_array($audienceSource)) $audienceSource = [$audienceSource];
        $audiences = array_values(array_unique(array_filter($audienceSource, static fn($item) => in_array($item, ['users', 'guests'], true))));
        $type = in_array(($page['type'] ?? ''), ['banner', 'popup'], true) ? (string)$page['type'] : '';
        $message = fridg3_site_notices_text($page['message'] ?? '', $type === 'banner' ? 1000 : 2000);
        if ($path === '' || $audiences === [] || $type === '' || $message === '') continue;
        $normalized = compact('id', 'path', 'audiences', 'type', 'message');
        if ($type === 'banner') {
            $normalized['dismissible'] = !empty($page['dismissible']);
        } else {
            $label = fridg3_site_notices_text($page['buttonLabel'] ?? '', 80);
            $url = fridg3_site_notices_url($page['buttonUrl'] ?? '');
            $normalized['title'] = fridg3_site_notices_text($page['title'] ?? '', 120) ?: 'notice';
            $normalized['buttonLabel'] = ($label !== '' && $url !== '') ? $label : '';
            $normalized['buttonUrl'] = ($label !== '' && $url !== '') ? $url : '';
        }
        $notices['pages'][] = $normalized;
    }

    return $notices;
}

function fridg3_site_notices_for_request(array $notices, string $audience, string $requestUri): array
{
    $selected = isset($notices[$audience]) && is_array($notices[$audience])
        ? $notices[$audience]
        : ['banner' => null, 'popup' => null];
    $path = fridg3_site_notices_page_path($requestUri);
    foreach (($notices['pages'] ?? []) as $page) {
        $pageAudiences = $page['audiences'] ?? (($page['audience'] ?? '') !== '' ? [$page['audience']] : []);
        if (($page['path'] ?? '') !== $path || !in_array($audience, $pageAudiences, true)) continue;
        $type = (string)($page['type'] ?? '');
        if ($type === 'banner' || $type === 'popup') $selected[$type] = $page;
    }
    return $selected;
}

function fridg3_site_notices_load(string $startDir): array
{
    $path = fridg3_site_notices_path($startDir);
    if ($path === null || !is_file($path)) {
        return fridg3_site_notices_empty();
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return fridg3_site_notices_normalize($decoded);
}

function fridg3_site_notices_save(string $startDir, array $notices): bool
{
    $path = fridg3_site_notices_path($startDir);
    if ($path === null || !is_dir(dirname($path))) {
        return false;
    }

    $encoded = json_encode(fridg3_site_notices_normalize($notices), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    $tempPath = tempnam(dirname($path), 'site-notices_');
    if ($tempPath === false || @file_put_contents($tempPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        if ($tempPath !== false) {
            @unlink($tempPath);
        }
        return false;
    }

    $existingPerms = @fileperms($path);
    if ($existingPerms !== false) {
        @chmod($tempPath, $existingPerms & 0777);
    }

    if (!@rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }

    return true;
}
