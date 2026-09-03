<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'journal.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}

$currentUsername = $_SESSION['user']['username'] ?? null;
if ($currentUsername !== null) {
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    if (is_file($accountsPath)) {
        $accountsData = json_decode(@file_get_contents($accountsPath), true);
        if (is_array($accountsData) && isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
            foreach ($accountsData['accounts'] as $account) {
                if (isset($account['username']) && $account['username'] === $currentUsername) {
                    $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
                    $_SESSION['user']['postingRestricted'] = (bool)($account['postingRestricted'] ?? false);
                    $_SESSION['user']['allowedPages'] = (array)($account['allowedPages'] ?? []);
                    break;
                }
            }
        }
    }
}

$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
if (!$isAdmin) {
    header('Location: /journal');
    exit;
}

$title = 'edit journal post';
$description = 'edit an existing journal post.';

$postId = $_GET['post'] ?? ($_POST['post'] ?? '');
$postId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$postId);
if ($postId === '') {
    header('Location: /journal');
    exit;
}

$journalDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
$postFile = fridg3_journal_post_path($journalDir, $postId);
if ($postFile === null || !is_file($postFile)) {
    header('Location: /journal');
    exit;
}

$rawPost = @file_get_contents($postFile);
$parsedPost = $rawPost !== false ? fridg3_journal_parse_post($rawPost) : null;
if ($parsedPost === null) {
    header('Location: /journal');
    exit;
}

$postDate = $parsedPost['date'] !== '' ? $parsedPost['date'] : date('Y-m-d');
$postTitle = $parsedPost['title'];
$postSubtitle = $parsedPost['description'];
$postHtml = $parsedPost['body'];
$postCardImage = $parsedPost['cardImage'];
$postFormat = $parsedPost['format'];
$postingRestricted = fridg3_current_user_posting_restricted();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postingRestricted && !isset($_POST['delete'])) {
    header('Location: /journal/edit?post=' . rawurlencode($postId) . '&posting_restricted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete'])) {
        @unlink($postFile);
        header('Location: /journal');
        exit;
    }

    $newTitle = trim((string)($_POST['title'] ?? ''));
    $newDescription = trim((string)($_POST['description'] ?? ''));
    $newContent = (string)($_POST['content'] ?? '');

    $openPreview = isset($_POST['open_preview']);
    $isDraft = isset($_POST['save_draft']) || $openPreview;

    if ($isDraft) {
        $draftsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'drafts';
        if (!is_dir($draftsDir)) {
            @mkdir($draftsDir, 0777, true);
        }

        $baseTitle = $newTitle !== '' ? $newTitle : ('post_' . $postId);
        $safeBase = preg_replace('/[^a-zA-Z0-9]+/', '_', $baseTitle);
        $safeBase = trim($safeBase, '_');
        if ($safeBase === '') {
            $safeBase = 'post_' . $postId;
        }

        $draftFilename = 'edit_' . $postId . '_' . $safeBase . '.txt';
        $draftPath = $draftsDir . DIRECTORY_SEPARATOR . $draftFilename;
        $ownerLine = 'USER:' . $currentUsername;
        $draftText = $ownerLine . PHP_EOL . $newTitle . PHP_EOL . $newDescription . PHP_EOL . 'FORMAT:' . ($postFormat === 'v2' ? 'markdown' : 'html') . PHP_EOL . $newContent;
        @file_put_contents($draftPath, $draftText);

        if ($openPreview) {
            header('Location: /journal/edit/preview?draft=' . urlencode(pathinfo($draftFilename, PATHINFO_FILENAME)) . '&post=' . urlencode($postId));
            exit;
        }

        $postTitle = $newTitle;
        $postSubtitle = $newDescription;
        $postHtml = $newContent;
    } else {
        $imageMap = $postFormat === 'v2' && isset($_FILES['images']) && is_array($_FILES['images'])
            ? fridg3_feed_process_uploaded_media($_FILES['images']) : [];
        $voiceMap = $postFormat === 'v2' && isset($_FILES['voice_notes']) && is_array($_FILES['voice_notes'])
            ? fridg3_feed_process_uploaded_voice_notes($_FILES['voice_notes']) : [];
        if ($postFormat === 'v2') {
            $newContent = fridg3_feed_replace_media_placeholders($newContent, $imageMap, true);
            $newContent = fridg3_feed_replace_voice_placeholders($newContent, $voiceMap, true);
            if (preg_match('/\[(?:media|img|audio|video|voice):\d+\]/i', $newContent) === 1) {
                header('Location: /journal/edit?post=' . rawurlencode($postId) . '&error=' . rawurlencode('one or more media uploads failed.'));
                exit;
            }
        }
        $cardImageUpload = isset($_FILES['card_image']) && is_array($_FILES['card_image'])
            ? fridg3_journal_process_card_image($_FILES['card_image'])
            : ['provided' => false, 'url' => ''];
        if ($cardImageUpload['provided'] && $cardImageUpload['url'] === '') {
            header('Location: /journal/edit?post=' . rawurlencode($postId) . '&error=' . rawurlencode('card image upload failed. use a supported image no larger than 8 MB.'));
            exit;
        }
        $newCardImage = $postCardImage;
        if (isset($_POST['remove_card_image'])) {
            $newCardImage = '';
        }
        if ($cardImageUpload['url'] !== '') {
            $newCardImage = $cardImageUpload['url'];
        }
        $text = $postFormat === 'v2'
            ? fridg3_journal_build_v2_post(date('Y-m-d'), $newTitle, $newDescription, $newContent, $newCardImage)
            : fridg3_journal_build_post($postDate, $newTitle, $newDescription, $newContent, $newCardImage);
        @file_put_contents($postFile, $text);
        header('Location: /journal/posts/' . urlencode($postId));
        exit;
    }
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
    die('page template not found. report this issue to ashton@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to ashton@fridge.dev.');
}

$content = file_get_contents($postFormat === 'v2' ? (__DIR__ . DIRECTORY_SEPARATOR . 'markdown-content.html') : $content_path);
$content = str_replace('{post_id}', htmlspecialchars($postId, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{title_value}', htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{description_value}', htmlspecialchars($postSubtitle, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{content_value}', htmlspecialchars($postHtml, ENT_QUOTES, 'UTF-8'), $content);
$currentCardImageHtml = '';
if ($postCardImage !== '') {
    $currentCardImageHtml = '<span class="journal-current-card-image">current custom image: <a href="'
        . htmlspecialchars($postCardImage, ENT_QUOTES, 'UTF-8')
        . '" target="_blank" rel="noopener">view image</a></span>'
        . '<label class="checkbox-label"><input class="checkbox" type="checkbox" name="remove_card_image" value="1"><span>remove custom card image</span></label>';
}
$content = str_replace('{current_card_image}', $currentCardImageHtml, $content);
if ($postFormat === 'v2') {
    $markdownEditor = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'markdown-editor.html');
    $viewerTemplate = (string)file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 'content.html');
    if (preg_match('/<style>(.*?)<\/style>/s', $viewerTemplate, $viewerStyles)) $markdownEditor = '<style>' . $viewerStyles[1] . '</style>' . $markdownEditor;
    $markdownEditor = str_replace(
        ['{voice_controls}', '{voice_inputs}', '{content_value}'],
        [
            '<button type="button" id="bbcode-voice-btn" class="bbcode-btn bbcode-voice-btn" data-tooltip="record voice note"><i class="fa-solid fa-microphone"></i></button>',
            '<input id="bbcode-voice-input" name="voice_notes[]" type="file" accept="audio/*" multiple hidden><div class="bbcode-voice-recorder" hidden></div>',
            htmlspecialchars($postHtml, ENT_QUOTES, 'UTF-8'),
        ],
        $markdownEditor
    );
    $content = str_replace('{markdown_editor}', $markdownEditor, $content);
}
if ($postingRestricted) {
    $deleteButton = '<button id="two-buttons" type="submit" form="delete-journal-post-form" data-tooltip="this is permanent and cannot be undone!">delete post</button>';
    $content = fridg3_disable_composer_controls($content);
    $content = str_replace(
        '<button disabled id="two-buttons" type="submit" form="delete-journal-post-form" data-tooltip="this is permanent and cannot be undone!">delete post</button>',
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
