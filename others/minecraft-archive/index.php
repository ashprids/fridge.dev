<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridge_start_session();

$title = 'minecraft archive';
$description = "an archive of the minecraft worlds i've worked on throughout the past couple of years, from builds and survival to modpacks and multiplayer servers.";


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

// Example of a route-specific non-process PHP debug entry. Shared request and
// included-file entries are emitted automatically by lib/debug.php on all pages.
fridge_debug_log('[PHP] formatting Markdown example page initialized');

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$template_path = find_template_file($template_name);
if (!$template_path && $template_name !== 'template.html') {
    $template_path = find_template_file('template.html');
}
if (!$template_path) {
    die('page template not found. report this issue to ashton@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

// The page source is Markdown; use the same renderer and presentation as journal posts.
require_once find_template_file('tools/mdpaste/lib.php');
$content_path = __DIR__ . DIRECTORY_SEPARATOR . 'content.md';
if (!is_file($content_path) || !is_readable($content_path)) {
    http_response_code(500);
    die('content.md not found. report this issue to ashton@fridge.dev.');
}

$markdown = (string)file_get_contents($content_path);
$rendered = '<article class="mdpaste-markdown">' . mdp_render_trusted_markdown($markdown) . '</article>';
$markdown_view = (string)file_get_contents(find_template_file('tools/mdpaste/s/content.html'));
$content = str_replace('{paste_content}', $rendered, $markdown_view);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

// Inject user greeting and swap account button when logged in
$user_greeting = '';
if (isset($_SESSION['user']) && isset($_SESSION['user']['name'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    // Swap Account button to Logout in the template footer
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountBtn, $logoutBtn, $html);
}
$html = str_replace('{user_greeting}', $user_greeting, $html);
echo $html;
?>
