<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();

require_once dirname(__DIR__, 2) . '/account/admin/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/feed.php';
require_once dirname(__DIR__, 2) . '/lib/guestbook.php';

account_admin_require_moderator();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $ip = trim((string)($_POST['ip'] ?? ''));
    if (!hash_equals((string)$_SESSION['csrf_token'], $token) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        $notice = '<div id="error">invalid request.</div><br>';
    } elseif (fridg3_feed_unban_ip($ip)) {
        $notice = '<div id="result">unbanned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>';
        fridg3_moderator_audit_log('unbanned IP', ['ip' => $ip]);
    } else {
        $notice = '<div id="error">could not unban that IP.</div><br>';
    }
}

function restricted_ips_rows(): array {
    $rows = [];
    foreach (fridg3_feed_load_banned_ips() as $key => $entry) {
        $ip = is_string($key) && filter_var($key, FILTER_VALIDATE_IP)
            ? $key
            : (is_array($entry) ? (string)($entry['ip'] ?? '') : (is_string($entry) ? $entry : ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
        $rows[$ip] = is_array($entry) ? array_merge($entry, ['ip' => $ip]) : ['ip' => $ip];
    }
    uasort($rows, static fn(array $a, array $b): int => strcmp((string)($b['bannedAt'] ?? ''), (string)($a['bannedAt'] ?? '')));
    return $rows;
}

function restricted_ips_live_content(string $ip): array {
    $items = [];
    $postIps = fridg3_feed_load_post_ips();
    foreach (glob(fridg3_feed_posts_dir() . '/*.txt') ?: [] as $path) {
        $postId = pathinfo(basename($path), PATHINFO_FILENAME);
        if ((string)($postIps[$postId]['ip'] ?? '') !== $ip) continue;
        $post = fridg3_feed_parse_post((string)@file_get_contents($path));
        $items[] = ['type' => 'feed_post', 'id' => $postId, 'username' => (string)($post['username'] ?? ''), 'date' => (string)($post['date'] ?? ''), 'body' => (string)($post['body'] ?? ''), 'format' => (string)($post['format'] ?? 'legacy'), 'url' => '/feed/posts/' . rawurlencode($postId), 'archived' => false];
    }
    foreach (fridg3_feed_collect_guest_replies_by_ip()[$ip] ?? [] as $reply) {
        $items[] = ['type' => 'feed_reply', 'id' => (string)($reply['postId'] ?? '') . ':' . (string)($reply['replyId'] ?? ''), 'username' => (string)($reply['username'] ?? ''), 'date' => (string)($reply['date'] ?? ''), 'body' => (string)($reply['body'] ?? ''), 'format' => (string)($reply['format'] ?? 'legacy'), 'url' => '/feed/posts/' . rawurlencode((string)($reply['postId'] ?? '')) . '#reply-' . rawurlencode((string)($reply['replyId'] ?? '')), 'archived' => false];
    }
    $guestbookFiles = glob(fridg3_guestbook_dir() . '/*.txt') ?: [];
    usort($guestbookFiles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (fridg3_guestbook_collect_entries_by_ip()[$ip] ?? [] as $entry) {
        $entryFile = (string)($entry['file'] ?? '');
        $position = array_search(fridg3_guestbook_dir() . DIRECTORY_SEPARATOR . $entryFile, $guestbookFiles, true);
        $page = $position === false ? 1 : ((int)floor($position / 10) + 1);
        $anchor = 'guestbook-entry-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($entryFile, PATHINFO_FILENAME));
        $items[] = ['type' => 'guestbook', 'id' => $entryFile, 'username' => (string)($entry['name'] ?? ''), 'date' => (string)($entry['timestamp'] ?? ''), 'body' => (string)($entry['message'] ?? ''), 'format' => 'plain', 'url' => '/guestbook?page=' . $page . '#' . $anchor, 'archived' => false];
    }
    return $items;
}

function restricted_ips_all_content(string $ip): array {
    $items = restricted_ips_live_content($ip);
    foreach ((array)(fridg3_feed_load_ban_archive()[$ip] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $type = in_array((string)($entry['type'] ?? ''), ['feed_post', 'feed_reply', 'guestbook'], true)
            ? (string)$entry['type']
            : 'feed_post';
        $items[] = ['type' => $type, 'id' => (string)($entry['id'] ?? ''), 'username' => (string)($entry['username'] ?? ''), 'date' => (string)($entry['date'] ?? ''), 'body' => (string)($entry['body'] ?? ''), 'format' => (string)($entry['format'] ?? ($type === 'guestbook' ? 'plain' : 'legacy')), 'url' => '', 'archived' => true, 'deletedAt' => (string)($entry['deletedAt'] ?? '')];
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    return $items;
}

function restricted_ips_render_content_item(array $item): string {
    $type = (string)($item['type'] ?? 'feed_post');
    $username = (string)($item['username'] ?? 'Anonymous');
    $date = (string)($item['date'] ?? '');
    $body = (string)($item['body'] ?? '');
    $format = (string)($item['format'] ?? 'legacy');
    $safeUsername = htmlspecialchars($username !== '' ? $username : 'Anonymous', ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($date !== '' ? $date : 'unknown date', ENT_QUOTES, 'UTF-8');
    $isArchived = !empty($item['archived']);
    $typeLabel = match ($type) {
        'feed_reply' => 'feed reply',
        'guestbook' => 'guestbook post',
        default => 'feed post',
    };
    $status = '<span class="restricted-ip-history-state">' . $typeLabel . ($isArchived ? ' · deleted' : '') . '</span>';
    $url = !$isArchived ? trim((string)($item['url'] ?? '')) : '';
    $open = $url !== ''
        ? '<div class="restricted-ip-history-entry restricted-ip-history-link" data-history-href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" role="link" tabindex="0">'
        : '<div class="restricted-ip-history-entry">';
    $close = '</div>';

    if ($type === 'guestbook') {
        return $open . $status
            . '<div id="post"><div id="post-header"><span id="post-username">' . $safeUsername . '</span><span id="post-date-feed">' . $safeDate . '</span></div>'
            . '<span id="post-content">' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</span></div>' . $close;
    }

    $renderedBody = fridg3_feed_render_post_body($body, $format);
    if ($type === 'feed_reply') {
        return $open . $status
            . '<div class="feed-reply"><div class="feed-reply-header"><span class="feed-reply-username"><em>' . $safeUsername . '</em></span>'
            . '<span class="feed-reply-date">' . $safeDate . '</span></div>'
            . '<div class="post-content feed-reply-body">' . $renderedBody . '</div></div>' . $close;
    }

    $postBody = $format === 'v2' ? $renderedBody : '<span id="post-content">' . $renderedBody . '</span>';
    return $open . $status
        . '<div id="post"><div id="post-header"><span id="post-username">@' . $safeUsername . '</span><span id="post-date-feed">' . $safeDate . '</span></div>'
        . $postBody . '</div>' . $close;
}

$rows = restricted_ips_rows();
$selectedIp = trim((string)($_GET['ip'] ?? ''));
$csrf = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$title = 'banned IPs';
$description = 'review content-upload restrictions and ban history.';
$content = '<h1>banned IPs</h1><h2>content-upload restrictions</h2>' . $notice;

if ($selectedIp !== '' && isset($rows[$selectedIp])) {
    $record = $rows[$selectedIp];
    $safeIp = htmlspecialchars($selectedIp, ENT_QUOTES, 'UTF-8');
    $content .= '<p><a href="/settings/restricted-ips/">&lsaquo; all banned IPs</a></p>'
        . '<div class="account-admin-card"><strong>' . $safeIp . '</strong><span>reason: '
        . htmlspecialchars(trim((string)($record['reason'] ?? '')) !== '' ? (string)$record['reason'] : 'No reason provided', ENT_QUOTES, 'UTF-8')
        . '</span><span>banned by: @' . htmlspecialchars((string)($record['bannedBy'] ?? 'unknown'), ENT_QUOTES, 'UTF-8')
        . '</span><span>banned at: ' . htmlspecialchars((string)($record['bannedAt'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') . '</span></div><br>';
    $items = restricted_ips_all_content($selectedIp);
    $content .= '<h3>all posts</h3>';
    if ($items === []) {
        $content .= '<p>no posts are stored for this IP.</p>';
    } else {
        $content .= '<div class="restricted-ip-history">';
        foreach ($items as $item) {
            $content .= restricted_ips_render_content_item($item);
        }
        $content .= '</div>';
    }
} elseif ($rows === []) {
    $content .= '<p>no IP addresses are currently banned from uploading content.</p>';
} else {
    $content .= '<div class="account-admin-grid">';
    foreach ($rows as $ip => $record) {
        $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $reason = trim((string)($record['reason'] ?? ''));
        $content .= '<div class="account-admin-card"><strong>' . $safeIp . '</strong>'
            . '<span>reason: ' . htmlspecialchars($reason !== '' ? $reason : 'No reason provided', ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>banned by: @' . htmlspecialchars((string)($record['bannedBy'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>banned at: ' . htmlspecialchars((string)($record['bannedAt'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') . '</span>'
            . '<div class="restricted-ip-actions"><a class="restricted-ip-view-button" href="/settings/restricted-ips/?ip=' . rawurlencode($ip) . '">view all posts</a>'
            . '<form method="post" action="/settings/restricted-ips/" data-no-spa="1" data-site-confirm="1" data-confirm-title="unban IP?" data-confirm-detail="this allows the IP to upload feed replies and guestbook posts again." data-confirm-text="unban" data-cancel-text="cancel" style="display:inline-block;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="ip" value="' . $safeIp . '">'
            . '<button class="danger-button" type="submit">unban</button></form></div></div>';
    }
    $content .= '</div>';
}

account_admin_render_page($title, $description, $content);
?>
