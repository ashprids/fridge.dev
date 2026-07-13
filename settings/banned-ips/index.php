<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';

account_admin_require_admin();

if (empty($_SESSION['hard_ban_csrf']) || !is_string($_SESSION['hard_ban_csrf'])) {
    $_SESSION['hard_ban_csrf'] = bin2hex(random_bytes(32));
}

$notice = '';
$editorValue = implode(PHP_EOL, fridg3_hard_ban_load());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editorValue = (string)($_POST['hard_banned_ips'] ?? '');
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['hard_ban_csrf'], $submittedToken)) {
        $notice = '<div id="error">invalid request token. refresh and try again.</div><br>';
    } else {
        $parsed = fridg3_hard_ban_parse($editorValue);
        if ($parsed['invalid'] !== []) {
            $invalid = htmlspecialchars(implode(', ', $parsed['invalid']), ENT_QUOTES, 'UTF-8');
            $notice = '<div id="error">invalid IP address' . (count($parsed['invalid']) === 1 ? '' : 'es') . ': ' . $invalid . '</div><br>';
        } elseif (!fridg3_hard_ban_admin_save($parsed['ips'])) {
            $notice = '<div id="error">could not save the hard-ban list. check data directory permissions.</div><br>';
        } else {
            $editorValue = implode(PHP_EOL, fridg3_hard_ban_load());
            $notice = '<div id="result">hard-ban list saved.</div><br>';
        }
    }
}

$safeValue = htmlspecialchars($editorValue, ENT_QUOTES, 'UTF-8');
$safeCsrf = htmlspecialchars((string)$_SESSION['hard_ban_csrf'], ENT_QUOTES, 'UTF-8');
$content = '<style>'
    . '.hard-ban-editor{width:100%;min-height:55vh;resize:vertical;box-sizing:border-box;font:inherit;line-height:1.45;background:var(--bg);color:var(--fg);border:1px solid var(--border);padding:12px;}'
    . '.hard-ban-note{max-width:760px;color:var(--subtle);}'
    . '</style>'
    . '<h1>hard-banned IPs</h1>'
    . '<h2>site-wide network access restrictions</h2>'
    . $notice
    . '<p class="hard-ban-note">one IP per space or line. hard-banned IPs are redirected by nginx before website pages or files are served. only <code>/error/blacklisted</code>, files inside that directory, and shared font files remain accessible.</p>'
    . '<p class="hard-ban-note">a first-party browser identifier associates later IPs with the original manually banned IP. removing that original IP also removes its automatically associated IPs and browser records.</p>'
    . '<p class="hard-ban-note"><strong>warning:</strong> adding your current IP will lock this browser out after the save completes.</p>'
    . '<form method="post" action="/settings/banned-ips/" data-no-spa="1">'
    . '<input type="hidden" name="csrf_token" value="' . $safeCsrf . '">'
    . '<label for="hard-banned-ips"><strong>IP addresses</strong></label><br><br>'
    . '<textarea class="hard-ban-editor" id="hard-banned-ips" name="hard_banned_ips" spellcheck="false" autocomplete="off" wrap="off">' . $safeValue . '</textarea>'
    . '<br><br><button id="form-button" type="submit">save hard bans</button>'
    . '</form>';

account_admin_render_page('hard-banned IPs', 'manage IP addresses blocked from the entire website.', $content);
