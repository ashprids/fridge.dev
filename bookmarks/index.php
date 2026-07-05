<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'saves';
$description = 'see all the stuff you\'ve saved.';


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

// Generate user greeting if logged in
$user_greeting = '';
if (isset($_SESSION['user'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    
    // Swap Account button to Logout
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
    $template = str_replace($accountBtn, $logoutBtn, $template);
}

// Replace user greeting placeholder
$template = str_replace('{user_greeting}', $user_greeting, $template);

// Build bookmarks list if user is logged in and has a bookmarks file
$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);

// Container we will replace with generated posts

$feedBookmarksHtml = '<div id="bookmarks-feed-list">';
$journalBookmarksHtml = '<div id="bookmarks-journal-list">';

// Only attempt server-side bookmarks if logged in
if (isset($_SESSION['user']) && !empty($_SESSION['user']['username'])) {
    $rootDir = dirname(__DIR__);
    $accountsFile = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $username = (string)$_SESSION['user']['username'];
    $bookmarkIds = [];
    if (is_file($accountsFile)) {
        $json = @file_get_contents($accountsFile);
        $accountsData = json_decode($json, true);
        if (isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
            foreach ($accountsData['accounts'] as $acct) {
                if (isset($acct['username']) && $acct['username'] === $username && isset($acct['bookmarks']) && is_array($acct['bookmarks'])) {
                    $bookmarkIds = array_values(array_unique(array_map('strval', $acct['bookmarks'])));
                    break;
                }
            }
        }
    }
    if (!empty($bookmarkIds)) {
        $postsDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';

            // Simple humanized time difference (same logic as /feed)
            $humanize = function($dtStr) {
                try {
                    $dt = new DateTime($dtStr);
                    $now = new DateTime('now');
                    $diff = $now->getTimestamp() - $dt->getTimestamp();
                    if ($diff < 60) return $diff . 's ago';
                    if ($diff < 3600) return floor($diff / 60) . 'm ago';
                    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
                    return $dt->format('Y-m-d');
                } catch (Exception $e) {
                    return $dtStr;
                }
            };

            foreach ($bookmarkIds as $id) {
                $isJournal = false;
                $basename = basename($id);
                if (strpos($id, 'journal:') === 0) {
                    $isJournal = true;
                    $basename = substr($id, 8); // after 'journal:'
                } elseif (strpos($id, 'newsletter:') === 0) {
                    continue;
                }
                if ($isJournal) {
                    $postFile = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . $basename . '.txt';
                } else {
                    $postFile = $postsDir . DIRECTORY_SEPARATOR . $basename . '.txt';
                }
                if (!is_file($postFile)) continue;

                $raw = @file_get_contents($postFile);
                if ($raw === false) continue;
                if ($isJournal) {
                    $lines = preg_split("/(\r\n|\n|\r)/", $raw);
                    $dateLine = isset($lines[0]) ? trim($lines[0]) : '';
                    $titleLine = isset($lines[1]) ? trim($lines[1]) : '';
                    $descLine = isset($lines[2]) ? trim($lines[2]) : '';
                    $safeTitle = htmlspecialchars($titleLine, ENT_QUOTES, 'UTF-8');
                    $safeDate = htmlspecialchars($dateLine, ENT_QUOTES, 'UTF-8');
                    $safeDesc = htmlspecialchars($descLine, ENT_QUOTES, 'UTF-8');
                    $postId = 'journal:' . htmlspecialchars($basename, ENT_QUOTES, 'UTF-8');
                    $postLink = '/journal/posts/' . rawurlencode($basename);
                    $journalBookmarksHtml .= '<a href="' . $postLink . '" class="journal-post-link" style="text-decoration:none;color:inherit;">'
                        . '<div id="post" style="cursor: pointer;">'
                        . '<span id="post-date">' . $safeDate . '</span>'
                        . '<span id="post-bookmark" data-tooltip="save post" data-post-id="' . $postId . '"><i class="fa-solid fa-bookmark"></i></span>'
                        . '<span id="post-title">' . $safeTitle . '</span>'
                        . '<span id="post-description">' . $safeDesc . '</span>'
                        . '</div>'
                        . '</a><br>';
                } else {
                    $lines = preg_split("/(\r\n|\n|\r)/", $raw);
                    $usernameLine = isset($lines[0]) ? trim($lines[0]) : '';
                    $dateLine = isset($lines[1]) ? trim($lines[1]) : '';
                    $body = '';
                    if (count($lines) > 2) {
                        $body = implode("\n", array_slice($lines, 2));
                    }
                    $postUsername = ltrim($usernameLine, '@');
                    $safeUserName = htmlspecialchars($postUsername, ENT_QUOTES, 'UTF-8');
                    $humanDate = $humanize($dateLine);
                    $safeDate = htmlspecialchars($humanDate, ENT_QUOTES, 'UTF-8');
                    $safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
                    $postId = htmlspecialchars($basename, ENT_QUOTES, 'UTF-8');
                    $postLink = '/feed/posts/' . rawurlencode($basename);
                    $feedBookmarksHtml .= '<a href="' . $postLink . '" class="feed-post-link" style="text-decoration:none;color:inherit;">'
                        . '<div id="post" style="cursor: pointer;">'
                        . '<div id="post-header">'
                        . '<span id="post-username">@' . $safeUserName . '</span>'
                        . '<span id="post-date-feed">' . $safeDate . ' • <span id="post-bookmark-feed" data-tooltip="save post" data-post-id="' . $postId . '"><i class="fa-solid fa-bookmark"></i></span></span>'
                        . '</div>'
                        . '<span id="post-content">' . $safeBody . '</span>'
                        . '</div>'
                        . '</a><br>';
                }
            }
        }
    }
// removed unmatched closing brace here

if ($feedBookmarksHtml === '<div id="bookmarks-feed-list">') {
    $feedBookmarksHtml .= 'you haven\'t saved any feed posts yet';
}
$feedBookmarksHtml .= '</div>';
if ($journalBookmarksHtml === '<div id="bookmarks-journal-list">') {
    $journalBookmarksHtml .= 'you haven\'t saved any journal posts yet';
}
$journalBookmarksHtml .= '</div>';

// Replace both containers
$content = preg_replace('/<div id="bookmarks-feed-list">[\s\S]*?<\/div>/i', $feedBookmarksHtml, $content, 1);
$content = preg_replace('/<div id="bookmarks-journal-list">[\s\S]*?<\/div>/i', $journalBookmarksHtml, $content, 1);

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
