<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
fridg3_feed_refresh_session_user();

// Require logged-in user
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}

$title = 'edit post';
$description = 'edit your feed post.';
$error = '';
$postFile = null;
$postContent = '';
$postUsername = '';

// Get post filename from query string
$postId = $_GET['post'] ?? '';
if (!$postId) {
    header('Location: /feed');
    exit;
}

$postsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
$postPath = $postsDir . DIRECTORY_SEPARATOR . basename($postId);

// Verify file exists
if (!file_exists($postPath)) {
    header('Location: /feed');
    exit;
}

// Load post and verify permissions
$raw = file_get_contents($postPath);
$lines = preg_split("/(\r\n|\n|\r)/", $raw);
$postUsername = isset($lines[0]) ? ltrim(trim($lines[0]), '@') : '';
$postDate = isset($lines[1]) ? trim($lines[1]) : '';
$postBody = '';
if (count($lines) > 2) {
    $postBody = implode("\n", array_slice($lines, 2));
}

// Check if user can edit (owner or admin)
$currentUser = $_SESSION['user']['username'] ?? '';
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
$canEdit = ($currentUser === $postUsername) || $isAdmin;
$postingRestricted = fridg3_current_user_posting_restricted();

if (!$canEdit) {
    header('Location: /feed');
    exit;
}

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postingRestricted && !isset($_POST['delete'])) {
    header('Location: /feed/edit?post=' . rawurlencode((string)$postId) . '&posting_restricted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if delete action
    if (isset($_POST['delete'])) {
        // Parse post content for images in /data/images and delete them
        $imagesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
        preg_match_all('/\[img=\/data\/images\/([^\]]+)\]/i', $postBody, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $imageFile) {
                $imagePath = $imagesDir . DIRECTORY_SEPARATOR . basename($imageFile);
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                }
            }
        }
        fridg3_feed_delete_post_voice_files(pathinfo(basename($postId), PATHINFO_FILENAME), $postBody);
        
        // Delete the post file
        @unlink($postPath);
        @unlink(fridg3_feed_replies_dir() . DIRECTORY_SEPARATOR . pathinfo(basename($postId), PATHINFO_FILENAME) . '.json');
        
        // Redirect back to feed
        header('Location: /feed');
        exit;
    }
    
    $newContent = trim($_POST['content'] ?? '');
    
    $mediaMap = isset($_FILES['images']) && is_array($_FILES['images'])
        ? fridg3_feed_process_uploaded_media($_FILES['images'])
        : [];
    $newContent = fridg3_feed_replace_media_placeholders($newContent, $mediaMap);
    if (preg_match('/\[(?:media|img|audio|video):\d+\]/i', $newContent) === 1) {
        fridg3_feed_delete_media_files_from_content($newContent);
        header('Location: /feed/edit?post=' . rawurlencode(pathinfo(basename($postId), PATHINFO_FILENAME)) . '&error=' . rawurlencode('media upload failed. files must be supported and no larger than 8 MB.'));
        exit;
    }

    // Update the post file (keep original username and date)
    $text = '@' . $postUsername . PHP_EOL . $postDate . PHP_EOL . $newContent . PHP_EOL;
    file_put_contents($postPath, $text);

    // Redirect back to feed
    header('Location: /feed');
    exit;
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

// Inject post content into the textarea
$escapedBody = htmlspecialchars($postBody, ENT_QUOTES, 'UTF-8');
$content = str_replace('<textarea id="bbcode-textbox" name="content"></textarea>', 
                       '<textarea id="bbcode-textbox" name="content">' . $escapedBody . '</textarea>', 
                       $content);
if ($postingRestricted) {
    $deleteButton = '<button id="two-buttons" type="submit" form="delete-feed-post-form" data-tooltip="this is permanent and cannot be undone!">delete post</button>';
    $content = fridg3_disable_composer_controls($content);
    $content = str_replace(
        '<button disabled id="two-buttons" type="submit" form="delete-feed-post-form" data-tooltip="this is permanent and cannot be undone!">delete post</button>',
        $deleteButton,
        $content
    );
    $content = str_replace('<form id="create-post-form"', fridg3_posting_restriction_notice() . '<form id="create-post-form"', $content);
}

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
