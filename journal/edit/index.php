<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

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

$postFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . $postId . '.txt';
if (!is_file($postFile)) {
    header('Location: /journal');
    exit;
}

$lines = @file($postFile, FILE_IGNORE_NEW_LINES);
if ($lines === false || count($lines) < 3) {
    header('Location: /journal');
    exit;
}

$postDate = (string)($lines[0] ?? date('Y-m-d'));
$postTitle = (string)($lines[1] ?? '');
$postSubtitle = (string)($lines[2] ?? '');
$postHtml = implode("\n", array_slice($lines, 3));

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
        $draftText = $ownerLine . PHP_EOL . $newTitle . PHP_EOL . $newDescription . PHP_EOL . 'FORMAT:html' . PHP_EOL . $newContent;
        @file_put_contents($draftPath, $draftText);

        if ($openPreview) {
            header('Location: /journal/edit/preview?draft=' . urlencode(pathinfo($draftFilename, PATHINFO_FILENAME)) . '&post=' . urlencode($postId));
            exit;
        }

        $postTitle = $newTitle;
        $postSubtitle = $newDescription;
        $postHtml = $newContent;
    } else {
        $text = $postDate . PHP_EOL . $newTitle . PHP_EOL . $newDescription . PHP_EOL . $newContent . PHP_EOL;
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
$content = str_replace('{post_id}', htmlspecialchars($postId, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{title_value}', htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{description_value}', htmlspecialchars($postSubtitle, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{content_value}', htmlspecialchars($postHtml, ENT_QUOTES, 'UTF-8'), $content);
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
