<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
fridg3_start_session();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib.php';

$id = mdp_share_id_from_request();
$paste = null;
$markdown = null;
$error = '';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$alreadyCleanUrl = is_string($requestPath) && preg_match('#^/tools/mdpaste/s/[a-fA-F0-9]{16}/?$#', $requestPath);
if ($id !== '' && isset($_GET['id']) && !$alreadyCleanUrl && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /tools/mdpaste/s/' . rawurlencode($id), true, 301);
    exit;
}

try {
    $paste = $id !== '' ? mdp_load_paste($id) : null;
} catch (Throwable $exception) {
    $paste = null;
}

if ($paste !== null && empty($paste['encrypted'])) {
    $markdown = mdp_decrypt_paste($paste, '');
}

if ($paste !== null && !empty($paste['encrypted']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $markdown = mdp_decrypt_paste($paste, $password);
    if ($markdown === null) {
        $error = 'wrong password. tragic, but recoverable.';
    }
}

$title = 'mdpaste';
$description = 'a markdown file has been shared with you.';

if ($paste === null) {
    http_response_code(404);
    $pasteContent = '<h1>not found</h1><h2>that paste is missing, expired, or never existed.</h2>'
        . '<a class="mdpaste-view-action" href="/tools/mdpaste/">create a paste</a>';
} elseif ($markdown === null) {
    $errorHtml = $error !== '' ? '<p id="error">' . mdp_h($error) . '</p>' : '';
    $pasteContent = '<h1>locked paste</h1>'
        . '<h2>this paste is encrypted. enter its password to view the markdown.</h2>'
        . $errorHtml
        . '<form class="form-card mdpaste-unlock-form" method="post">'
        . '<label for="password">password</label>'
        . '<input class="text-input" id="password" name="password" type="password" autocomplete="current-password" autofocus required>'
        . '<button id="form-button" type="submit">unlock</button>'
        . '</form>';
} else {
    $meta = '';
    if (!empty($paste['encrypted'])) {
        $meta .= '<span>encrypted</span>';
    }
    $downloadFilename = mdp_download_filename($markdown);
    $rawPayload = json_encode(
        ['markdown' => $markdown, 'filename' => $downloadFilename],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    );
    $metaHtml = $meta !== '' ? '<div class="mdpaste-view-meta">' . $meta . '</div>' : '';
    $pasteContent = '<div class="mdpaste-paste-actions">'
        . '<button type="button" id="mdpaste-download" data-tooltip="download file" aria-label="download file"><i class="fa-solid fa-download"></i></button>'
        . '<button type="button" id="mdpaste-font-toggle" data-tooltip="toggle serif font" aria-label="toggle serif font" aria-pressed="false"><i class="fa-solid fa-font"></i></button>'
        . '<button type="button" id="mdpaste-format-toggle" data-tooltip="toggle formatting" aria-label="toggle formatting" aria-pressed="false"><i class="fa-solid fa-code"></i></button>'
        . '</div>' . $metaHtml
        . '<article class="mdpaste-markdown" id="mdpaste-formatted">'
        . mdp_render_markdown($markdown, !empty($paste['hard_breaks']))
        . '</article>'
        . '<pre class="mdpaste-raw-markdown" id="mdpaste-raw" hidden><code class="nohighlight">' . mdp_h($markdown) . '</code></pre>'
        . '<script id="mdpaste-source-data" type="application/json">' . ($rawPayload ?: '{}') . '</script>';
}

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
    die('page template not found. report this issue to ashton@fridge.dev.');
}

$template = (string)file_get_contents($templatePath);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}
$template = preg_replace('/<span id="show-sidebar"[^>]*>.*?<\/span>\s*/is', '', $template, 1) ?: $template;
$template = preg_replace(
    '/(<div id="page-wrapper">\s*)<div id="sidebar">.*?(<div id="container">)/is',
    '$1$2',
    $template,
    1
) ?: $template;
$template = preg_replace('/<body class="([^"]*)">/i', '<body class="$1 mdpaste-shared-page">', $template, 1) ?: $template;
$template = preg_replace(
    '/<div id="site-notice-banner-region">.*?<script id="site-notice-runtime"[^>]*>.*?<\/script>/is',
    '',
    $template,
    1
) ?: $template;
$template = preg_replace(
    '/<div id="content-footer">\s*<span id="content-footer-views"[^>]*>.*?<\/span>\s*<\/div>/is',
    '',
    $template,
    1
) ?: $template;
$template = str_replace('</head>', '<meta name="robots" content="noindex,nofollow">' . "\n</head>", $template);

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
if (!is_file($contentPath)) {
    die('content.html not found. report this issue to ashton@fridge.dev.');
}
$content = str_replace('{paste_content}', $pasteContent, (string)file_get_contents($contentPath));
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
