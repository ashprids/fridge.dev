<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
fridg3_refresh_current_user_posting_restriction();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'guestbook.php';

$title = 'edit guestbook entry';
$description = 'edit a guestbook message (admin or owner).';

$isAdmin = isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']);
$postingRestricted = fridg3_current_user_posting_restricted();

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
$isClientIpBanned = !$isAdmin && fridg3_feed_is_ip_banned($clientIp);
$postingBlocked = $postingRestricted || $isClientIpBanned;

$status_message = '';
$status_class = 'success';

// Find template helper
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

$posts_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook';
$ip_index_path = $posts_dir . DIRECTORY_SEPARATOR . 'ip_index.json';
$ip_index = [];
if (is_file($ip_index_path)) {
    $decoded = json_decode(@file_get_contents($ip_index_path), true);
    if (is_array($decoded)) {
        $ip_index = $decoded;
    }
}
$target_file = '';
$timestamp_line = '';
$current_name = '';
$current_message = '';

function load_guestbook_entry(string $posts_dir, string $filename): ?array {
    return fridg3_guestbook_load_entry($filename);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postingBlocked) {
    header('Location: /guestbook/edit?file=' . rawurlencode(basename((string)($_POST['file'] ?? ''))) . '&posting_restricted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_file = basename($_POST['file'] ?? '');
    $entry = load_guestbook_entry($posts_dir, $target_file);
    $isOwner = isset($ip_index[$clientIp]) && $ip_index[$clientIp] === $target_file;
    if (!$entry || (!$isAdmin && !$isOwner)) {
        $status_message = 'could not load that entry.';
        $status_class = 'error';
    } else {
        $timestamp_line = $entry['timestamp'] ?: date('Y/m/d H:i:s');
        $name = trim($_POST['name'] ?? '');
        $message = $_POST['message'] ?? '';

        // Sanitize inputs
        $name = strip_tags($name);
        $name = str_replace(["\r", "\n"], ' ', $name);
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = strip_tags($message);

        // Limits and defaults
        $name = $name === '' ? 'Anonymous' : (function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80));
        $message = trim(function_exists('mb_substr') ? mb_substr($message, 0, 4000) : substr($message, 0, 4000));

        if ($message === '') {
            $status_message = 'message cannot be empty.';
            $status_class = 'error';
        } else {
            $written = fridg3_guestbook_write_entry(
                (string)$entry['path'],
                $timestamp_line,
                $name,
                $message,
                (string)($entry['ip'] ?? '')
            );
            if ($written === false) {
                $status_message = 'could not save your changes. please try again later.';
                $status_class = 'error';
            } else {
                $_SESSION['guestbook_status'] = 'post updated.';
                header('Location: /guestbook');
                exit;
            }
        }

        $current_name = $name;
        $current_message = $message;
    }
} else {
    $target_file = basename($_GET['file'] ?? '');
    $entry = load_guestbook_entry($posts_dir, $target_file);
    $isOwner = isset($ip_index[$clientIp]) && $ip_index[$clientIp] === $target_file;
    if (!$entry || (!$isAdmin && !$isOwner)) {
        $_SESSION['guestbook_status'] = 'invalid entry.';
        header('Location: /guestbook');
        exit;
    }
    $timestamp_line = $entry['timestamp'];
    $current_name = $entry['name'];
    $current_message = $entry['message'];
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

// user greeting + logout swap
$user_greeting = '';
if (isset($_SESSION['user'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
    $template = str_replace($accountBtn, $logoutBtn, $template);
}
$template = str_replace('{user_greeting}', $user_greeting, $template);

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);
$status_html = '';
if ($status_message !== '') {
    $status_classname = $status_class === 'error' ? 'form-status error' : 'form-status success';
    $status_html = '<div class="' . $status_classname . '">' . htmlspecialchars($status_message, ENT_QUOTES, 'UTF-8') . '</div>';
}

$content = str_replace('{status}', $status_html, $content);
$content = str_replace('{file}', htmlspecialchars($target_file, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{name}', htmlspecialchars($current_name, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{message}', htmlspecialchars($current_message, ENT_QUOTES, 'UTF-8'), $content);
if ($postingBlocked) {
    $content = fridg3_disable_composer_controls($content);
    $blockedNotice = $postingRestricted
        ? fridg3_posting_restriction_notice()
        : '<p class="posting-restriction-message">your IP address has been restricted.</p>';
    $content = str_replace('<form id="guestbook-edit-form"', $blockedNotice . '<form id="guestbook-edit-form"', $content);
}

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
