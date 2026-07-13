<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

// Require logged-in user with permission to create posts
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}
// Refresh permissions from accounts.json for the logged-in user so changes
// to allowedPages take effect without requiring a new login
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

// Check if user is admin or has "journal" in allowedPages
$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
$allowedPages = $_SESSION['user']['allowedPages'] ?? [];
$canCreatePost = $isAdmin || in_array('journal', $allowedPages);
$postingRestricted = fridg3_current_user_posting_restricted();

if (!$canCreatePost) {
    header('Location: /journal');
    exit;
}

$title = 'create journal post';
$description = 'create a new post for the journal.';

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

// Convert BBCode to HTML
function bbcode_to_html(string $text): string {
    // Basic BBCode conversions
    $replacements = [
        '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
        '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[s\](.*?)\[\/s\]/is' => '<s>$1</s>',
        '/\[code=([^\]]*)\](.*?)\[\/code\]/is' => '<pre><code class="language-$1">$2</code></pre>',
        '/\[code\](.*?)\[\/code\]/is' => '<pre><code>$1</code></pre>',
        '/\[h3\](.*?)\[\/h3\]/is' => '<h3>$1</h3>',
        '/\[h4\](.*?)\[\/h4\]/is' => '<h4>$1</h4>',
        '/\[h5\](.*?)\[\/h5\]/is' => '<h5>$1</h5>',
    ];

    $html = $text;
    foreach ($replacements as $pattern => $replacement) {
        $html = preg_replace($pattern, $replacement, $html);
    }

    // Handle color tags [color=#hex] or [color:#hex]
    $html = preg_replace_callback('/\[color[:=]([^\]]+)\](.*?)\[\/color\]/is', function($m) {
        $color = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        $content = $m[2];
        return '<span style="color: ' . $color . ';">' . $content . '</span>';
    }, $html);

    // Handle list tags [list]item1\nitem2[/list]
    $html = preg_replace_callback('/\[list\](.*?)\[\/list\]/is', function($m) {
        $inner = $m[1];
        $lines = preg_split('/\r\n|\r|\n/', $inner);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $items[] = '<li>' . $line . '</li>';
        }
        if (empty($items)) {
            return '';
        }
        return '<ul>' . implode('', $items) . '</ul>';
    }, $html);

    // Handle image tags [img=url][name:name] with optional closing [/img]
    // Render as <img id="post-image" src="..." alt="name-or-filename">
    $html = preg_replace_callback('/\[img=([^\]]+)\](?:\[name:([^\]]*)\])?(?:\[\/img\])?/i', function($m) {
        $rawUrl = $m[1];
        $url = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');

        if (isset($m[2]) && strlen(trim($m[2])) > 0) {
            $altSource = trim($m[2]);
        } else {
            $altSource = basename($rawUrl);
        }
        $alt = htmlspecialchars($altSource, ENT_QUOTES, 'UTF-8');

        return '<img id="post-image" src="' . $url . '" alt="' . $alt . '">';
    }, $html);

    // Handle spoiler tags [spoiler]...[/spoiler]
    $html = preg_replace_callback('/\[spoiler\](.*?)\[\/spoiler\]/is', function($m) {
        return '<span class="spoiler">' . $m[1] . '</span>';
    }, $html);

    // Handle tooltip tags [tooltip=text]content[/tooltip]
    $html = preg_replace_callback('/\[tooltip=([^\]]+)\](.*?)\[\/tooltip\]/is', function($m) {
        $tooltip = htmlspecialchars($m[1], ENT_COMPAT, 'UTF-8');
        $content = $m[2];
        return '<span data-tooltip="' . $tooltip . '">' . $content . '</span>';
    }, $html);

    // Handle link tags [link=url]text[/link] or [url=url]text[/url]
    $html = preg_replace_callback('/\[(link|url)=([^\]]+)\](.*?)\[\/(link|url)\]/is', function($m) {
        $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        $text = $m[3];
        return '<a href="' . $url . '">' . $text . '</a>';
    }, $html);

    // Extract headings and code blocks to preserve newlines
    $preserved = [];
    $html = preg_replace_callback('/<h[345][^>]*>.*?<\/h[345]>|<pre><code[^>]*>.*?<\/code><\/pre>/is', function($m) use (&$preserved) {
        $placeholder = '___PRESERVE_' . count($preserved) . '___';
        $preserved[$placeholder] = $m[0];
        return $placeholder;
    }, $html);

    // Convert newlines to <br> tags (no XHTML self-closing)
    $html = preg_replace('/\r\n|\r|\n/', '<br>', $html);

    // Restore preserved blocks with original newlines
    foreach ($preserved as $placeholder => $content) {
        $html = str_replace($placeholder, $content, $html);
    }

    // Do not allow <br> directly after h3 or h4 headings
    $html = preg_replace('/(<h3[^>]*>.*?<\/h3>)(?:<br\s*\/?\s*>)+/is', '$1', $html);
    $html = preg_replace('/(<h4[^>]*>.*?<\/h4>)(?:<br\s*\/?\s*>)+/is', '$1', $html);

    // Remove a single <br> directly after each h5 heading (leave any additional spacing)
    $html = preg_replace('/<\/h5><br\s*\/?\s*>/i', '</h5>', $html);

    // Remove a single <br> immediately before any h3 heading (collapse extra blank line)
    $html = preg_replace('/<br\s*\/?\s*>\s*(<h3[^>]*>)/i', '$1', $html);

    return $html;
}

// Handle create-post submission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $postingRestricted
    && trim((string)($_POST['delete_draft'] ?? '')) === ''
) {
    header('Location: /journal/create?posting_restricted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Require logged-in user
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
        header('Location: /account/login');
        exit;
    }

    $username = $_SESSION['user']['username'];
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content = trim($_POST['content'] ?? '');

    $openPreview = isset($_POST['open_preview']);
    $isDraft = isset($_POST['save_draft']) || $openPreview;
    $deleteDraftId = isset($_POST['delete_draft']) ? trim($_POST['delete_draft']) : '';

    // Handle draft deletion: delete draft file, then redirect
    // Image deletion is commented out since images may be shared by multiple drafts 
    // and posts; they can be manually removed if needed
    if ($deleteDraftId !== '') {
        $rootDir = dirname(__DIR__, 2);
        $draftsRoot = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'drafts';
        // $imagesRoot = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $deleteDraftId);
        if ($safeId !== '') {
            $target = $draftsRoot . DIRECTORY_SEPARATOR . $safeId . '.txt';
            if (is_file($target)) {
                // Read draft lines to determine owner (new format: first line "USER:username")
                $lines = @file($target, FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    $lines = [];
                }

                $ownerUsername = null;
                if (isset($lines[0]) && strncmp($lines[0], 'USER:', 5) === 0) {
                    $ownerUsername = substr($lines[0], 5);
                }

                $sessionUsername = $_SESSION['user']['username'] ?? null;
                $sessionIsAdmin = $_SESSION['user']['isAdmin'] ?? false;

                $allowedToDelete = $sessionIsAdmin || ($ownerUsername !== null && $sessionUsername !== null && $ownerUsername === $sessionUsername);

                // For legacy drafts without owner info, only admins may delete
                if (!$allowedToDelete) {
                    header('Location: /journal/create');
                    exit;
                }

                /* $draftContent = implode(PHP_EOL, $lines);
                if ($draftContent !== '' && is_dir($imagesRoot)) {
                    if (preg_match_all('#/data/images/([A-Za-z0-9_\-\.]+)#', $draftContent, $m)) {
                        foreach ($m[1] as $imgFile) {
                            $imgPath = $imagesRoot . DIRECTORY_SEPARATOR . $imgFile;
                            if (is_file($imgPath)) {
                                @unlink($imgPath);
                            }
                        }
                    }
                } */

                @unlink($target);
            }
        }
        header('Location: /journal/create');
        exit;
    }

    // Prepare images directory and process uploads (shared by posts and drafts)
    $imagesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images'; // /data/images
    if (!is_dir($imagesDir)) {
        @mkdir($imagesDir, 0777, true);
    }

    // Save uploaded images (support multiple) with compression
    $imageMap = [];
    if (isset($_FILES['images']) && isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        $count = count($_FILES['images']['name']);
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];

        for ($i = 0; $i < $count; $i++) {
            $error = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) continue;

            $tmpPath = $_FILES['images']['tmp_name'][$i];
            if (!is_uploaded_file($tmpPath)) continue;

            $origName = $_FILES['images']['name'][$i] ?? ('image_' . $i);
            $imageInfo = @getimagesize($tmpPath);
            $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
            if (!isset($allowed[$mime])) continue;

            $ext = $allowed[$mime];
            $sizeBytes = @filesize($tmpPath) ?: 0;
            $mustJpeg = ($mime === 'image/png');
            $mustCompress = $mustJpeg || ($sizeBytes > 1000000);
            $randomBase = bin2hex(random_bytes(8));
            $destExt = $mustCompress ? 'jpg' : $ext;
            $destName = $randomBase . '.' . $destExt;
            $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;

            $saved = false;
            if ($mustCompress) {
                $saved = save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            } else {
                $saved = @move_uploaded_file($tmpPath, $destPath);
            }

            // Fallback: try compressing to JPEG if initial move failed or size still too large
            $finalSize = $saved ? (@filesize($destPath) ?: 0) : 0;
            if (!$saved || $finalSize > 1000000) {
                @unlink($destPath);
                $destExt = 'jpg';
                $destName = $randomBase . '.jpg';
                $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;
                $saved = save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            }

            if ($saved) {
                $imageMap[$i] = [
                    'url' => '/data/images/' . $destName,
                    'name' => $origName ?: $destName,
                ];
            }
        }
    }

    // Save draft: /data/journal/drafts/[title_with_underscores].txt
    if ($isDraft) {
        $draftsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'drafts';
        if (!is_dir($draftsDir)) {
            @mkdir($draftsDir, 0777, true);
        }

        $baseTitle = $title !== '' ? $title : 'untitled';
        $safeBase = preg_replace('/[^a-zA-Z0-9]+/', '_', $baseTitle);
        $safeBase = trim($safeBase, '_');
        if ($safeBase === '') {
            $safeBase = 'untitled';
        }
        $draftFilename = $safeBase . '.txt';
        $draftPath = $draftsDir . DIRECTORY_SEPARATOR . $draftFilename;

        // For drafts, update BBCode image placeholders to point at uploaded files
        $draftContent = $content;
        if (!empty($imageMap)) {
            $draftContent = preg_replace_callback('/\[img:(\d+)\](?:\[name:([^\]]*)\])?/i', function($m) use ($imageMap) {
                $idx = (int)$m[1];
                if (!isset($imageMap[$idx])) {
                    return $m[0];
                }
                $name = isset($m[2]) && strlen(trim($m[2])) ? trim($m[2]) : ($imageMap[$idx]['name'] ?? 'image');
                return '[img=' . $imageMap[$idx]['url'] . '][name:' . $name . ']';
            }, $draftContent);
        }

        // Line 1: owner (USER:username), line 2: title, line 3: description, remaining: BBCode body (with image URLs)
        $ownerLine = 'USER:' . $username;
        $draftText = $ownerLine . PHP_EOL . $title . PHP_EOL . $description . PHP_EOL . $draftContent;
        @file_put_contents($draftPath, $draftText);

        if ($openPreview) {
            header('Location: /journal/create/preview?draft=' . urlencode(pathinfo($draftFilename, PATHINFO_FILENAME)));
            exit;
        }

        // For drafts, skip full post creation and fall through to template rendering
    } else {

    // Prepare posts directory
    $postsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal'; // /data/journal
    if (!is_dir($postsDir)) {
        @mkdir($postsDir, 0777, true);
    }
    // Timestamp for filename and display
    $postDate = date('Y-m-d');

    // Find the next incrementing number for the post file
    $files = @scandir($postsDir);
    $maxNum = 0;
    if (is_array($files)) {
        foreach ($files as $file) {
            if (preg_match('/^(\d+)\.txt$/', $file, $matches)) {
                $num = (int)$matches[1];
                if ($num > $maxNum) {
                    $maxNum = $num;
                }
            }
        }
    }
    $nextNum = $maxNum + 1;
    $postFilename = $nextNum . '.txt';

    // Build post file content
    $safeContent = $content; // store raw; renderer can sanitize/format later
    // Replace client-side image placeholders [img:index][name:...] with saved server paths
    if (!empty($imageMap)) {
        $safeContent = preg_replace_callback('/\[img:(\d+)\](?:\[name:([^\]]*)\])?/i', function($m) use ($imageMap) {
            $idx = (int)$m[1];
            if (!isset($imageMap[$idx])) {
                return $m[0];
            }
            $name = isset($m[2]) && strlen(trim($m[2])) ? trim($m[2]) : ($imageMap[$idx]['name'] ?? 'image');
            return '[img=' . $imageMap[$idx]['url'] . '][name:' . $name . '][/img]';
        }, $safeContent);
    }
    // Convert BBCode to HTML
    $htmlContent = bbcode_to_html($safeContent);
    $text = $postDate . PHP_EOL . $title . PHP_EOL . $description . PHP_EOL . $htmlContent . PHP_EOL;
    $postFile = $postsDir . DIRECTORY_SEPARATOR . $postFilename;
    file_put_contents($postFile, $text);

    // Redirect to feed after posting
    header('Location: /feed');
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

// Load drafts from /data/journal/drafts for display under the editor
$draftsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'drafts';
$draftItems = '';
if (is_dir($draftsDir)) {
    $draftFiles = glob($draftsDir . DIRECTORY_SEPARATOR . '*.txt');
    // Newest first by file modification time
    usort($draftFiles, function($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    $sessionUsername = $_SESSION['user']['username'] ?? null;
    $sessionIsAdmin = $_SESSION['user']['isAdmin'] ?? false;

    foreach ($draftFiles as $draftFile) {
        $lines = @file($draftFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 2) continue;

        // Detect new-format drafts with owner line "USER:username"
        $ownerUsername = null;
        $offset = 0;
        if (isset($lines[0]) && strncmp($lines[0], 'USER:', 5) === 0) {
            $ownerUsername = substr($lines[0], 5);
            $offset = 1;
        }

        // Require at least title and description after any owner line
        if (!isset($lines[$offset + 1])) {
            continue;
        }

        $draftTitle = htmlspecialchars($lines[$offset] ?? '', ENT_QUOTES, 'UTF-8');
        $draftDescription = htmlspecialchars($lines[$offset + 1] ?? '', ENT_QUOTES, 'UTF-8');
        $bodyLines = array_slice($lines, $offset + 2);
        $draftBodyRaw = implode(PHP_EOL, $bodyLines);
        $draftBodyAttr = htmlspecialchars($draftBodyRaw, ENT_QUOTES, 'UTF-8');

        // Only show delete icon for draft owner or admins
        $canDeleteThisDraft = $sessionIsAdmin || ($ownerUsername !== null && $sessionUsername !== null && $ownerUsername === $sessionUsername);

        $draftId = basename($draftFile, '.txt');
        $deleteIcon = '';
        if ($canDeleteThisDraft) {
            $deleteIcon = '<span id="post-edit-feed" data-tooltip="delete draft" style="cursor:pointer !important" data-draft-id="' . htmlspecialchars($draftId, ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-trash"></i></span>';
        }

        $draftItems .= '<div id="post">'
            . '<div id="post-header">'
            . '<span id="post-title">' . $draftTitle . '</span>'
            . '<span id="post-date-feed">' . $deleteIcon . '</span>'
            . '</div>'
            . '<a class="journal-draft-link" href="#" '
            . 'data-draft-title="' . $draftTitle . '" '
            . 'data-draft-description="' . $draftDescription . '" '
            . 'data-draft-content="' . $draftBodyAttr . '">'
            . '<span id="post-description">' . $draftDescription . '</span>'
            . '</a>'
            . '</div><br>' . "\n";
    }
}

$content = file_get_contents($content_path);
// Replace {drafts} placeholder with rendered draft list
$content = str_replace('{drafts}', $draftItems, $content);
if ($postingRestricted) {
    $content = fridg3_disable_composer_controls($content);
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
