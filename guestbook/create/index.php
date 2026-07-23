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

$title = 'post to guestbook';
$description = 'send a message to the guestbook.';

$status_message = '';
$status_class = 'success';
$hasPosted = false;
$postingRestricted = fridg3_current_user_posting_restricted();

$client_ip = guestbook_client_ip();
$isClientIpBanned = fridg3_feed_is_ip_banned($client_ip);
if ($isClientIpBanned) {
    $status_message = 'your IP address has been restricted.';
    $status_class = 'error';
}

$posts_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'guestbook';
if (!is_dir($posts_dir)) {
    @mkdir($posts_dir, 0777, true);
}

$ip_index_path = $posts_dir . DIRECTORY_SEPARATOR . 'ip_index.json';
$ip_index = [];
if (is_file($ip_index_path)) {
    $decoded = json_decode(@file_get_contents($ip_index_path), true);
    if (is_array($decoded)) {
        $ip_index = $decoded;
    }
}

// Mark if this IP already posted (for UI disable)
if (isset($ip_index[$client_ip])) {
    $hasPosted = true;
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
        // If multiple forwarded IPs, take the first
        $ip = trim(explode(',', $raw)[0]);
        if ($ip !== '') {
            return $ip;
        }
    }
    return 'unknown';
}

// Handle guestbook submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postingRestricted) {
    header('Location: /guestbook/create?posting_restricted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $message = $_POST['message'] ?? '';

    // Normalize and sanitize inputs (strip HTML/PHP tags)
    $name = strip_tags($name);
    $name = str_replace(["\r", "\n"], ' ', $name);
    $message = str_replace(["\r\n", "\r"], "\n", $message);
    $message = strip_tags($message);

    // Fallback values and length limits
    $name = $name === '' ? 'Anonymous' : $name;
    $name = (function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80));
    $message = trim(function_exists('mb_substr') ? mb_substr($message, 0, 4000) : substr($message, 0, 4000));

    if ($isClientIpBanned) {
        $status_message = 'your IP address has been restricted.';
        $status_class = 'error';
    } elseif ($message === '') {
        $status_message = 'message cannot be empty.';
        $status_class = 'error';
    } else {
        if (isset($ip_index[$client_ip])) {
            $hasPosted = true;
            $status_message = 'you have already posted to the guestbook.';
            $status_class = 'error';
        } else {
            $timestamp_line = date('Y/m/d H:i:s');

            // Use timestamp plus random suffix to avoid collisions
            try {
                $suffix = bin2hex(random_bytes(3));
            } catch (Exception $e) {
                $suffix = substr(uniqid('', true), -6);
            }
            $filename = date('Y-m-d_H-i-s') . '_' . $suffix . '.txt';
            $filepath = $posts_dir . DIRECTORY_SEPARATOR . $filename;

            $written = fridg3_guestbook_write_entry($filepath, $timestamp_line, $name, $message, $client_ip);
            if ($written === false) {
                fridg3_debug_submission_log('[SUBMISSION] guestbook entry save failed');
                $status_message = 'could not save your message. please try again later.';
                $status_class = 'error';
            } else {
                fridg3_debug_submission_log('[SUBMISSION] guestbook entry save succeeded attachments=0');
                $ip_index[$client_ip] = $filename;
                @file_put_contents($ip_index_path, json_encode($ip_index, JSON_PRETTY_PRINT), LOCK_EX);
                header('Location: /guestbook');
                exit;
            }
        }
    }
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
$status_html = '';
if ($status_message !== '') {
    $status_classname = $status_class === 'error' ? 'form-status error' : 'form-status success';
    $status_html = '<div class="' . $status_classname . '">' . htmlspecialchars($status_message, ENT_QUOTES, 'UTF-8') . '</div>';
}

$button_state = $hasPosted || $isClientIpBanned
    ? 'disabled aria-disabled="true" class="form-button-disabled" data-tooltip="' . ($isClientIpBanned ? 'your IP address has been restricted.' : 'you can only post here once!') . '"'
    : 'data-tooltip="please note: you can only post one message here!"';
$content = str_replace('{status}', $status_html, $content);
$content = str_replace('{post_button_attrs}', $button_state, $content);
if ($postingRestricted) {
    $content = fridg3_disable_composer_controls($content);
    $content = str_replace('<form id="guestbook-form"', fridg3_posting_restriction_notice() . '<form id="guestbook-form"', $content);
}
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
