<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'guestbook.php';

$title = 'guestbook';
$description = 'messages left by visitors.';
$postsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook';
$isAdmin = isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']);
$pageSize = 10;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Best-effort client IP detection (single IP only)
function guestbook_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $raw) {
        if (!$raw) {
            continue;
        }
        $ip = trim(explode(',', $raw)[0]);
        if ($ip !== '') {
            return $ip;
        }
    }
    return 'unknown';
}

$clientIp = guestbook_client_ip();

// Load IP index (ip -> filename)
$ip_index = [];
$ip_index_path = $postsDir . DIRECTORY_SEPARATOR . 'ip_index.json';
if (is_file($ip_index_path)) {
    $decoded = json_decode(@file_get_contents($ip_index_path), true);
    if (is_array($decoded)) {
        $ip_index = $decoded;
    }
}

// Handle owner deletion and admin IP moderation requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        $_SESSION['guestbook_status'] = 'invalid request.';
        header('Location: /guestbook');
        exit;
    }

    $moderationAction = (string)($_POST['moderation_action'] ?? '');
    $moderationIp = trim((string)($_POST['ip'] ?? ''));
    if ($isAdmin && $moderationAction === 'ban_ip' && filter_var($moderationIp, FILTER_VALIDATE_IP)) {
        $guestName = trim((string)($_POST['guest_name'] ?? 'Anonymous'));
        $_SESSION['guestbook_status'] = fridg3_feed_ban_guest_ip($moderationIp, (string)$_SESSION['user']['username'], $guestName)
            ? 'IP banned from feed and guestbook posting.'
            : 'unable to ban that IP.';
        header('Location: /guestbook');
        exit;
    }
    if ($isAdmin && $moderationAction === 'purge_ip' && filter_var($moderationIp, FILTER_VALIDATE_IP)) {
        if (!fridg3_feed_verify_current_admin_password((string)($_POST['admin_password'] ?? ''))) {
            $_SESSION['guestbook_status'] = 'admin password did not match. purge cancelled.';
        } else {
            $feedResult = fridg3_feed_purge_guest_replies_by_ip($moderationIp);
            $guestbookResult = fridg3_guestbook_purge_entries_by_ip($moderationIp);
            $_SESSION['guestbook_status'] = 'purged '
                . ((int)$feedResult['deleted'] + (int)$guestbookResult['deleted'])
                . ' guest item(s) for this IP.';
        }
        header('Location: /guestbook');
        exit;
    }

    $deleteFile = basename($_POST['delete_file'] ?? '');
    $isOwner = isset($ip_index[$clientIp]) && $ip_index[$clientIp] === $deleteFile;
    $canDelete = $isAdmin || $isOwner;

    if ($canDelete && fridg3_guestbook_delete_entry($deleteFile)) {
        $_SESSION['guestbook_status'] = 'post deleted.';
    } else {
        $_SESSION['guestbook_status'] = 'invalid delete request.';
    }

    header('Location: /guestbook');
    exit;
}

// Convert absolute timestamp (Y/m/d H:i:s) to a short relative string like "13h ago"
function guestbook_relative_time(string $timestamp): string {
    $dt = DateTime::createFromFormat('Y/m/d H:i:s', $timestamp);
    if (!$dt) {
        return '';
    }

    $now = new DateTime('now');
    $diffSeconds = $now->getTimestamp() - $dt->getTimestamp();
    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }

    if ($diffSeconds < 60) {
        return $diffSeconds . 's ago';
    }

    $minutes = intdiv($diffSeconds, 60);
    if ($minutes < 60) {
        return $minutes . 'm ago';
    }

    $hours = intdiv($minutes, 60);
    if ($hours < 24) {
        return $hours . 'h ago';
    }

    $days = intdiv($hours, 24);
    if ($days < 7) {
        return $days . 'd ago';
    }

    $weeks = intdiv($days, 7);
    if ($weeks < 5) {
        return $weeks . 'w ago';
    }

    $months = intdiv($days, 30);
    if ($months < 12) {
        return $months . 'mo ago';
    }

    $years = intdiv($days, 365);
    return $years . 'y ago';
}

// Return sorted guestbook files (newest first)
function guestbook_get_files(string $postsDir): array {
    if (!is_dir($postsDir)) {
        return [];
    }
    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    if (!$files) {
        return [];
    }
    usort($files, function($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    return $files;
}

// Build HTML for a provided list of guestbook files
function render_guestbook_posts(array $files, bool $isAdmin, string $clientIp, array $ipIndex, string $csrfToken): string {
    if (empty($files)) {
        return '<div id="post"><div id="post-header"><span id="post-username">no messages yet</span><span id="post-date-feed"></span></div><span id="post-content">be the first to leave one!</span></div>';
    }

    $html = '';
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        $entry = fridg3_guestbook_parse_entry($raw, basename($file));
        if ($entry === null) {
            continue;
        }
        $timestampLine = (string)$entry['timestamp'];
        $nameLine = (string)$entry['name'];
        $entryIp = (string)$entry['ip'];
        if ($entryIp === '') {
            foreach ($ipIndex as $indexedIp => $indexedFile) {
                if ((string)$indexedFile === basename($file) && filter_var((string)$indexedIp, FILTER_VALIDATE_IP)) {
                    $entryIp = (string)$indexedIp;
                    break;
                }
            }
        }
        $message = (string)$entry['message'];

        if ($message === '') {
            continue;
        }

        $safeTimestamp = htmlspecialchars($timestampLine !== '' ? $timestampLine : 'unknown time', ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($nameLine !== '' ? $nameLine : 'Anonymous', ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $relative = guestbook_relative_time($timestampLine);
        $displayTime = $relative !== '' ? $relative : $safeTimestamp;
        $timeHtml = '<span id="post-date-feed" title="' . $safeTimestamp . '">' . $displayTime . '</span>';

        $deleteButton = '';
        $editButton = '';
        $safeFile = htmlspecialchars(basename($file), ENT_QUOTES, 'UTF-8');
        $isOwner = isset($ipIndex[$clientIp]) && $ipIndex[$clientIp] === basename($file);
        if ($isAdmin || $isOwner) {
            $editUrl = '/guestbook/edit?file=' . rawurlencode($safeFile);
            $editButton = '<a class="guestbook-edit-btn" href="' . $editUrl . '" data-tooltip="edit post"><i class="fa-solid fa-pen"></i></a>';
            $deleteButton = '<form class="guestbook-delete-form" method="POST" action="/guestbook/index.php" data-site-confirm="1" data-confirm-title="delete guestbook entry?" data-confirm-detail="this removes the guestbook entry from the site." data-confirm-text="delete" data-cancel-text="cancel">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<input type="hidden" name="delete_file" value="' . $safeFile . '">'
                . '<button type="submit" id="post-edit-feed" class="guestbook-delete-btn" data-tooltip="delete post"><i class="fa-solid fa-trash"></i></button>'
                . '</form>';
        }

        $ipModerationButtons = '';
        if ($isAdmin && $entryIp !== '') {
            $safeIp = htmlspecialchars($entryIp, ENT_QUOTES, 'UTF-8');
            if (!fridg3_feed_is_ip_banned($entryIp)) {
                $ipModerationButtons .= '<form class="guestbook-delete-form" method="POST" action="/guestbook/index.php" data-site-confirm="1" data-confirm-title="ban IP?" data-confirm-detail="this blocks feed replies and guestbook posts from this IP." data-confirm-text="ban IP" data-cancel-text="cancel">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                    . '<input type="hidden" name="moderation_action" value="ban_ip">'
                    . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                    . '<input type="hidden" name="guest_name" value="' . $safeName . '">'
                    . '<button type="submit" id="post-edit-feed" class="guestbook-delete-btn" data-tooltip="ban IP"><i class="fa-solid fa-ban"></i></button>'
                    . '</form>';
            }
            $ipModerationButtons .= '<form class="guestbook-delete-form" method="POST" action="/guestbook/index.php" data-site-confirm="1" data-admin-password-confirm="1" data-confirm-title="purge guest content from this IP?" data-confirm-detail="this deletes feed replies and guestbook posts from this IP." data-confirm-text="purge content" data-cancel-text="cancel" data-password-title="confirm guest purge" data-password-detail="enter your admin password to purge this IP&apos;s guest content.">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<input type="hidden" name="moderation_action" value="purge_ip">'
                . '<input type="hidden" name="ip" value="' . $safeIp . '">'
                . '<button type="submit" id="post-edit-feed" class="guestbook-delete-btn" data-tooltip="purge IP content"><i class="fa-solid fa-broom"></i></button>'
                . '</form>';
        }

        $rightSide = '<div class="guestbook-post-actions">' . $timeHtml . $editButton . $ipModerationButtons . $deleteButton . '</div>';

        $html .= '<div id="post">'
            . '<div id="post-header">'
            . '<span id="post-username">' . $safeName . '</span>'
            . $rightSide
            . '</div>'
            . '<span id="post-content">' . $safeMessage . '</span>'
            . '</div>';
    }

    if ($html === '') {
        return '<div id="post"><div id="post-header"><span id="post-username">no messages yet</span><span id="post-date-feed"></span></div><span id="post-content">be the first to leave one!</span></div>';
    }

    return $html;
}

function render_guestbook_pagination(int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) {
        return '';
    }
    $items = '';
    for ($i = 1; $i <= $totalPages; $i++) {
        $isCurrent = $i === $currentPage;
        $class = 'guestbook-page-btn' . ($isCurrent ? ' current' : '');
        $aria = $isCurrent ? ' aria-current="page"' : '';
        $label = $i;
        if ($isCurrent) {
            $items .= '<span class="' . $class . '"' . $aria . '>' . $label . '</span>';
        } else {
            $items .= '<a class="' . $class . '" href="/guestbook?page=' . $i . '">' . $label . '</a>';
        }
    }
    return '<div class="guestbook-pagination">' . $items . '</div>';
}


function find_template_file($filename) {
    $dir = __DIR__;
    $prev_dir = '';
    
    while ($dir !== $prev_dir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prev_dir = $dir;
        $dir = dirname($dir);
    }
    
    return null;
}

$render_helper_path = find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$template_path = find_template_file($template_name);
if (!$template_path && $template_name !== 'template.html') {
    $template_path = find_template_file('template.html');
}
if (!$template_path) {
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

// Generate user greeting if logged in
$user_greeting = '';
if (isset($_SESSION['user'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    
    // Swap Account button to Logout
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
    $template = str_replace($accountBtn, $logoutBtn, $template);
}

// Replace user greeting placeholder
$template = str_replace('{user_greeting}', $user_greeting, $template);

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);
$allFiles = guestbook_get_files($postsDir);
$totalPosts = count($allFiles);
$totalPages = $totalPosts > 0 ? (int)ceil($totalPosts / $pageSize) : 1;
$currentPage = min($currentPage, max(1, $totalPages));
$offset = ($currentPage - 1) * $pageSize;
$pageFiles = array_slice($allFiles, $offset, $pageSize);
$posts_html = render_guestbook_posts(
    $pageFiles,
    $isAdmin,
    $clientIp,
    $ip_index,
    htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8')
);
$pagination_html = render_guestbook_pagination($currentPage, $totalPages);

$leave_button_attrs = 'data-tooltip="post to the guestbook"';
$isClientIpBanned = fridg3_feed_is_ip_banned($clientIp);
if (isset($ip_index[$clientIp]) || $isClientIpBanned) {
    $leave_button_attrs = 'disabled aria-disabled="true" class="form-button-disabled" data-tooltip="'
        . ($isClientIpBanned ? 'your IP address has been restricted.' : 'you can only post here once!')
        . '"';
}

$status_message = $_SESSION['guestbook_status'] ?? '';
if ($status_message !== '') {
    unset($_SESSION['guestbook_status']);
}

$status_html = $status_message !== ''
    ? '<div class="form-status success">' . htmlspecialchars($status_message, ENT_QUOTES, 'UTF-8') . '</div>'
    : '';

$content = str_replace('{status}', $status_html, $content);
$content = str_replace('{leave_button_attrs}', $leave_button_attrs, $content);
$content = str_replace('{posts}', $posts_html, $content);
$content = str_replace('{pagination}', $pagination_html, $content);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
