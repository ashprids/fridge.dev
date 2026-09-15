<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridge_start_session();

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'guestbook.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';

account_admin_require_moderator();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function settings_banned_ips_verify_admin_password(string $password, array $accountsData): bool {
    $currentUsername = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
    if ($currentUsername === '') {
        return false;
    }

    foreach ($accountsData['accounts'] as $account) {
        if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
            continue;
        }
        if (empty($account['password'])) {
            return $password === '';
        }

        $storedPassword = (string)$account['password'];
        if (password_get_info($storedPassword)['algo'] !== null) {
            return password_verify($password, $storedPassword);
        }

        return hash_equals($storedPassword, $password);
    }

    return false;
}

function settings_banned_ips_rows(array $bannedIps): array {
    $rows = [];
    foreach ($bannedIps as $key => $entry) {
        $ip = '';
        if (is_string($key) && filter_var($key, FILTER_VALIDATE_IP)) {
            $ip = $key;
        } elseif (is_string($entry) && filter_var($entry, FILTER_VALIDATE_IP)) {
            $ip = $entry;
        } elseif (is_array($entry) && isset($entry['ip']) && filter_var((string)$entry['ip'], FILTER_VALIDATE_IP)) {
            $ip = (string)$entry['ip'];
        }

        if ($ip === '') {
            continue;
        }

        $rows[$ip] = is_array($entry) ? $entry : [];
    }

    ksort($rows, SORT_NATURAL);
    return $rows;
}

function settings_banned_ips_entry_usernames(array $entry): array {
    $usernames = [];
    foreach (['usernames', 'usedUsernames', 'names'] as $key) {
        if (!isset($entry[$key]) || !is_array($entry[$key])) {
            continue;
        }
        foreach ($entry[$key] as $username) {
            $name = trim((string)$username);
            if ($name !== '') {
                $usernames[$name] = true;
            }
        }
    }

    if (isset($entry['username'])) {
        $name = trim((string)$entry['username']);
        if ($name !== '') {
            $usernames[$name] = true;
        }
    }

    return array_keys($usernames);
}

function settings_banned_ips_remove_ip(array $bannedIps, string $targetIp): array {
    $updated = [];
    $wasList = array_keys($bannedIps) === range(0, count($bannedIps) - 1);
    foreach ($bannedIps as $key => $entry) {
        $entryIp = '';
        if (is_string($key) && filter_var($key, FILTER_VALIDATE_IP)) {
            $entryIp = $key;
        } elseif (is_string($entry) && filter_var($entry, FILTER_VALIDATE_IP)) {
            $entryIp = $entry;
        } elseif (is_array($entry) && isset($entry['ip']) && filter_var((string)$entry['ip'], FILTER_VALIDATE_IP)) {
            $entryIp = (string)$entry['ip'];
        }

        if ($entryIp === $targetIp) {
            continue;
        }

        $updated[$key] = $entry;
    }

    return $wasList ? array_values($updated) : $updated;
}

function settings_guests_ip_matches_search(string $ip, array $usernames, string $searchQuery): bool {
    $query = trim($searchQuery);
    if ($query === '') {
        return true;
    }

    if (stripos($ip, $query) !== false) {
        return true;
    }

    $usernameQuery = ltrim($query, '@');
    foreach ($usernames as $username) {
        if (stripos((string)$username, $query) !== false) {
            return true;
        }
        if ($usernameQuery !== '' && stripos((string)$username, $usernameQuery) !== false) {
            return true;
        }
    }

    return false;
}

function settings_guests_ip_belongs_to_admin(string $ip, array $accountsData): bool {
    foreach ((array)($accountsData['accounts'] ?? []) as $account) {
        if (empty($account['isAdmin'])) continue;
        foreach ((array)($account['ips'] ?? []) as $knownIp) {
            if ((string)$knownIp === $ip) return true;
        }
    }
    return false;
}

function settings_guests_account_matches_search(array $account, string $searchQuery): bool {
    $query = ltrim(trim($searchQuery), '@');
    if ($query === '') return true;
    if (stripos((string)($account['username'] ?? ''), $query) !== false) return true;
    if (stripos((string)($account['name'] ?? ''), $query) !== false) return true;
    foreach ((array)($account['ips'] ?? []) as $ip) {
        if (stripos((string)$ip, $query) !== false) return true;
    }
    return false;
}

function settings_guests_ip_label(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return $ip;
    $packed = @inet_pton($ip);
    if ($packed === false) return $ip;
    $groups = array_values(unpack('n8', $packed));
    return ':' . implode(':', array_map(static fn(int $group): string => dechex($group), array_slice($groups, -4)));
}

function settings_guests_ip_control(string $ip, bool $isAdmin, bool $inlineCode = false): string {
    $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars(settings_guests_ip_label($ip), ENT_QUOTES, 'UTF-8');
    $tooltip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? ' data-tooltip="' . $safeIp . '"' : '';
    $tag = $inlineCode ? 'code' : 'span';
    $class = $inlineCode ? 'account-ip-value guest-ip-address' : 'guest-ip-address';
    return '<' . $tag . ' class="' . $class . '" tabindex="0" role="button" data-guest-ip-actions="1" data-ip="' . $safeIp . '" data-ip-admin="' . ($isAdmin ? '1' : '0') . '"' . $tooltip . '>' . $label . '</' . $tag . '>';
}

function settings_guests_account_pagination(string $username, int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) return '';
    $url = static fn(int $page): string => '/settings/guests/?account=' . rawurlencode($username) . '&amp;page=' . $page;
    $items = $currentPage > 1
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . $url($currentPage - 1) . '" aria-label="previous page">&lsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&lsaquo;</span>';
    $pages = array_unique(array_filter([1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages], static fn(int $page): bool => $page >= 1 && $page <= $totalPages));
    sort($pages);
    $previous = 0;
    foreach ($pages as $page) {
        if ($previous > 0 && $page - $previous > 1) $items .= '<span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>';
        $class = 'guestbook-page-btn' . ($page === $currentPage ? ' current' : '');
        $items .= $page === $currentPage
            ? '<span class="' . $class . '" aria-current="page">' . $page . '</span>'
            : '<a class="' . $class . '" href="' . $url($page) . '">' . $page . '</a>';
        $previous = $page;
    }
    $items .= $currentPage < $totalPages
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . $url($currentPage + 1) . '" aria-label="next page">&rsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&rsaquo;</span>';
    return '<nav class="guestbook-pagination content-pagination" aria-label="account activity pages">' . $items . '</nav>';
}

function settings_guests_account_by_username(array $accounts, string $username): ?array {
    foreach ($accounts as $account) if (strcasecmp((string)($account['username'] ?? ''), $username) === 0) return $account;
    return null;
}

function settings_guests_feed_reply_matches_ip(string $postId, string $replyId, string $ip): bool {
    foreach (fridge_feed_load_replies($postId) as $reply) {
        if (
            (string)($reply['id'] ?? '') === $replyId
            && ($reply['isGuest'] ?? false) === true
            && (string)($reply['ip'] ?? '') === $ip
        ) {
            return true;
        }
    }
    return false;
}

function settings_guests_latest_activity(array $guestReplies, array $guestbookEntries): int {
    $latest = 0;
    foreach ($guestReplies as $reply) {
        $timestamp = strtotime((string)($reply['date'] ?? ''));
        if ($timestamp !== false) $latest = max($latest, $timestamp);
    }
    foreach ($guestbookEntries as $entry) {
        $timestamp = strtotime((string)($entry['timestamp'] ?? ''));
        if ($timestamp !== false) $latest = max($latest, $timestamp);
    }
    return $latest;
}

function settings_guests_pagination(int $currentPage, int $totalPages, string $searchQuery): string {
    if ($totalPages <= 1) return '';
    $url = static function (int $page) use ($searchQuery): string {
        $query = ['page' => $page];
        if ($searchQuery !== '') $query['q'] = $searchQuery;
        return '/settings/guests/?' . http_build_query($query);
    };
    $items = $currentPage > 1
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . htmlspecialchars($url($currentPage - 1), ENT_QUOTES, 'UTF-8') . '" aria-label="previous page">&lsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&lsaquo;</span>';
    $pages = array_unique(array_filter([1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages], static fn(int $page): bool => $page >= 1 && $page <= $totalPages));
    sort($pages);
    $previous = 0;
    foreach ($pages as $page) {
        if ($previous > 0 && $page - $previous > 1) $items .= '<span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>';
        if ($page === $currentPage) {
            $items .= '<span class="guestbook-page-btn current" aria-current="page">' . $page . '</span>';
        } else {
            $items .= '<a class="guestbook-page-btn" href="' . htmlspecialchars($url($page), ENT_QUOTES, 'UTF-8') . '" aria-label="page ' . $page . '">' . $page . '</a>';
        }
        $previous = $page;
    }
    $items .= $currentPage < $totalPages
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . htmlspecialchars($url($currentPage + 1), ENT_QUOTES, 'UTF-8') . '" aria-label="next page">&rsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&rsaquo;</span>';
    return '<nav class="guestbook-pagination content-pagination" aria-label="guest management pages">' . $items . '</nav>';
}

$title = 'manage users';
$description = 'review account users and manage guest feed replies, guestbook posts, and IP moderation.';
$noticeHtml = '';
$accountsData = account_admin_load_accounts();
$currentUserIsAdmin = !empty($_SESSION['user']['isAdmin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $ip = trim((string)($_POST['ip'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        $noticeHtml = '<div id="error">invalid request. please try again.</div><br>';
    } elseif ($action === 'ban_account' || $action === 'unban_account') {
        $targetUsername = trim((string)($_POST['username'] ?? ''));
        $target = settings_guests_account_by_username((array)($accountsData['accounts'] ?? []), $targetUsername);
        if ($target === null || !empty($target['isAdmin']) || !empty($target['isModerator'])) {
            $noticeHtml = '<div id="error">that account cannot be banned.</div><br>';
        } else {
            $banned = $action === 'ban_account';
            $saved = fridge_feed_set_account_banned($targetUsername, $banned, (string)$_SESSION['user']['username'], (string)($_POST['ban_reason'] ?? ''));
            $noticeHtml = $saved ? '<div id="result">' . ($banned ? 'banned ' : 'unbanned ') . '@' . htmlspecialchars($targetUsername, ENT_QUOTES, 'UTF-8') . '.</div><br>' : '<div id="error">failed to update the account ban.</div><br>';
            if ($saved) {
                fridge_moderator_audit_log($banned ? 'banned account' : 'unbanned account', ['username' => $targetUsername]);
                $accountsData = account_admin_load_accounts();
            }
        }
    } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $noticeHtml = '<div id="error">invalid IP address.</div><br>';
    } elseif (!$currentUserIsAdmin && settings_guests_ip_belongs_to_admin($ip, $accountsData)) {
        $noticeHtml = '<div id="error">moderators cannot manage an IP associated with an admin account.</div><br>';
    } elseif ($action === 'purge_content' || $action === 'purge_replies' || $action === 'purge_all_ip_content') {
        if (!settings_banned_ips_verify_admin_password((string)($_POST['admin_password'] ?? ''), $accountsData)) {
            $noticeHtml = '<div id="error">password did not match. purge cancelled.</div><br>';
        } else {
            $feedResult = $action === 'purge_all_ip_content'
                ? fridge_feed_purge_all_content_by_ip($ip)
                : fridge_feed_purge_guest_replies_by_ip($ip);
            $guestbookResult = fridge_guestbook_purge_entries_by_ip($ip);
            $deleted = (int)$feedResult['deleted'] + (int)$guestbookResult['deleted'];
            $failed = (int)$feedResult['failed'] + (int)$guestbookResult['failed'];
            if ($failed > 0) {
                $noticeHtml = '<div id="error">deleted ' . $deleted . ' guest item(s)'
                    . ', but ' . $failed . ' data file(s)'
                    . ' failed. check file permissions.</div><br>';
            } elseif ($deleted === 0) {
                $noticeHtml = '<div id="result">no content found for this IP.</div><br>';
            } else {
                $noticeHtml = '<div id="result">deleted ' . $deleted . ' item(s)'
                    . ' from ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>';
            }
            fridge_moderator_audit_log('purged IP content', ['ip' => $ip, 'deleted' => $deleted, 'failed' => $failed]);
        }
    } elseif ($action === 'hard_ban') {
        if (!$currentUserIsAdmin) {
            $noticeHtml = '<div id="error">only admins can hard-ban an IP.</div><br>';
        } else {
            $hardBans = fridge_hard_ban_load();
            if (!fridge_hard_ban_list_contains($hardBans, $ip)) $hardBans[] = $ip;
            $saved = fridge_hard_ban_admin_save($hardBans);
            $noticeHtml = $saved ? '<div id="result">hard-banned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>' : '<div id="error">failed to hard-ban IP.</div><br>';
            if ($saved) fridge_moderator_audit_log('hard-banned IP', ['ip' => $ip]);
        }
    } elseif ($action === 'delete_feed_reply') {
        $postId = (string)($_POST['post_id'] ?? '');
        $replyId = (string)($_POST['reply_id'] ?? '');
        $deletedReply = null;
        foreach (fridge_feed_load_replies($postId) as $reply) if ((string)($reply['id'] ?? '') === $replyId) $deletedReply = $reply;
        $deletedOk = (
            settings_guests_feed_reply_matches_ip($postId, $replyId, $ip)
            && fridge_feed_delete_reply($postId, $replyId)
        );
        $noticeHtml = $deletedOk ? '<div id="result">guest feed reply deleted.</div><br>' : '<div id="error">failed to delete that guest feed reply.</div><br>';
        if ($deletedOk) fridge_moderator_audit_log('deleted feed reply', ['postId' => $postId, 'replyId' => $replyId, 'ip' => $ip, 'author' => (string)($deletedReply['username'] ?? '')], ['body' => (string)($deletedReply['body'] ?? '')]);
    } elseif ($action === 'delete_guestbook_entry') {
        $filename = (string)($_POST['guestbook_file'] ?? '');
        $deletedEntry = fridge_guestbook_load_entry($filename);
        $deletedOk = fridge_guestbook_delete_entry($filename, $ip);
        $noticeHtml = $deletedOk ? '<div id="result">guestbook post deleted.</div><br>' : '<div id="error">failed to delete that guestbook post.</div><br>';
        if ($deletedOk) fridge_moderator_audit_log('deleted guestbook post', ['file' => $filename, 'ip' => $ip, 'author' => (string)($deletedEntry['name'] ?? '')], ['name' => (string)($deletedEntry['name'] ?? ''), 'body' => (string)($deletedEntry['message'] ?? '')]);
    } elseif ($action === 'ban') {
        $username = trim((string)($_POST['username'] ?? 'Anonymous'));
        $banned = fridge_feed_ban_guest_ip($ip, (string)$_SESSION['user']['username'], $username, (string)($_POST['ban_reason'] ?? ''));
        $noticeHtml = $banned ? '<div id="result">banned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ' from feed and guestbook posting.</div><br>' : '<div id="error">failed to ban IP.</div><br>';
        if ($banned) fridge_moderator_audit_log('banned IP', ['ip' => $ip, 'username' => $username, 'reason' => (string)($_POST['ban_reason'] ?? '')]);
    } elseif ($action === 'unban') {
        if (fridge_feed_ip_belongs_to_banned_account($ip)) {
            $noticeHtml = '<div id="error">unban the associated account before unbanning this IP.</div><br>';
        } elseif (fridge_feed_banned_ip_record($ip) === null) {
            $noticeHtml = '<div id="result">that IP is not currently banned.</div><br>';
        } elseif (!fridge_feed_unban_ip($ip)) {
            $noticeHtml = '<div id="error">failed to unban IP. check file permissions.</div><br>';
        } else {
            $noticeHtml = '<div id="result">unbanned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>';
            fridge_moderator_audit_log('unbanned IP', ['ip' => $ip]);
        }
    }
}

$bannedIps = fridge_feed_load_banned_ips();
$rows = settings_banned_ips_rows($bannedIps);
$guestUsernamesByIp = fridge_feed_collect_guest_usernames_by_ip();
$guestRepliesByIp = fridge_feed_collect_guest_replies_by_ip();
$guestbookEntriesByIp = fridge_guestbook_collect_entries_by_ip();
$accountView = trim((string)($_GET['account'] ?? ''));
$accountIpsView = trim((string)($_GET['account_ips'] ?? ''));
$sharedIpView = trim((string)($_GET['shared_ip'] ?? ''));

if ($accountView !== '') {
    $account = settings_guests_account_by_username((array)($accountsData['accounts'] ?? []), $accountView);
    if ($account === null) { http_response_code(404); account_admin_render_page('user not found', 'account activity', '<h1>user not found</h1>'); exit; }
    $username = (string)$account['username'];
    $knownIps = array_fill_keys(array_map('strval', (array)($account['ips'] ?? [])), true);
    $items = [];
    foreach (glob(fridge_feed_posts_dir() . DIRECTORY_SEPARATOR . '*.*') ?: [] as $path) {
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') continue;
        $post = fridge_feed_parse_post((string)@file_get_contents($path));
        if (strcasecmp((string)($post['username'] ?? ''), $username) !== 0) continue;
        $items[] = ['type' => 'feed post', 'author' => $username, 'date' => (string)($post['date'] ?? ''), 'body' => (string)($post['body'] ?? ''), 'format' => (string)($post['format'] ?? 'legacy'), 'url' => '/feed/posts/' . rawurlencode(pathinfo($path, PATHINFO_FILENAME))];
    }
    foreach (glob(fridge_feed_replies_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $postId = pathinfo($path, PATHINFO_FILENAME);
        $postReplies = fridge_feed_load_replies($postId);
        $replyAuthors = [];
        foreach ($postReplies as $candidateReply) $replyAuthors[(string)($candidateReply['id'] ?? '')] = (string)($candidateReply['username'] ?? 'Anonymous');
        foreach ($postReplies as $reply) if (strcasecmp((string)($reply['username'] ?? ''), $username) === 0) {
            $items[] = ['type' => 'feed reply', 'author' => $username, 'replyTo' => $replyAuthors[(string)($reply['parentId'] ?? '')] ?? '', 'date' => (string)($reply['date'] ?? ''), 'body' => (string)($reply['body'] ?? ''), 'format' => fridge_feed_reply_format($reply), 'url' => '/feed/posts/' . rawurlencode($postId)];
        }
    }
    foreach ($guestbookEntriesByIp as $entryIp => $entries) if (isset($knownIps[$entryIp])) foreach ($entries as $entry) {
        $items[] = ['type' => 'guestbook post', 'author' => (string)($entry['name'] ?? 'Anonymous'), 'date' => (string)($entry['timestamp'] ?? ''), 'body' => (string)($entry['message'] ?? ''), 'url' => '/guestbook'];
    }
    usort($items, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
    $pageSize = 25;
    $totalPages = max(1, (int)ceil(count($items) / $pageSize));
    $currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $pagedItems = array_slice($items, ($currentPage - 1) * $pageSize, $pageSize);
    $content = '<div data-guest-ip-menu-config data-csrf="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '"></div><h1>@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ' activity</h1><p><a href="/settings/guests/">&larr; manage users</a></p>';
    if ($items === []) $content .= '<p>no feed or guestbook posts found.</p>';
    foreach ($pagedItems as $item) {
        $bodyHtml = isset($item['format']) ? fridge_feed_render_post_body($item['body'], $item['format']) : nl2br(htmlspecialchars($item['body'], ENT_QUOTES, 'UTF-8'));
        $safeAuthor = htmlspecialchars((string)$item['author'], ENT_QUOTES, 'UTF-8');
        $displayDate = $item['type'] === 'guestbook post'
            ? fridge_guestbook_relative_time((string)$item['date'])
            : fridge_feed_humanize_datetime((string)$item['date']);
        $safeDate = htmlspecialchars($displayDate !== '' ? $displayDate : (string)$item['date'], ENT_QUOTES, 'UTF-8');
        $view = '<a class="site-icon-button" href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '" data-tooltip="view post" aria-label="view post"><i class="fa-solid fa-eye"></i></a>';
        if ($item['type'] === 'feed reply') {
            $replyingTo = trim((string)($item['replyTo'] ?? ''));
            $replyingToHtml = $replyingTo !== '' ? ' <em class="guest-activity-replying-to">(replying to ' . htmlspecialchars($replyingTo, ENT_QUOTES, 'UTF-8') . ')</em>' : '';
            $content .= '<div class="feed-reply guest-activity-item"><div class="feed-reply-header"><span class="feed-reply-username">@' . $safeAuthor . $replyingToHtml . '</span><span class="feed-reply-date" data-exact-datetime="' . htmlspecialchars((string)$item['date'], ENT_QUOTES, 'UTF-8') . '">' . $safeDate . ' ' . $view . '</span></div><div class="post-content feed-reply-body">' . $bodyHtml . '</div></div>';
        } else {
            $content .= '<div class="' . ($item['type'] === 'guestbook post' ? 'guestbook-entry-anchor ' : '') . 'guest-activity-item"><div id="post" class="' . ($item['type'] === 'feed post' ? 'feed-post' : '') . '"><div id="post-header"><span id="post-username">' . ($item['type'] === 'feed post' ? '@' : '') . $safeAuthor . '</span><div class="guestbook-post-actions"><span id="post-date-feed" data-exact-datetime="' . htmlspecialchars((string)$item['date'], ENT_QUOTES, 'UTF-8') . '">' . $safeDate . '</span>' . $view . '</div></div><div id="post-content" data-rendered-content="1">' . $bodyHtml . '</div></div></div>';
        }
    }
    $content .= settings_guests_account_pagination($username, $currentPage, $totalPages);
    account_admin_render_page('@' . $username . ' activity', 'feed and guestbook activity for this account.', $content); exit;
}

if ($accountIpsView !== '') {
    $account = settings_guests_account_by_username((array)($accountsData['accounts'] ?? []), $accountIpsView);
    if ($account === null) { http_response_code(404); account_admin_render_page('user not found', 'recorded IPs', '<h1>user not found</h1>'); exit; }
    $ips = array_reverse(array_values(array_filter(array_map('strval', (array)($account['ips'] ?? [])), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false)));
    $content = '<div data-guest-ip-menu-config data-csrf="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '"></div><h1>@' . htmlspecialchars((string)$account['username'], ENT_QUOTES, 'UTF-8') . ' recorded IPs</h1><p><a href="/settings/guests/">&larr; manage users</a></p><div class="account-ip-list">';
    foreach ($ips as $ip) $content .= settings_guests_ip_control($ip, $currentUserIsAdmin, true);
    $content .= '</div>';
    account_admin_render_page('recorded IPs', 'all recorded IPs for this account.', $content); exit;
}

if ($sharedIpView !== '' && filter_var($sharedIpView, FILTER_VALIDATE_IP)) {
    $names = [];
    foreach ((array)($accountsData['accounts'] ?? []) as $account) if (in_array($sharedIpView, array_map('strval', (array)($account['ips'] ?? [])), true)) $names['@' . (string)$account['username']] = true;
    foreach (($guestUsernamesByIp[$sharedIpView] ?? []) as $name) $names[(string)$name . ' (guest)'] = true;
    foreach (($guestbookEntriesByIp[$sharedIpView] ?? []) as $entry) $names[(string)($entry['name'] ?? 'Anonymous') . ' (guestbook)'] = true;
    $content = '<div data-guest-ip-menu-config data-csrf="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '"></div><h1>users sharing ' . settings_guests_ip_control($sharedIpView, $currentUserIsAdmin) . '</h1><p><a href="/settings/guests/">&larr; manage users</a></p>';
    $content .= $names === [] ? '<p>no users found.</p>' : '<ul><li>' . implode('</li><li>', array_map(static fn(string $name): string => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), array_keys($names))) . '</li></ul>';
    account_admin_render_page('shared IP users', 'users and guests recorded on this IP.', $content); exit;
}
$allIps = array_fill_keys(array_merge(array_keys($rows), array_keys($guestRepliesByIp), array_keys($guestbookEntriesByIp)), true);
$allIps = array_keys($allIps);
$csrf = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$safeSearchQuery = htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
$matchingIps = [];
foreach ($allIps as $candidateIp) {
    $candidateUsernames = [];
    foreach (settings_banned_ips_entry_usernames($rows[$candidateIp] ?? []) as $name) $candidateUsernames[(string)$name] = true;
    foreach (($guestUsernamesByIp[$candidateIp] ?? []) as $name) $candidateUsernames[(string)$name] = true;
    foreach (($guestbookEntriesByIp[$candidateIp] ?? []) as $entry) {
        $name = trim((string)($entry['name'] ?? ''));
        if ($name !== '') $candidateUsernames[$name] = true;
    }
    if (settings_guests_ip_matches_search($candidateIp, array_keys($candidateUsernames), $searchQuery)) $matchingIps[] = $candidateIp;
}
usort($matchingIps, static function (string $a, string $b) use ($guestRepliesByIp, $guestbookEntriesByIp): int {
    $aLatest = settings_guests_latest_activity($guestRepliesByIp[$a] ?? [], $guestbookEntriesByIp[$a] ?? []);
    $bLatest = settings_guests_latest_activity($guestRepliesByIp[$b] ?? [], $guestbookEntriesByIp[$b] ?? []);
    $activityOrder = $bLatest <=> $aLatest;
    return $activityOrder !== 0 ? $activityOrder : strnatcasecmp($a, $b);
});
$pageSize = 10;
$totalPages = max(1, (int)ceil(count($matchingIps) / $pageSize));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$pagedIps = array_slice($matchingIps, ($currentPage - 1) * $pageSize, $pageSize);
$formQuery = [];
if ($searchQuery !== '') $formQuery['q'] = $searchQuery;
if ($currentPage > 1) $formQuery['page'] = $currentPage;
$formAction = '/settings/guests/' . ($formQuery !== [] ? '?' . http_build_query($formQuery) : '');
$safeFormAction = htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8');
$matchingAccounts = array_values(array_filter((array)($accountsData['accounts'] ?? []), static function (array $account) use ($searchQuery): bool {
    return settings_guests_account_matches_search($account, $searchQuery);
}));
usort($matchingAccounts, static function (array $a, array $b): int {
    $rank = static function (array $account): int {
        $username = strtolower((string)($account['username'] ?? ''));
        if ($username === 'admin') return 0;
        if ($username === 'fridge') return 1;
        if (!empty($account['accountBanned'])) return 5;
        if (!empty($account['isAdmin'])) return 2;
        if (!empty($account['isModerator'])) return 3;
        return 4;
    };
    $rankOrder = $rank($a) <=> $rank($b);
    return $rankOrder !== 0 ? $rankOrder : strnatcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
});

$content = '<div data-guest-ip-menu-config data-csrf="' . $csrf . '" data-is-admin="' . ($currentUserIsAdmin ? '1' : '0') . '"></div><h1>manage users</h1>'
    . $noticeHtml
    . '<form id="search" action="/settings/guests/" method="GET">'
    . '<input id="search-box" name="q" type="text" placeholder="search users, names, or IPs..." value="' . $safeSearchQuery . '">'
    . '<button id="search-button" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>'
    . '</form>'
    . '<br>';

$content .= '<h2>account users</h2>';
if ($matchingAccounts === []) {
    $content .= '<p>no account users matched your search.</p>';
} else {
    $content .= '<div class="account-admin-grid guest-management-grid">';
    foreach ($matchingAccounts as $account) {
        $username = (string)($account['username'] ?? 'unknown');
        $name = (string)($account['name'] ?? '');
        $isAccountAdmin = !empty($account['isAdmin']);
        $isAccountModerator = !empty($account['isModerator']);
        $ips = array_values(array_filter(array_map('strval', (array)($account['ips'] ?? [])), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        $roles = $isAccountAdmin ? 'admin' : ($isAccountModerator ? 'moderator' : 'account user');
        $isAccountBanned = !empty($account['accountBanned']);
        $newestIps = array_slice(array_reverse($ips), 0, 100);
        $ipHtml = $ips === []
            ? '<span>no recorded IPs</span>'
            : '<details class="account-ip-disclosure"><summary>' . count($ips) . ' recorded IP' . (count($ips) === 1 ? '' : 's') . '</summary><div class="account-ip-list">'
                . implode('', array_map(fn(string $ip): string => settings_guests_ip_control($ip, $currentUserIsAdmin, true), $newestIps))
                . '<a href="/settings/guests/?account_ips=' . rawurlencode($username) . '">show all</a>'
                . '</div></details>';
        $content .= '<div class="account-admin-card guest-account-card" data-account-href="/settings/guests/?account=' . rawurlencode($username) . '" tabindex="0">'
            . '<strong><a href="/settings/guests/?account=' . rawurlencode($username) . '">@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</a></strong>'
            . '<span>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>' . htmlspecialchars($roles, ENT_QUOTES, 'UTF-8') . ($isAccountAdmin && !$currentUserIsAdmin ? ' &middot; protected from moderator actions' : '') . '</span>'
            . $ipHtml
            . (!$isAccountAdmin && !$isAccountModerator ? '<form class="guest-account-ban-form" method="post" action="/settings/guests/" data-no-spa="1" data-site-confirm="1" ' . (!$isAccountBanned ? 'data-ban-reason-prompt="1" ' : '') . 'data-confirm-title="' . ($isAccountBanned ? 'unban' : 'ban') . ' account?" data-confirm-detail="this updates the account and all associated IP addresses." data-confirm-text="' . ($isAccountBanned ? 'unban' : 'ban') . '" data-cancel-text="cancel"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="' . ($isAccountBanned ? 'unban_account' : 'ban_account') . '"><input type="hidden" name="username" value="' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '"><button class="danger-button" type="submit">' . ($isAccountBanned ? 'unban' : 'ban') . '</button></form>' : '')
            . '</div>';
    }
    $content .= '</div>';
}

$content .= '<br><hr><br><h2>guest feed and guestbook moderation</h2>';

if (empty($matchingIps)) {
    $content .= $searchQuery === ''
        ? '<p>no guest content or banned IP addresses.</p>'
        : '<p>no guest IPs or usernames matched your search.</p>';
} else {
    $content .= '<div class="account-admin-grid guest-management-grid">';
    foreach ($pagedIps as $ip) {
        $entry = $rows[$ip] ?? [];
        $isBanned = array_key_exists($ip, $rows);
        $isAdminIpProtected = !$currentUserIsAdmin && settings_guests_ip_belongs_to_admin($ip, $accountsData);
        $guestReplies = $guestRepliesByIp[$ip] ?? [];
        $guestbookEntries = $guestbookEntriesByIp[$ip] ?? [];
        $usernames = [];
        foreach (settings_banned_ips_entry_usernames($entry) as $name) {
            $usernames[$name] = true;
        }
        foreach (($guestUsernamesByIp[$ip] ?? []) as $name) {
            $usernames[(string)$name] = true;
        }
        foreach ($guestbookEntries as $entry) {
            $name = trim((string)($entry['name'] ?? ''));
            if ($name !== '') {
                $usernames[$name] = true;
            }
        }
        $usernameList = array_keys($usernames);
        sort($usernameList, SORT_NATURAL | SORT_FLAG_CASE);
        $usernameText = empty($usernameList)
            ? 'no usernames recorded'
            : implode(', ', array_map(static function ($name) {
                return htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
            }, $usernameList));

        $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $replyCount = count($guestReplies);
        $guestbookCount = count($guestbookEntries);
        $replyLabel = ($replyCount === 1 ? '1 feed reply' : $replyCount . ' feed replies')
            . ' · '
            . ($guestbookCount === 1 ? '1 guestbook post' : $guestbookCount . ' guestbook posts');
        $content .= '<div class="account-admin-card">'
            . '<strong>' . settings_guests_ip_control($ip, $currentUserIsAdmin) . '</strong>'
            . '<span>' . ($isBanned ? 'banned &middot; ' : '') . htmlspecialchars($replyLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>usernames: ' . $usernameText . '</span>'
            . ($isAdminIpProtected ? '<span><em>protected from moderator actions because this IP is associated with an admin account</em></span>' : '')
            . '<span class="account-admin-meta">';
        if (!$isAdminIpProtected) {
            $content .= '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-admin-password-confirm="1" data-confirm-title="purge guest content from this IP?" data-confirm-detail="this deletes feed replies and guestbook posts from this IP. it does not ban or unban the IP." data-confirm-text="purge content" data-cancel-text="cancel" data-password-title="confirm guest purge" data-password-detail="enter your password to purge all guest content from this IP." style="display:inline-block; margin-right: 8px;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="purge_content">'
            . '<input type="hidden" name="ip" value="' . $safeIp . '">'
            . '<button class="danger-button" type="submit">purge content</button>'
            . '</form>';
        if (!$isBanned) {
            $banUsername = $usernameList[0] ?? 'Anonymous';
            $content .= '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-ban-reason-prompt="1" data-confirm-title="ban IP?" data-confirm-detail="this blocks new feed replies and guestbook posts from this IP." data-confirm-text="continue" data-cancel-text="cancel" style="display:inline-block;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="ban">'
                . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                . '<input type="hidden" name="username" value="' . htmlspecialchars((string)$banUsername, ENT_QUOTES, 'UTF-8') . '">'
                . '<button class="danger-button" type="submit">ban</button>'
                . '</form>';
        } else {
            $content .= '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-confirm-title="unban IP?" data-confirm-detail="this allows new feed replies and guestbook posts from this IP again." data-confirm-text="unban" data-cancel-text="cancel" style="display:inline-block;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="unban">'
                . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                . '<button id="form-button" type="submit">unban</button>'
                . '</form>';
        }
        if ($currentUserIsAdmin) {
            $content .= '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-confirm-title="hard-ban IP?" data-confirm-detail="this adds the address to the site-wide hard-ban system." data-confirm-text="hard-ban" data-cancel-text="cancel" style="display:inline-block; margin-left:8px;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="hard_ban"><input type="hidden" name="ip" value="' . $safeIp . '">'
                . '<button class="danger-button" type="submit">hard-ban</button></form>';
        }
        }
        $content .= '</span>';

        if (!empty($guestReplies)) {
            $content .= '<h4 style="margin-top: 12px;">feed replies</h4>';
            $content .= '<div class="feed-replies-list guest-management-replies" style="margin-top: 12px;">';
            foreach ($guestReplies as $reply) {
                $replyUser = htmlspecialchars((string)($reply['username'] ?? 'Anonymous'), ENT_QUOTES, 'UTF-8');
                $replyDateRaw = (string)($reply['date'] ?? '');
                $replyDate = htmlspecialchars($replyDateRaw !== '' ? fridge_feed_humanize_datetime($replyDateRaw) : 'unknown date', ENT_QUOTES, 'UTF-8');
                $postId = (string)($reply['postId'] ?? '');
                $postUrl = '/feed/posts/' . rawurlencode($postId);
                $replyBody = htmlspecialchars((string)($reply['body'] ?? ''), ENT_QUOTES, 'UTF-8');
                $content .= '<div class="feed-reply">'
                    . '<div class="feed-reply-header">'
                    . '<span class="feed-reply-username"><em>' . $replyUser . '</em></span>'
                    . '<span class="feed-reply-date" data-exact-datetime="' . htmlspecialchars($replyDateRaw, ENT_QUOTES, 'UTF-8') . '">' . $replyDate . ' <a class="site-icon-button" href="' . $postUrl . '" data-tooltip="view post" aria-label="view post"><i class="fa-solid fa-eye"></i></a></span>'
                    . '</div>'
                    . '<div class="post-content feed-reply-body">' . $replyBody . '</div>'
                    . '</div>';
            }
            $content .= '</div>';
        }

        if (!empty($guestbookEntries)) {
            $content .= '<h4 style="margin-top: 12px;">guestbook posts</h4>'
                . '<div class="feed-replies-list guest-management-replies" style="margin-top: 12px;">';
            foreach ($guestbookEntries as $entry) {
                $entryName = htmlspecialchars((string)($entry['name'] ?? 'Anonymous'), ENT_QUOTES, 'UTF-8');
                $entryDateRaw = (string)($entry['timestamp'] ?? '');
                $entryDate = htmlspecialchars($entryDateRaw !== '' ? fridge_guestbook_relative_time($entryDateRaw) : 'unknown date', ENT_QUOTES, 'UTF-8');
                $entryBody = nl2br(htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8'));
                $content .= '<div class="feed-reply">'
                    . '<div class="feed-reply-header">'
                    . '<span class="feed-reply-username"><em>' . $entryName . '</em></span>'
                    . '<span class="feed-reply-date" data-exact-datetime="' . htmlspecialchars($entryDateRaw, ENT_QUOTES, 'UTF-8') . '">' . $entryDate . ' <a class="site-icon-button" href="/guestbook/" data-tooltip="view post" aria-label="view post"><i class="fa-solid fa-eye"></i></a></span>'
                    . '</div>'
                    . '<div class="post-content feed-reply-body">' . $entryBody . '</div>'
                    . '</div>';
            }
            $content .= '</div>';
        }

        if (empty($guestReplies) && empty($guestbookEntries)) {
            $content .= '<span style="color: var(--subtle);">no guest content currently stored for this IP.</span>';
        }

        $content .= '</div>';
    }
    $content .= '</div>';
    $content .= settings_guests_pagination($currentPage, $totalPages, $searchQuery);
}

account_admin_render_page($title, $description, $content);
?>
