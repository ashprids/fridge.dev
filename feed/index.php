<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';

$title = 'feed';
$description = 'short snippets and updates.';
$pageSizeDefault = 10;

function render_feed_pagination(int $currentPage, int $totalPages, string $searchQuery): string {
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
            $items .= '<a class="' . $class . '" href="/feed?page=' . $i . $query . '">' . $i . '</a>';
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

$content = file_get_contents($content_path);
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$isSearch = ($searchQuery !== '');
$isUsernameSearch = ($isSearch && strpos($searchQuery, '@') === 0);
$searchUsername = $isUsernameSearch ? ltrim($searchQuery, '@') : '';

// Build posts listing from /data/feed and inject into #posts div
$postsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
$postsHtml = '';
$paginationHtml = '';
$postsData = [];

// Load existing bookmarks for the logged-in user so we can
// render bookmark icons in the correct filled/empty state.
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
            // IDs in the file are already normalized by the API
            $userBookmarks = array_values(array_unique(array_map('strval', $data['bookmarks'])));
        }
    }
}

// Check if user can create posts (admin or has "feed" in allowedPages)
$canCreatePost = false;
if (isset($_SESSION['user'])) {
    $isAdmin = $_SESSION['user']['isAdmin'] ?? false;
    $allowedPages = $_SESSION['user']['allowedPages'] ?? [];
    $canCreatePost = $isAdmin || in_array('feed', $allowedPages);
}

// Remove create post button if user doesn't have permission
if (!$canCreatePost) {
    $content = preg_replace('/<a href="\/feed\/create">\s*<button[^>]*id="form-button"[^>]*>create post<\/button>\s*<\/a>/i', '', $content);
    $content = preg_replace('/<button[^>]*id="form-button"[^>]*>create post<\/button>/i', '', $content);
}

// Preserve search query in the search box
if ($searchQuery !== '') {
    $content = preg_replace('/<input id="search-box"([^>]*)>/i', '<input id="search-box"$1 value="' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . '">', $content, 1);
}

if (is_dir($postsDir)) {
    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    // Sort newest first based on filename or mtime
    usort($files, function($a, $b) {
        return strcmp(basename($b), basename($a));
    });

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) continue;
        $lines = preg_split("/(\r\n|\n|\r)/", $raw);
        $usernameLine = isset($lines[0]) ? trim($lines[0]) : '';
        $dateLine = isset($lines[1]) ? trim($lines[1]) : '';
        $body = '';
        if (count($lines) > 2) {
            $body = implode("\n", array_slice($lines, 2));
        }

        // Normalize username (strip leading @ if present)
        $username = ltrim($usernameLine, '@');

        $postsData[] = [
            'file' => $file,
            'username' => $username,
            'date' => $dateLine,
            'body' => $body,
        ];
    }

    // Write index.toml alongside posts
    $indexPath = $postsDir . DIRECTORY_SEPARATOR . 'index.toml';
    $toml = '';
    foreach ($postsData as $p) {
        $bodyToml = str_replace('"""', '\\"""', $p['body']);
        $toml .= "[[post]]\n";
        $toml .= 'file = "' . str_replace('"', '\\"', basename($p['file'])) . '"' . "\n";
        $toml .= 'username = "' . str_replace('"', '\\"', $p['username']) . '"' . "\n";
        $toml .= 'date = "' . str_replace('"', '\\"', $p['date']) . '"' . "\n";
        $toml .= 'body = """' . $bodyToml . '"""' . "\n\n";
    }
    @file_put_contents($indexPath, $toml);

    // Apply search filter
    if ($isSearch) {
        if ($isUsernameSearch && $searchUsername !== '') {
            // Username search (exact, case-insensitive)
            $postsData = array_values(array_filter($postsData, function($p) use ($searchUsername) {
                return strcasecmp($p['username'], $searchUsername) === 0;
            }));
        } else {
            // Text search: look for the query in username or body (case-insensitive)
            $needle = strtolower($searchQuery);
            $postsData = array_values(array_filter($postsData, function($p) use ($needle) {
                $hayUsername = strtolower($p['username']);
                $hayBody = strtolower($p['body']);
                return (strpos($hayUsername, $needle) !== false) || (strpos($hayBody, $needle) !== false);
            }));
        }
    }

    // Pagination (10 posts per page, 50 for username search)
    $perPage = ($isUsernameSearch && $searchUsername !== '') ? 50 : $pageSizeDefault;
    $totalPosts = count($postsData);
    $totalPages = max(1, (int)ceil($totalPosts / $perPage));
    $currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    if ($currentPage > $totalPages) $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $perPage;
    $pagedPosts = array_slice($postsData, $offset, $perPage);

    foreach ($pagedPosts as $p) {
        $username = $p['username'];
        $dateLine = $p['date'];
        $body = $p['body'];

        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $ago = fridg3_feed_humanize_datetime($dateLine);
        $safeAgo = htmlspecialchars($ago, ENT_QUOTES, 'UTF-8');
        // Escape body so browser treats it as text; BBCode parser will transform later
        $safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

        // Determine if current user can edit this post (owner or admin)
        $canEdit = false;
        if (isset($_SESSION['user'])) {
            $currentUser = $_SESSION['user']['username'] ?? '';
            $isAdmin = $_SESSION['user']['isAdmin'] ?? false;
            $canEdit = ($currentUser === $username) || $isAdmin;
        }

        // Build edit icon if allowed
        $editIcon = '';
        $postId = urlencode(basename($p['file'], '.txt'));
        $postIdRaw = basename($p['file'], '.txt');
        $replyCount = count(fridg3_feed_load_replies($postIdRaw));
        $replyMeta = '<span class="feed-reply-count"><i class="fa-regular fa-comment"></i> ' . $replyCount . '</span>';

        // Determine if this post is bookmarked for the current user
        $isBookmarked = in_array($postIdRaw, $userBookmarks, true);
        $bookmarkIconClass = $isBookmarked ? 'fa-solid' : 'fa-regular';
        if ($canEdit) {
            // Use a span with a data-edit-href attribute instead of a nested
            // anchor so we don't produce invalid <a><a> markup inside the
            // outer feed-post link. JS will handle navigation.
            $editIcon = '<span id="post-edit-feed" data-tooltip="edit post" style="cursor:pointer !important" data-edit-href="/feed/edit?post=' . $postId . '.txt"><i class="fa-solid fa-pencil"></i></span> ';
        }

        $postLink = '/feed/posts/' . $postId;

        // Wrap each post in an anchor so SPA navigation can intercept
        // the click; avoid inline JS redirects so the mini player
        // stays alive.
        $postsHtml .= '<a href="' . $postLink . '" class="feed-post-link" style="text-decoration:none;color:inherit;">'
            . '<div id="post" style="cursor: pointer;">'
            . '<div id="post-header">'
            . '<span id="post-username">@' . $safeUser . '</span>'
            . '<span id="post-date-feed">' . $safeAgo . ' • ' . $replyMeta . ' • ' . $editIcon . '<span id="post-bookmark-feed" data-tooltip="save post" data-post-id="' . $postId . '"><i class="' . $bookmarkIconClass . ' fa-bookmark"></i></span></span>'
            . '</div>'
            . '<span id="post-content">' . $safeBody . '</span>'
            . '</div>'
            . '</a>';
    }

    $paginationHtml = render_feed_pagination($currentPage, $totalPages, $searchQuery);
}

// Replace the example posts block with generated posts
if ($postsHtml !== '' || $searchQuery !== '' || !empty($postsData)) {
    $content = preg_replace('/<div id="posts">[\s\S]*?<\/div>/i', '<div id="posts">' . $postsHtml . '</div>' . $paginationHtml, $content, 1);
}

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
