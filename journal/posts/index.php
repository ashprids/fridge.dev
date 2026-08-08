<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'journal.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'video-embeds.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 'lib.php';

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
$journal_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
$post_file = fridg3_journal_post_path($journal_dir, $post);

$title = 'Post not found';
$subtitle = '';
$date = '';
$content_html = '';
$description = '';

if ($post && $post_file !== null && file_exists($post_file)) {
    $rawPost = @file_get_contents($post_file);
    $parsedPost = $rawPost !== false ? fridg3_journal_parse_post($rawPost) : null;
    if ($parsedPost !== null) {
        $date = htmlspecialchars($parsedPost['date'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($parsedPost['title'], ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars($parsedPost['description'], ENT_QUOTES, 'UTF-8');
        $description = $subtitle;
        $content_html = $parsedPost['format'] === 'v2'
            ? mdp_render_markdown($parsedPost['markdown'])
            : fridg3_embed_plain_video_links_in_html($parsedPost['body']);
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


$editButton = '';
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
if ($isAdmin && $post !== '') {
    $editButton = '<a id="journal-article-edit" class="journal-mdpaste-edit" href="/journal/edit?post=' . urlencode($post) . '" data-tooltip="edit post" aria-label="edit post"><i class="fa-solid fa-pencil"></i></a>';
}
$isV2 = isset($parsedPost) && is_array($parsedPost) && $parsedPost['format'] === 'v2';
$journalViewerStyles = '<style>'
    . '.journal-mdpaste-edit{display:inline-grid;place-items:center;width:26px;height:26px;padding:0;color:var(--subtle)!important;background:transparent!important;border:0!important;font-size:12px;text-decoration:none;box-shadow:none!important}'
    . '.journal-mdpaste-edit:hover,.journal-mdpaste-edit:focus-visible{color:var(--fg)!important;background:rgba(255,255,255,.06)!important;outline:none}'
    . '.journal-mdpaste-post .mdpaste-article-header,.journal-mdpaste-post #journal-article-header{padding-right:var(--journal-action-clearance,30px);box-sizing:border-box}'
    . '</style>';
$sharedContent = (string)file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 's' . DIRECTORY_SEPARATOR . 'content.html');
if ($isV2) {
    $payload = json_encode(['markdown' => $parsedPost['markdown'], 'filename' => mdp_download_filename($parsedPost['markdown']), 'preserveLayout' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
    $actions = '<div class="mdpaste-paste-actions">'
        . $editButton
        . '<button type="button" id="mdpaste-download" data-tooltip="download file" aria-label="download file"><i class="fa-solid fa-download"></i></button>'
        . '<button type="button" id="mdpaste-font-toggle" data-tooltip="toggle serif font" aria-label="toggle serif font" aria-pressed="false"><i class="fa-solid fa-font"></i></button>'
        . '<button type="button" id="mdpaste-format-toggle" data-tooltip="toggle formatting" aria-label="toggle formatting" aria-pressed="false"><i class="fa-solid fa-code"></i></button>'
        . '</div>';
    $v2Clearance = $editButton !== '' ? 116 : 86;
    $paste = $journalViewerStyles . '<div class="journal-mdpaste-post" style="--journal-action-clearance:' . $v2Clearance . 'px">' . $actions
        . '<article class="mdpaste-markdown" id="mdpaste-formatted">' . $content_html . '</article>'
        . '<pre class="mdpaste-raw-markdown" id="mdpaste-raw" hidden><code>' . mdp_h($parsedPost['markdown']) . '</code></pre>'
        . '<script id="mdpaste-source-data" type="application/json">' . $payload . '</script></div>';
    $content = str_replace('{paste_content}', $paste, $sharedContent);
} else {
    $legacyArticle = file_get_contents($content_path);
    $legacyArticle = str_replace(['{title}', '{subtitle}', '{date}', '{edit_button}', '{content}'], [$title, $subtitle, $date, '', $content_html], $legacyArticle);
    $legacyPayload = json_encode(['markdown' => '', 'filename' => '', 'preserveLayout' => true], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
    $legacyActions = '<div class="mdpaste-paste-actions">'
        . $editButton
        . '<button type="button" id="mdpaste-font-toggle" data-tooltip="toggle serif font" aria-label="toggle serif font" aria-pressed="false"><i class="fa-solid fa-font"></i></button>'
        . '</div>';
    $legacyClearance = $editButton !== '' ? 58 : 30;
    $legacyPaste = $journalViewerStyles . '<div class="journal-mdpaste-post" style="--journal-action-clearance:' . $legacyClearance . 'px">' . $legacyActions . $legacyArticle
        . '<script id="mdpaste-source-data" type="application/json">' . $legacyPayload . '</script></div>';
    $content = str_replace('{paste_content}', $legacyPaste, $sharedContent);
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
