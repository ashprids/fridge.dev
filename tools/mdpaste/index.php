<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
fridg3_start_session();
fridg3_refresh_current_user_posting_restriction();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

$postingRestricted = fridg3_current_user_posting_restricted();
$ipBanned = fridg3_feed_is_ip_banned(fridg3_feed_client_ip());
$pasteCreationBlocked = $postingRestricted || $ipBanned;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($pasteCreationBlocked) {
        mdp_json_response([
            'ok' => false,
            'error' => $postingRestricted
                ? 'your account has been restricted.'
                : 'your IP address has been restricted.',
        ], 403);
    }

    try {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'application/json')) {
            mdp_json_response(['ok' => false, 'error' => 'send json, bestie.'], 415);
        }

        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            mdp_json_response(['ok' => false, 'error' => 'that json is cooked.'], 400);
        }

        $markdown = is_string($payload['markdown'] ?? null) ? $payload['markdown'] : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
        $hardBreaks = (bool)($payload['hardBreaks'] ?? false);
        if (($payload['action'] ?? '') === 'preview') {
            mdp_json_response([
                'ok' => true,
                'html' => mdp_render_markdown($markdown, $hardBreaks),
            ]);
        }
        $paste = mdp_create_paste($markdown, $password, $hardBreaks);

        mdp_json_response([
            'ok' => true,
            'id' => $paste['id'],
            'url' => '/tools/mdpaste/s/' . rawurlencode((string)$paste['id']),
            'expires_at' => date(DATE_ATOM, (int)$paste['expires_at']),
            'encrypted' => (bool)$paste['encrypted'],
        ]);
    } catch (InvalidArgumentException $error) {
        mdp_json_response(['ok' => false, 'error' => $error->getMessage()], 400);
    } catch (Throwable $error) {
        mdp_json_response(['ok' => false, 'error' => 'server tripped over its shoelaces. try again.'], 500);
    }
}

$title = 'mdpaste';
$description = 'write and share Markdown-formatted notes.';

$renderHelperPath = mdp_find_up('lib' . DIRECTORY_SEPARATOR . 'render.php');
if ($renderHelperPath !== null) {
    require_once $renderHelperPath;
}

$templateName = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$templatePath = mdp_find_up($templateName);
if ($templatePath === null && $templateName !== 'template.html') {
    $templatePath = mdp_find_up('template.html');
}
if ($templatePath === null) {
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = (string)file_get_contents($templatePath);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}
$template = str_replace(
    '</head>',
    "\t<meta name=\"robots\" content=\"noindex, nofollow\">\n</head>",
    $template
);

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
if (!is_file($contentPath) || !is_readable($contentPath)) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = (string)file_get_contents($contentPath);
if ($pasteCreationBlocked) {
    $restrictionNotice = $postingRestricted
        ? fridg3_posting_restriction_notice()
        : '<p class="posting-restriction-message">your IP address has been restricted.</p>';
    $content = fridg3_disable_composer_controls($content);
    $content = str_replace(
        'contenteditable="true"',
        'contenteditable="false" aria-disabled="true"',
        $content
    );
    $content = $restrictionNotice . $content;
}

$html = str_replace(
    ['{content}', '{title}', '{description}'],
    [$content, $title, $description],
    $template
);

$userGreeting = '';
if (isset($_SESSION['user']['name'])) {
    $userName = htmlspecialchars((string)$_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $userGreeting = '<div id="user-greeting">Hello, ' . $userName . '!</div>';
    $accountButton = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutButton = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountButton, $logoutButton, $html);
}
$html = str_replace('{user_greeting}', $userGreeting, $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
