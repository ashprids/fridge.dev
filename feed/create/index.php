<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

// Require logged-in user with permission to create posts
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}

// Check if user is admin or has "feed" in allowedPages
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
$allowedPages = $_SESSION['user']['allowedPages'] ?? [];
$canCreatePost = $isAdmin || in_array('feed', $allowedPages);
$isToast = fridg3_toast_is_current_user();

if (!$canCreatePost) {
    header('Location: /feed');
    exit;
}

$title = 'create feed post';
$description = 'create a new post for the feed.';
$error = trim((string)($_GET['error'] ?? ''));

// Compress to JPEG under the provided byte limit; always flattens transparency to white
function save_jpeg_under_limit(string $srcPath, string $mime, string $destPath, int $maxBytes = 1000000): bool {
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $createMap = [
        'image/png' => function($p) { return @imagecreatefrompng($p); },
        'image/jpeg' => function($p) { return @imagecreatefromjpeg($p); },
        'image/gif' => function($p) { return function_exists('imagecreatefromgif') ? @imagecreatefromgif($p) : false; },
        'image/webp' => function($p) { return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($p) : false; },
    ];

    if (!isset($createMap[$mime])) {
        return false;
    }

    $img = $createMap[$mime]($srcPath);
    if (!$img) {
        return false;
    }

    $width = imagesx($img);
    $height = imagesy($img);
    $canvas = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);
    imagedestroy($img);

    $tmpPath = tempnam(sys_get_temp_dir(), 'img');
    if ($tmpPath === false) {
        imagedestroy($canvas);
        return false;
    }

    $quality = 90;
    do {
        imagejpeg($canvas, $tmpPath, $quality);
        $size = @filesize($tmpPath);
        if ($size !== false && $size <= $maxBytes) {
            break;
        }
        $quality -= 5;
    } while ($quality >= 40);

    imagedestroy($canvas);
    $finalSize = @filesize($tmpPath);
    if ($finalSize === false || $finalSize > $maxBytes) {
        @unlink($tmpPath);
        return false;
    }

    $moved = @rename($tmpPath, $destPath);
    if (!$moved) {
        @unlink($tmpPath);
    }
    return $moved;
}

// Handle create-post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Require logged-in user
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
        header('Location: /account/login');
        exit;
    }

    $username = $_SESSION['user']['username'];
    $content = trim($_POST['content'] ?? '');

    // Prepare directories
    $postsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed'; // /data/feed
    if (!is_dir($postsDir)) {
        @mkdir($postsDir, 0777, true);
    }

    // Timestamp for filename and display
    $timestampFilename = date('Y-m-d_H-i-s');
    $displayDateTime = date('Y-m-d H:i:s');

    $imageMap = isset($_FILES['images']) && is_array($_FILES['images'])
        ? fridg3_feed_process_uploaded_images($_FILES['images'])
        : [];
    $voiceMap = isset($_FILES['voice_notes']) && is_array($_FILES['voice_notes'])
        ? fridg3_feed_process_uploaded_voice_notes($_FILES['voice_notes'])
        : [];

    // Build post file content
    $safeContent = $content; // store raw; renderer can sanitize/format later
    $safeContent = fridg3_feed_replace_image_placeholders($safeContent, $imageMap);
    $safeContent = fridg3_feed_replace_voice_placeholders($safeContent, $voiceMap);
    if (preg_match('/\[voice:\d+\]/i', $safeContent) === 1) {
        foreach ($voiceMap as $voice) {
            fridg3_feed_delete_voice_files_from_content('[audio=' . ($voice['url'] ?? '') . ']');
        }
        header('Location: /feed/create?error=' . rawurlencode('voice note failed. keep it under 2 minutes and try again.'));
        exit;
    }
    $text = '@' . $username . PHP_EOL . $displayDateTime . PHP_EOL . $safeContent . PHP_EOL;
    $postFile = $postsDir . DIRECTORY_SEPARATOR . $timestampFilename . '.txt';
    file_put_contents($postFile, $text);

    $shouldQueueToastAutoReply = (
        strcasecmp((string)$username, 'toast') !== 0
        && fridg3_toast_feed_mentions_toast($safeContent)
    );

    // Send Discord webhook notification
    $webhooksFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'webhooks.json';
    if (file_exists($webhooksFile)) {
        $webhooksData = json_decode(file_get_contents($webhooksFile), true);
        $discordWebhookUrl = $webhooksData['discord_feed'] ?? null;
        
        if ($discordWebhookUrl && strpos($discordWebhookUrl, 'https://discord.com/api/webhooks/') === 0) {
            $postLink = 'https://fridge.dev/feed/posts/' . $timestampFilename;
            $discordMessage = "<@&1408064770891972660> new post by **@" . $username . "**\n" . $postLink;
            
            $payload = json_encode([
                'content' => $discordMessage
            ]);
            
            $ch = curl_init($discordWebhookUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            @curl_exec($ch);
            curl_close($ch);
        }
    }

    // Redirect to feed after posting
    header('Location: /feed/');
    if ($shouldQueueToastAutoReply) {
        $toastReplyPostId = $timestampFilename;
        $toastReplyPostUsername = (string)$username;
        $toastReplyPostDate = $displayDateTime;
        $toastReplyPostBody = $safeContent;
        fridg3_toast_run_auto_reply_after_response(static function () use (
            $toastReplyPostId,
            $toastReplyPostUsername,
            $toastReplyPostDate,
            $toastReplyPostBody
        ): void {
            fridg3_toast_maybe_auto_reply_to_feed(
                $toastReplyPostId,
                $toastReplyPostUsername,
                $toastReplyPostDate,
                $toastReplyPostBody,
                [
                    'username' => $toastReplyPostUsername,
                    'body' => $toastReplyPostBody,
                ]
            );
        });
    }
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
if ($error !== '') {
    $content = '<div id="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div><br>' . $content;
}
if ($isToast) {
    $toastGeneratorControls = '<div id="toast-feed-generator">'
        . '<h3>inspiration</h3>'
        . '<div class="radio-group">'
        . '<label class="radio-label"><input type="radio" class="radio" name="toast-feed-mode" value="random" checked><span>random</span></label>'
        . '<label class="radio-label"><input type="radio" class="radio" name="toast-feed-mode" value="prompt"><span>prompt</span></label>'
        . '</div>'
        . '<textarea id="toast-feed-prompt" rows="5" placeholder="input prompt..." style="display:none"></textarea>'
        . '<label class="toast-feed-length-control" for="toast-feed-length">'
        . '<span>length</span>'
        . '<input id="toast-feed-length" type="range" min="1" max="5" step="1" value="3">'
        . '<output id="toast-feed-length-label" for="toast-feed-length">normal</output>'
        . '</label>'
        . '<br>'
        . '<button id="form-button" type="button" data-action="toast-generate-feed">write</button>'
        . '<span id="toast-feed-generator-status" style="color: var(--subtle); margin-left: 0.75rem;"></span>'
        . '</div><br>';

    $content = str_replace('<button type="button"', '<button type="button" disabled', $content);
    $content = str_replace('class="bbcode-btn"', 'class="bbcode-btn disabled"', $content);
    $content = str_replace('<select id="bbcode-header-dropdown"', '<select id="bbcode-header-dropdown" disabled', $content);
    $content = str_replace('<input id="bbcode-image-input"', '<input id="bbcode-image-input" disabled', $content);
    $content = str_replace('<input id="bbcode-voice-input"', '<input id="bbcode-voice-input" disabled', $content);
    $content = str_replace(
        '<textarea id="bbcode-textbox" name="content"></textarea>',
        '<textarea id="bbcode-textbox" name="content" readonly placeholder=""></textarea>',
        $content
    );
    $content = str_replace(
        '<button id="form-button" type="submit">post</button>',
        '<button id="form-button" type="submit" data-toast-post-button="1" disabled>post</button>',
        $content
    );
    $content = str_replace('<div class="bbcode-editor">', $toastGeneratorControls . '<div class="bbcode-editor">', $content);
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
