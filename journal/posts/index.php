<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'video-embeds.php';

if (isset($_SESSION['user']) && isset($_SESSION['user']['username'])) {
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    if (is_file($accountsPath)) {
        $accountsData = json_decode(@file_get_contents($accountsPath), true);
        if (is_array($accountsData) && isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
            foreach ($accountsData['accounts'] as $account) {
                if (isset($account['username']) && $account['username'] === $_SESSION['user']['username']) {
                    $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
                    $_SESSION['user']['allowedPages'] = (array)($account['allowedPages'] ?? []);
                    break;
                }
            }
        }
    }
}

$post = '';
// Support /journal/posts/01 style URLs
if (isset($_GET['post'])) {
    $post = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['post']);
} elseif (isset($_SERVER['PATH_INFO']) && preg_match('/^\/([a-zA-Z0-9_-]+)$/', $_SERVER['PATH_INFO'], $m)) {
    $post = $m[1];
} else {
    // Try to extract from REQUEST_URI if PATH_INFO is not set
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/journal/posts/([a-zA-Z0-9_-]+)#', $uri, $m)) {
        $post = $m[1];
    }
}

// Redirect /journal/posts (no post ID) to /journal
if ($post === '') {
    header('Location: /journal');
    exit;
}
$post_file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . $post . '.txt';

$title = 'Post not found';
$subtitle = '';
$date = '';
$content_html = '';
$description = '';

if ($post && file_exists($post_file)) {
    $lines = file($post_file, FILE_IGNORE_NEW_LINES);
    $date = htmlspecialchars($lines[0] ?? '', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($lines[1] ?? '', ENT_QUOTES, 'UTF-8');
    $subtitle = htmlspecialchars($lines[2] ?? '', ENT_QUOTES, 'UTF-8');
    $description = $subtitle;
    $body = array_slice($lines, 3);
    // Render post content as HTML (trusted input)
    $content_html = fridg3_embed_plain_video_links_in_html(implode("\n", $body));
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

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}


$content = file_get_contents($content_path);
$content = str_replace('{title}', $title, $content);
$content = str_replace('{subtitle}', $subtitle, $content);
$content = str_replace('{date}', $date, $content);
$editButton = '';
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
if ($isAdmin && $post !== '') {
    $editButton = '<a id="journal-article-edit" href="/journal/edit?post=' . urlencode($post) . '" data-tooltip="edit post"><i class="fa-solid fa-pencil"></i></a>';
}
$content = str_replace('{edit_button}', $editButton, $content);
$content = str_replace('{content}', $content_html, $content);
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
