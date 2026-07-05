<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

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

$title = 'homepage';
$description = 'welcome to fridge.dev, a personal website and sometimes even an online community.';

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);

// Inject latest 3 feed posts into {latest_feed}
$postsDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
$latestFeedHtml = '';

if (is_dir($postsDir)) {
    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    usort($files, function($a, $b) {
        return strcmp(basename($b), basename($a));
    });

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

    // Load user bookmarks for current user (if any)
    $userBookmarks = [];
    if (isset($_SESSION['user']) && !empty($_SESSION['user']['username'])) {
        $rootDir = __DIR__;
        $usersDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'users';
        $username = (string)$_SESSION['user']['username'];
        $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $username);
        $userFile = $usersDir . DIRECTORY_SEPARATOR . $safeUsername . '.json';
        if (is_file($userFile)) {
            $json = @file_get_contents($userFile);
            $data = json_decode($json, true);
            if (is_array($data) && isset($data['bookmarks']) && is_array($data['bookmarks'])) {
                $userBookmarks = array_values(array_unique(array_map('strval', $data['bookmarks'])));
            }
        }
    }

    // Determine edit permission
    $canEdit = function($postUsername) {
        if (!isset($_SESSION['user'])) return false;
        $currentUser = $_SESSION['user']['username'] ?? '';
        $isAdmin = $_SESSION['user']['isAdmin'] ?? false;
        return ($currentUser === $postUsername) || $isAdmin;
    };

    $latestFeedHtml .= '<div id="posts">';

    $count = 0;
    foreach ($files as $file) {
        if ($count >= 1) break;
        $raw = @file_get_contents($file);
        if ($raw === false) continue;
        $lines = preg_split("/(\r\n|\n|\r)/", $raw);
        $usernameLine = isset($lines[0]) ? trim($lines[0]) : '';
        $dateLine = isset($lines[1]) ? trim($lines[1]) : '';
        $body = '';
        if (count($lines) > 2) {
            $body = implode("\n", array_slice($lines, 2));
        }

        $username = ltrim($usernameLine, '@');
        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $ago = $humanize($dateLine);
        $safeAgo = htmlspecialchars($ago, ENT_QUOTES, 'UTF-8');
        $safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

        $postId = urlencode(basename($file, '.txt'));
        $postIdRaw = basename($file, '.txt');
        $isBookmarked = in_array($postIdRaw, $userBookmarks, true);
        $bookmarkIconClass = $isBookmarked ? 'fa-solid' : 'fa-regular';

        $editIcon = '';
        if ($canEdit($username)) {
            $editIcon = '<span id="post-edit-feed" data-tooltip="edit post" style="cursor:pointer !important" data-edit-href="/feed/edit?post=' . $postId . '.txt"><i class="fa-solid fa-pencil"></i></span> • ';
        }

        $postLink = '/feed/posts/' . $postId;

        $latestFeedHtml .= '<a href="' . $postLink . '" class="feed-post-link" style="text-decoration:none;color:inherit;">'
            . '<div id="post" style="cursor: pointer;">'
            . '<div id="post-header">'
            . '<span id="post-username">@' . $safeUser . '</span>'
            . '<span id="post-date-feed">' . $safeAgo . ' • ' . $editIcon . '<span id="post-bookmark-feed" data-tooltip="save post" data-post-id="' . $postId . '"><i class="' . $bookmarkIconClass . ' fa-bookmark"></i></span></span>'
            . '</div>'
            . '<span id="post-content">' . $safeBody . '</span>'
            . '</div>'
            . '</a>';

        $count++;
    }

    if ($count === 0) {
        $latestFeedHtml .= '<div id="post"><span id="post-content">no feed posts yet.</span></div>';
    }

    $latestFeedHtml .= '</div>';
}

$content = str_replace('{latest_feed}', $latestFeedHtml, $content);

// Inject latest 3 journal posts into {latest_journal}
$journalDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
$latestJournalHtml = '';

// Load user bookmarks (shared with feed logic)
$userBookmarks = [];
if (isset($_SESSION['user']) && !empty($_SESSION['user']['username'])) {
    $rootDir = __DIR__;
    $usersDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'users';
    $username = (string)$_SESSION['user']['username'];
    $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $username);
    $userFile = $usersDir . DIRECTORY_SEPARATOR . $safeUsername . '.json';
    if (is_file($userFile)) {
        $json = @file_get_contents($userFile);
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['bookmarks']) && is_array($data['bookmarks'])) {
            $userBookmarks = array_values(array_unique(array_map('strval', $data['bookmarks'])));
        }
    }
}

if (is_dir($journalDir)) {
    $postFiles = glob($journalDir . DIRECTORY_SEPARATOR . '*.txt');
    $posts = [];

    foreach ($postFiles as $pf) {
        $lines = @file($pf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) < 3) {
            continue;
        }
        $dateStr = trim($lines[0]);
        $postTitle = $lines[1] ?? '';
        $desc = $lines[2] ?? '';
        $ts = strtotime($dateStr);
        if ($ts === false) {
            $ts = 0;
        }
        $posts[] = [
            'path' => $pf,
            'date' => $dateStr,
            'timestamp' => $ts,
            'title' => $postTitle,
            'description' => $desc,
        ];
    }

    usort($posts, function($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp(basename($b['path']), basename($a['path']));
        }
        return $b['timestamp'] <=> $a['timestamp'];
    });

    $latestJournalHtml .= '<div id="posts">';

    $count = 0;
    foreach ($posts as $post) {
        if ($count >= 1) break;
        $post_date = htmlspecialchars($post['date'] ?? '', ENT_QUOTES, 'UTF-8');
        $post_title = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $post_description = htmlspecialchars($post['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $filename = basename($post['path'], '.txt');
        $bookmarkId = 'journal:' . $filename;
        $isBookmarked = in_array($bookmarkId, $userBookmarks, true);
        $iconClass = $isBookmarked ? 'fa-solid' : 'fa-regular';

        $latestJournalHtml .= '<a id="post" class="journal-post-link" href="/journal/posts/' . urlencode($filename) . '" style="text-decoration:none;color:inherit;">' 
            . '<span id="post-date">' . $post_date . '</span>'
            . '<span id="post-bookmark" data-tooltip="save post" data-post-id="journal:' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '"><i class="' . $iconClass . ' fa-bookmark"></i></span>'
            . '<span id="post-title">' . $post_title . '</span>'
            . '<span id="post-description">' . $post_description . '</span>'
            . '</a>';

        $count++;
    }

    if ($count === 0) {
        $latestJournalHtml .= '<div id="post"><span id="post-content">no journal posts yet.</span></div>';
    }

    $latestJournalHtml .= '</div>';
}

$content = str_replace('{latest_journal}', $latestJournalHtml, $content);

// Inject latest 3 music releases into {latest_music} (frdg3 only)
$musicDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'music' . DIRECTORY_SEPARATOR . 'frdg3';
$latestMusicHtml = '';

if (is_dir($musicDir)) {
    $albumFiles = glob($musicDir . DIRECTORY_SEPARATOR . '*.json');
    $albums = [];

    foreach ($albumFiles as $albumFile) {
        $json = @file_get_contents($albumFile);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            continue;
        }

        $album_name = htmlspecialchars($data['album_name'] ?? basename($albumFile, '.json'), ENT_QUOTES, 'UTF-8');
        $album_caption = htmlspecialchars($data['album_caption'] ?? '', ENT_QUOTES, 'UTF-8');
        $album_type = htmlspecialchars($data['album_type'] ?? '', ENT_QUOTES, 'UTF-8');
        $album_art_value = $data['album_art_directory'] ?? ($data['album_art'] ?? '');
        $album_art = htmlspecialchars($album_art_value, ENT_QUOTES, 'UTF-8');
        $songs = (isset($data['songs']) && is_array($data['songs'])) ? $data['songs'] : [];
        $songs_json = htmlspecialchars(json_encode($songs), ENT_QUOTES, 'UTF-8');
        $order = isset($data['order']) ? (int)$data['order'] : 0;

        $albums[] = [
            'order' => $order,
            'name' => $album_name,
            'caption' => $album_caption,
            'type' => $album_type,
            'art' => $album_art,
            'songs' => $songs_json,
        ];
    }

    usort($albums, function ($a, $b) {
        if ($a['order'] === $b['order']) {
            return strcmp($a['name'], $b['name']);
        }
        return $b['order'] <=> $a['order'];
    });

    $latestMusicHtml .= '<div id="grid">';

    $count = 0;
    foreach ($albums as $album) {
        if ($count >= 3) break;
        $latestMusicHtml .= '<a href="#" class="album-link" data-no-viewer="1"'
            . ' data-album-name="' . $album['name'] . '"'
            . ' data-album-type="' . $album['type'] . '"'
            . ' data-album-art="' . $album['art'] . '"'
            . ' data-album-artist="frdg3"'
            . ' data-album-tracks="' . $album['songs'] . '">'
            . '<div class="grid-item">'
            . '<img class="grid-image" src="' . $album['art'] . '" alt="' . $album['name'] . '">'
            . '<div class="grid-caption">' . $album['name'] . '</div>'
            . '<div class="grid-subcaption">' . $album['type'] . '<br>' . $album['caption'] . '</div>'
            . '</div></a>';
        $count++;
    }

    if ($count === 0) {
        $latestMusicHtml .= '<div class="grid-item">no music releases yet.</div>';
    }

    $latestMusicHtml .= '</div>';
}

$content = str_replace('{latest_music}', $latestMusicHtml, $content);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
