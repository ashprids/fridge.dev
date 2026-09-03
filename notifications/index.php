<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();

$title = 'notifications';
$description = 'Your fridge.dev notifications.';

function find_template_file($filename) {
    $dir = __DIR__;
    $previous = '';
    while ($dir !== $previous) {
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($path)) return $path;
        $previous = $dir;
        $dir = dirname($dir);
    }
    return null;
}

$renderHelperPath = find_template_file('lib/render.php');
if ($renderHelperPath) require_once $renderHelperPath;

$templateName = function_exists('get_preferred_template_name') ? get_preferred_template_name(__DIR__) : 'template.html';
$templatePath = find_template_file($templateName);
if (!$templatePath && $templateName !== 'template.html') $templatePath = find_template_file('template.html');
if (!$templatePath) die('page template not found. report this issue to ashton@fridge.dev.');

$template = file_get_contents($templatePath);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
if (!is_file($contentPath)) die('content.html not found. report this issue to ashton@fridge.dev.');
$content = file_get_contents($contentPath);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$content = str_replace('{notification_csrf}', htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'), $content);

$userGreeting = '';
if (isset($_SESSION['user']['name'])) {
    $userName = htmlspecialchars((string)$_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $userGreeting = '<div id="user-greeting">Hello, ' . $userName . '!</div>';
    $accountButton = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutButton = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $template = str_replace($accountButton, $logoutButton, $template);
}

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
$html = str_replace('{user_greeting}', $userGreeting, $html);
$html = preg_replace('/<div id="content-footer">\s*<span id="content-footer-views"[^>]*>.*?<\/span>\s*<\/div>/is', '', $html) ?? $html;
echo $html;
?>
