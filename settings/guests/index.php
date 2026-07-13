<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'guestbook.php';

account_admin_require_admin();

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

function settings_guests_feed_reply_matches_ip(string $postId, string $replyId, string $ip): bool {
    foreach (fridg3_feed_load_replies($postId) as $reply) {
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

$title = 'manage guests';
$description = 'manage guest feed replies, guestbook posts, and IP moderation.';
$noticeHtml = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $ip = trim((string)($_POST['ip'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        $noticeHtml = '<div id="error">invalid request. please try again.</div><br>';
    } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $noticeHtml = '<div id="error">invalid IP address.</div><br>';
    } elseif ($action === 'purge_content' || $action === 'purge_replies') {
        $accountsData = account_admin_load_accounts();
        if (!settings_banned_ips_verify_admin_password((string)($_POST['admin_password'] ?? ''), $accountsData)) {
            $noticeHtml = '<div id="error">admin password did not match. purge cancelled.</div><br>';
        } else {
            $feedResult = fridg3_feed_purge_guest_replies_by_ip($ip);
            $guestbookResult = fridg3_guestbook_purge_entries_by_ip($ip);
            $deleted = (int)$feedResult['deleted'] + (int)$guestbookResult['deleted'];
            $failed = (int)$feedResult['failed'] + (int)$guestbookResult['failed'];
            if ($failed > 0) {
                $noticeHtml = '<div id="error">deleted ' . $deleted . ' guest item(s)'
                    . ', but ' . $failed . ' data file(s)'
                    . ' failed. check file permissions.</div><br>';
            } elseif ($deleted === 0) {
                $noticeHtml = '<div id="result">no guest content found for this IP.</div><br>';
            } else {
                $noticeHtml = '<div id="result">deleted ' . $deleted . ' guest item(s)'
                    . ' from ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>';
            }
        }
    } elseif ($action === 'delete_feed_reply') {
        $postId = (string)($_POST['post_id'] ?? '');
        $replyId = (string)($_POST['reply_id'] ?? '');
        $noticeHtml = (
            settings_guests_feed_reply_matches_ip($postId, $replyId, $ip)
            && fridg3_feed_delete_reply($postId, $replyId)
        )
            ? '<div id="result">guest feed reply deleted.</div><br>'
            : '<div id="error">failed to delete that guest feed reply.</div><br>';
    } elseif ($action === 'delete_guestbook_entry') {
        $filename = (string)($_POST['guestbook_file'] ?? '');
        $noticeHtml = fridg3_guestbook_delete_entry($filename, $ip)
            ? '<div id="result">guestbook post deleted.</div><br>'
            : '<div id="error">failed to delete that guestbook post.</div><br>';
    } elseif ($action === 'ban') {
        $username = trim((string)($_POST['username'] ?? 'Anonymous'));
        $noticeHtml = fridg3_feed_ban_guest_ip($ip, (string)$_SESSION['user']['username'], $username)
            ? '<div id="result">banned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ' from feed and guestbook posting.</div><br>'
            : '<div id="error">failed to ban IP.</div><br>';
    } elseif ($action === 'unban') {
        $bannedIps = fridg3_feed_load_banned_ips();
        $updated = settings_banned_ips_remove_ip($bannedIps, $ip);
        if ($updated === $bannedIps) {
            $noticeHtml = '<div id="result">that IP is not currently banned.</div><br>';
        } elseif (!fridg3_feed_write_banned_ips($updated)) {
            $noticeHtml = '<div id="error">failed to unban IP. check file permissions.</div><br>';
        } else {
            $noticeHtml = '<div id="result">unbanned ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</div><br>';
        }
    }
}

$bannedIps = fridg3_feed_load_banned_ips();
$rows = settings_banned_ips_rows($bannedIps);
$guestUsernamesByIp = fridg3_feed_collect_guest_usernames_by_ip();
$guestRepliesByIp = fridg3_feed_collect_guest_replies_by_ip();
$guestbookEntriesByIp = fridg3_guestbook_collect_entries_by_ip();
$allIps = array_fill_keys(array_merge(array_keys($rows), array_keys($guestRepliesByIp), array_keys($guestbookEntriesByIp)), true);
$allIps = array_keys($allIps);
sort($allIps, SORT_NATURAL);
$csrf = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$safeSearchQuery = htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
$formAction = '/settings/guests/';
if ($searchQuery !== '') {
    $formAction .= '?q=' . rawurlencode($searchQuery);
}
$safeFormAction = htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8');

$content = '<h1>manage guests</h1>'
    . '<h2>guest feed and guestbook moderation</h2>'
    . $noticeHtml
    . '<p style="color: var(--subtle);">guest feed replies and IP-backed guestbook posts are grouped together. purging content deletes both from an IP; banning separately blocks new posts in both places.</p>'
    . '<form id="search" action="/settings/guests/" method="GET">'
    . '<input id="search-box" name="q" type="text" placeholder="search IPs or usernames..." value="' . $safeSearchQuery . '">'
    . '<button id="search-button" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>'
    . '</form>'
    . '<br>';

if (empty($allIps)) {
    $content .= '<p>no guest content or banned IP addresses.</p>';
} else {
    $content .= '<div class="account-admin-grid">';
    $visibleCount = 0;
    foreach ($allIps as $ip) {
        $entry = $rows[$ip] ?? [];
        $isBanned = array_key_exists($ip, $rows);
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
        if (!settings_guests_ip_matches_search($ip, $usernameList, $searchQuery)) {
            continue;
        }
        $visibleCount++;
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
            . '<strong>' . $safeIp . '</strong>'
            . '<span>' . ($isBanned ? 'banned' : 'not banned') . ' &middot; ' . htmlspecialchars($replyLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>usernames: ' . $usernameText . '</span>'
            . '<span class="account-admin-meta">'
            . '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-admin-password-confirm="1" data-confirm-title="purge guest content from this IP?" data-confirm-detail="this deletes feed replies and guestbook posts from this IP. it does not ban or unban the IP." data-confirm-text="purge content" data-cancel-text="cancel" data-password-title="confirm guest purge" data-password-detail="enter your admin password to purge all guest content from this IP." style="display:inline-block; margin-right: 8px;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="purge_content">'
            . '<input type="hidden" name="ip" value="' . $safeIp . '">'
            . '<button class="danger-button" type="submit">purge content</button>'
            . '</form>';
        if (!$isBanned) {
            $banUsername = $usernameList[0] ?? 'Anonymous';
            $content .= '<form method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-confirm-title="ban IP?" data-confirm-detail="this blocks new feed replies and guestbook posts from this IP." data-confirm-text="ban" data-cancel-text="cancel" style="display:inline-block;">'
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
        $content .= '</span>';

        if (!empty($guestReplies)) {
            $content .= '<h4 style="margin-top: 12px;">feed replies</h4>';
            $content .= '<div class="feed-replies-list guest-management-replies" style="margin-top: 12px;">';
            foreach ($guestReplies as $reply) {
                $replyUser = htmlspecialchars((string)($reply['username'] ?? 'Anonymous'), ENT_QUOTES, 'UTF-8');
                $replyDateRaw = (string)($reply['date'] ?? '');
                $replyDate = htmlspecialchars($replyDateRaw !== '' ? fridg3_feed_humanize_datetime($replyDateRaw) : 'unknown date', ENT_QUOTES, 'UTF-8');
                $postId = (string)($reply['postId'] ?? '');
                $postUrl = '/feed/posts/' . rawurlencode($postId);
                $replyBody = htmlspecialchars((string)($reply['body'] ?? ''), ENT_QUOTES, 'UTF-8');
                $replyId = htmlspecialchars((string)($reply['replyId'] ?? ''), ENT_QUOTES, 'UTF-8');
                $content .= '<div class="feed-reply">'
                    . '<div class="feed-reply-header">'
                    . '<span class="feed-reply-username"><em>' . $replyUser . '</em></span>'
                    . '<span class="feed-reply-date">' . $replyDate . ' &middot; <a href="' . $postUrl . '">view</a>'
                    . '<form class="feed-reply-delete-form" method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-confirm-title="delete feed reply?" data-confirm-detail="this removes this individual guest reply." data-confirm-text="delete" data-cancel-text="cancel">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="delete_feed_reply">'
                    . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                    . '<input type="hidden" name="post_id" value="' . htmlspecialchars($postId, ENT_QUOTES, 'UTF-8') . '">'
                    . '<input type="hidden" name="reply_id" value="' . $replyId . '">'
                    . '<button type="submit" class="feed-reply-action-button" data-tooltip="delete reply"><i class="fa-solid fa-trash"></i></button>'
                    . '</form></span>'
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
                $entryDate = htmlspecialchars((string)($entry['timestamp'] ?? 'unknown date'), ENT_QUOTES, 'UTF-8');
                $entryBody = nl2br(htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8'));
                $entryFile = htmlspecialchars((string)($entry['file'] ?? ''), ENT_QUOTES, 'UTF-8');
                $content .= '<div class="feed-reply">'
                    . '<div class="feed-reply-header">'
                    . '<span class="feed-reply-username"><em>' . $entryName . '</em></span>'
                    . '<span class="feed-reply-date">' . $entryDate . ' &middot; <a href="/guestbook">view</a>'
                    . '<form class="feed-reply-delete-form" method="post" action="' . $safeFormAction . '" data-no-spa="1" data-site-confirm="1" data-confirm-title="delete guestbook post?" data-confirm-detail="this removes this individual guestbook post." data-confirm-text="delete" data-cancel-text="cancel">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="delete_guestbook_entry">'
                    . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                    . '<input type="hidden" name="guestbook_file" value="' . $entryFile . '">'
                    . '<button type="submit" class="feed-reply-action-button" data-tooltip="delete guestbook post"><i class="fa-solid fa-trash"></i></button>'
                    . '</form></span>'
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
    if ($visibleCount === 0) {
        $content .= '<p>no guest IPs or usernames matched your search.</p>';
    }
    $content .= '</div>';
}

account_admin_render_page($title, $description, $content);
?>
