<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) $sessionBootstrapDir = dirname($sessionBootstrapDir);
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();
require_once dirname(__DIR__, 2) . '/account/admin/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/moderator-audit.php';
require_once dirname(__DIR__, 2) . '/lib/feed.php';
account_admin_require_admin();

function audit_log_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function audit_log_details(array $details): string
{
    $html = '<div class="account-admin-meta">';
    foreach ($details as $key => $value) {
        if (is_bool($value)) $value = $value ? 'yes' : 'no';
        elseif (is_array($value)) $value = implode(', ', array_map('strval', $value));
        $html .= '<span>' . audit_log_h((string)$key) . ': ' . audit_log_h((string)$value) . '</span>';
    }
    return $html . '</div>';
}
function audit_log_page_url(int $page, string $searchQuery): string
{
    $query = ['page' => $page];
    if ($searchQuery !== '') $query['q'] = $searchQuery;
    return '/settings/audit-log/?' . http_build_query($query);
}

function audit_log_search_text($value): string
{
    if (is_array($value)) return implode(' ', array_map('audit_log_search_text', $value));
    if (is_bool($value)) return $value ? 'true yes' : 'false no';
    return is_scalar($value) ? (string)$value : '';
}

function audit_log_render_post(array $snapshot, array $details, string $action, string $fallbackDate): string
{
    $body = (string)($snapshot['body'] ?? '');
    $author = (string)($snapshot['name'] ?? $details['author'] ?? $details['username'] ?? 'Anonymous');
    $date = (string)($snapshot['date'] ?? $fallbackDate);
    $safeAuthor = audit_log_h($author !== '' ? $author : 'Anonymous');
    $safeDate = audit_log_h($date !== '' ? $date : 'unknown date');

    if (str_contains($action, 'guestbook')) {
        return '<div class="restricted-ip-history-entry"><span class="restricted-ip-history-state">guestbook post</span>'
            . '<div id="post"><div id="post-header"><span id="post-username">' . $safeAuthor . '</span><span id="post-date-feed">' . $safeDate . '</span></div>'
            . '<span id="post-content">' . nl2br(audit_log_h($body)) . '</span></div></div>';
    }

    $format = (string)($snapshot['format'] ?? 'legacy');
    $renderedBody = fridg3_feed_render_post_body($body, $format);
    if (str_contains($action, 'reply')) {
        return '<div class="restricted-ip-history-entry"><span class="restricted-ip-history-state">feed reply</span>'
            . '<div class="feed-reply"><div class="feed-reply-header"><span class="feed-reply-username"><em>' . $safeAuthor . '</em></span>'
            . '<span class="feed-reply-date">' . $safeDate . '</span></div><div class="post-content feed-reply-body">' . $renderedBody . '</div></div></div>';
    }

    $postBody = $format === 'v2' ? $renderedBody : '<span id="post-content">' . $renderedBody . '</span>';
    return '<div class="restricted-ip-history-entry"><span class="restricted-ip-history-state">feed post</span>'
        . '<div id="post"><div id="post-header"><span id="post-username">@' . $safeAuthor . '</span><span id="post-date-feed">' . $safeDate . '</span></div>'
        . $postBody . '</div></div>';
}

$searchQuery = trim((string)($_GET['q'] ?? ''));
$safeSearchQuery = audit_log_h($searchQuery);
$records = fridg3_moderator_audit_load();
usort($records, static function (array $a, array $b): int {
    $aTime = strtotime((string)($a['timestamp'] ?? '')) ?: 0;
    $bTime = strtotime((string)($b['timestamp'] ?? '')) ?: 0;
    $order = $bTime <=> $aTime;
    return $order !== 0 ? $order : strnatcasecmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
});
if ($searchQuery !== '') {
    $records = array_values(array_filter($records, static function (array $record) use ($searchQuery): bool {
        $haystack = audit_log_search_text($record);
        if (stripos($haystack, $searchQuery) !== false) return true;
        $usernameQuery = ltrim($searchQuery, '@');
        return $usernameQuery !== $searchQuery && $usernameQuery !== '' && stripos($haystack, $usernameQuery) !== false;
    }));
}
$pageSize = 20;
$totalPages = max(1, (int)ceil(count($records) / $pageSize));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$records = array_slice($records, ($page - 1) * $pageSize, $pageSize);

$content = '<h1>audit log</h1><h2>moderator actions</h2>'
    . '<form id="search" action="/settings/audit-log/" method="GET">'
    . '<input id="search-box" name="q" type="search" placeholder="search IPs, usernames, actions, or content..." value="' . $safeSearchQuery . '">'
    . '<button id="search-button" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>'
    . '</form><br>';
if ($records === []) {
    $content .= $searchQuery === '' ? '<p>no moderator actions have been recorded.</p>' : '<p>no audit entries matched your search.</p>';
} else {
    $content .= '<div class="account-admin-grid">';
    foreach ($records as $record) {
        $details = is_array($record['details'] ?? null) ? $record['details'] : [];
        $action = (string)($record['action'] ?? 'unknown action');
        $timestamp = (string)($record['timestamp'] ?? 'unknown');
        $content .= '<article class="account-admin-card">'
            . '<strong>' . audit_log_h($action) . '</strong>'
            . '<span>moderator: @' . audit_log_h((string)($record['username'] ?? 'unknown')) . '</span>'
            . '<span>IP: ' . audit_log_h((string)($record['ip'] ?? 'unknown')) . '</span>'
            . '<span>timestamp: ' . audit_log_h($timestamp) . '</span>';
        if ($details !== []) $content .= '<details><summary>action details</summary>' . audit_log_details($details) . '</details>';
        if (is_array($record['before'] ?? null) && is_array($record['after'] ?? null)) {
            $content .= '<details><summary>before / after</summary><h4>before</h4>' . audit_log_render_post($record['before'], $details, $action, $timestamp)
                . '<h4>after</h4>' . audit_log_render_post($record['after'], $details, $action, $timestamp) . '</details>';
        } elseif (is_array($record['before'] ?? null)) {
            $content .= '<details><summary>deleted post</summary>' . audit_log_render_post($record['before'], $details, $action, $timestamp) . '</details>';
        }
        $content .= '</article>';
    }
    $content .= '</div>';
}
if ($totalPages > 1) {
    $content .= '<nav class="guestbook-pagination content-pagination" aria-label="audit log pages">';
    $content .= $page > 1 ? '<a class="guestbook-page-btn pagination-arrow" href="' . audit_log_page_url($page - 1, $searchQuery) . '">&lsaquo;</a>' : '<span class="guestbook-page-btn pagination-arrow disabled">&lsaquo;</span>';
    for ($i = 1; $i <= $totalPages; $i++) {
        $content .= $i === $page ? '<span class="guestbook-page-btn current" aria-current="page">' . $i . '</span>' : '<a class="guestbook-page-btn" href="' . audit_log_page_url($i, $searchQuery) . '">' . $i . '</a>';
    }
    $content .= $page < $totalPages ? '<a class="guestbook-page-btn pagination-arrow" href="' . audit_log_page_url($page + 1, $searchQuery) . '">&rsaquo;</a>' : '<span class="guestbook-page-btn pagination-arrow disabled">&rsaquo;</span>';
    $content .= '</nav>';
}

account_admin_render_page('audit log', 'review actions performed by moderator accounts.', $content);
