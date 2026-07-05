<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';

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

if (!$canEdit) {
    header('Location: /feed');
    exit;
}

// Handle POST submission
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
    
    // Handle image upload (same logic as create-post)
    $imageUrl = null;
    $imageDisplayName = null;
    $imagesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
    if (!is_dir($imagesDir)) {
        @mkdir($imagesDir, 0777, true);
    }

    if (isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['image']['tmp_name'];
        $origName = $_FILES['image']['name'] ?? '';
        $imageInfo = @getimagesize($tmpPath);
        $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        if (isset($allowed[$mime])) {
            $ext = $allowed[$mime];
            $rand = bin2hex(random_bytes(8));
            $destName = $rand . '.' . $ext;
            $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;
            @move_uploaded_file($tmpPath, $destPath);
            $imageUrl = '/data/images/' . $destName;
            $imageDisplayName = $origName ?: $destName;
        }
    }

    // Replace image placeholders if image was uploaded
    if ($imageUrl) {
        $nameFallback = htmlspecialchars($imageDisplayName ?? basename($imageUrl), ENT_QUOTES, 'UTF-8');
        $newContent = preg_replace_callback('/\[img:\d+\](?:\[name:([^\]]*)\])?/i', function($m) use ($imageUrl, $nameFallback) {
            $name = isset($m[1]) && strlen(trim($m[1])) ? trim($m[1]) : $nameFallback;
            return '[img=' . $imageUrl . '][name:' . $name . ']';
        }, $newContent);
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
