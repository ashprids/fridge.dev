<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'frdgBeats';
$description = 'a browser-based music sketchpad with synth, SoundFont and sample instruments.';


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
$frdgbeats_css_path = __DIR__ . '/frdgbeats.css';
$frdgbeats_css_href = '/tools/frdgbeats/frdgbeats.css';
if (is_file($frdgbeats_css_path)) {
    $frdgbeats_css_href .= '?v=' . filemtime($frdgbeats_css_path);
}
$frdgbeats_css_link = '    <link rel="stylesheet" href="' . htmlspecialchars($frdgbeats_css_href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if (stripos($template, '</head>') !== false && strpos($template, '/tools/frdgbeats/frdgbeats.css') === false) {
    $template = preg_replace('/<\/head>/i', $frdgbeats_css_link . '</head>', $template, 1);
}
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}
$frdgbeats_layout_style = <<<'HTML'
    <style>
    #content:has(.frdgbeats-daw),
    #content-layout:has(.frdgbeats-daw),
    #content-main:has(.frdgbeats-daw) {
        box-sizing: border-box;
        max-width: none !important;
        width: 100% !important;
    }
    </style>

HTML;
if (stripos($template, '</head>') !== false && strpos($template, '#content:has(.frdgbeats-daw)') === false) {
    $template = preg_replace('/<\/head>/i', $frdgbeats_layout_style . '</head>', $template, 1);
}

$content_filename = function_exists('should_use_mobile_template') && should_use_mobile_template(__DIR__)
    ? 'content_mobile.html'
    : 'content.html';
$content_path = find_template_file($content_filename);
if (!$content_path && $content_filename !== 'content.html') {
    $content_path = find_template_file('content.html');
}
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);
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
