<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'journal';
$description = 'long-form updates and blog posts.';
$pageSizeDefault = 10;
// Refresh permissions from accounts.json for the logged-in user so changes
// to allowedPages take effect without requiring a new login
if (isset($_SESSION['user']) && isset($_SESSION['user']['username'])) {
    $accountsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
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

// Permission helpers
$isLoggedIn = isset($_SESSION['user']) && isset($_SESSION['user']['username']);
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
$allowedPages = $_SESSION['user']['allowedPages'] ?? [];
$canCreateJournal = $isLoggedIn && ($isAdmin || in_array('journal', $allowedPages));

function render_journal_pagination(int $currentPage, int $totalPages, string $searchQuery): string {
    if ($totalPages <= 1) {
        return '';
    }
    $items = '';
    for ($i = 1; $i <= $totalPages; $i++) {
        $isCurrent = $i === $currentPage;
        $class = 'guestbook-page-btn' . ($isCurrent ? ' current' : '');
        $aria = $isCurrent ? ' aria-current="page"' : '';
        $query = $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '';
        if ($isCurrent) {
            $items .= '<span class="' . $class . '"' . $aria . '>' . $i . '</span>';
        } else {
            $items .= '<a class="' . $class . '" href="/journal?page=' . $i . $query . '">' . $i . '</a>';
        }
    }
    return '<div class="guestbook-pagination">' . $items . '</div>';
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

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}


// Build journal grid from /data/journal
$posts_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
$perPage = $pageSizeDefault;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$searchQuery = trim($_GET['q'] ?? '');

// Load user bookmarks if logged in
$userBookmarks = [];
if (isset($_SESSION['user']) && !empty($_SESSION['user']['username'])) {
    $rootDir = dirname(__DIR__);
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

$post_items = '';
$paginationHtml = '';
if (is_dir($posts_dir)) {
    $post_files = glob($posts_dir . DIRECTORY_SEPARATOR . '*.txt');
    $posts = [];

    // Load basic metadata (date, title, description) for each post
    foreach ($post_files as $pf) {
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

    // Filter by search query against title/description
    if ($searchQuery !== '') {
        $needle = strtolower($searchQuery);
        $posts = array_values(array_filter($posts, function($post) use ($needle) {
            $haystack = strtolower(($post['title'] ?? '') . ' ' . ($post['description'] ?? ''));
            return strpos($haystack, $needle) !== false;
        }));
    }

    // Sort by post date (first line) descending, then by filename as a tiebreaker
    usort($posts, function($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp(basename($b['path']), basename($a['path']));
        }
        return $b['timestamp'] <=> $a['timestamp'];
    });

    $totalPosts = count($posts);
    $totalPages = max(1, (int)ceil($totalPosts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $postsPage = array_slice($posts, $offset, $perPage);

    foreach ($postsPage as $post) {
        $post_date = htmlspecialchars($post['date'] ?? '', ENT_QUOTES, 'UTF-8');
        $post_title = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $post_description = htmlspecialchars($post['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $filename = basename($post['path'], '.txt');
        $safeFilename = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
        $bookmarkId = 'journal:' . $filename;
        $isBookmarked = in_array($bookmarkId, $userBookmarks, true);
        $iconClass = $isBookmarked ? 'fa-solid' : 'fa-regular';
        $actionsHtml = '<span id="journal-post-actions" style="position:absolute;top:12px;right:12px;display:inline-flex;align-items:center;gap:8px;">';
        if ($isAdmin) {
            $actionsHtml .= '<span id="post-edit-feed" data-tooltip="edit post" data-edit-href="/journal/edit?post=' . urlencode($filename) . '" style="color: var(--subtle); font-size: 12px;"><i class="fa-solid fa-pencil"></i></span>';
        }
        $actionsHtml .= '<span id="post-bookmark" style="position:static;top:auto;right:auto;" data-tooltip="save post" data-post-id="journal:' . $safeFilename . '"><i class="' . $iconClass . ' fa-bookmark"></i></span>';
        $actionsHtml .= '</span>';
        $item = '<a id="post" class="journal-post-link" href="/journal/posts/' . urlencode($filename) . '">' 
            . '<span id="post-date">' . $post_date . '</span>'
            . $actionsHtml
            . '<span id="post-title">' . $post_title . '</span>'
            . '<span id="post-description">' . $post_description . '</span>'
            . '</a>';
        $post_items .= $item . "\n";
    }

    $paginationHtml = render_journal_pagination($page, $totalPages, $searchQuery);
}

$content = file_get_contents($content_path);
// Hide create button unless user can create journal posts
if (!$canCreateJournal) {
    $content = preg_replace('#<a[^>]*href="/journal/create"[^>]*>.*?</a>#is', '', $content);
}

// Point search form to /journal and retain search value
$content = preg_replace('#<form id="search"[^>]*action="/feed"#i', '<form id="search" action="/journal"', $content);
$searchQueryEsc = htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
$content = preg_replace('#<input id="search-box" name="q" type="text" placeholder="search\.\.\.">#i', '<input id="search-box" name="q" type="text" placeholder="search..." value="' . $searchQueryEsc . '">', $content);
// Replace the posts grid with generated items
$content = preg_replace('/<div id="posts">.*?<\/div>/s', '<div id="posts">' . $post_items . '</div>' . $paginationHtml, $content);

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
