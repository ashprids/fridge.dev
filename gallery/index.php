<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'gallery';
$description = 'a listing of all images uploaded to the site.';
$isAdmin = isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;

function gallery_thumbnail_path(string $sourcePath, string $thumbnailDir): ?string
{
    if (!is_dir($thumbnailDir) && !@mkdir($thumbnailDir, 0777, true)) return null;
    $thumbnailName = hash('sha256', basename($sourcePath)) . '.jpg';
    $thumbnailPath = $thumbnailDir . DIRECTORY_SEPARATOR . $thumbnailName;
    $sourceMtime = @filemtime($sourcePath) ?: 0;
    if (is_file($thumbnailPath) && ((@filemtime($thumbnailPath) ?: 0) >= $sourceMtime)) return $thumbnailPath;

    $info = @getimagesize($sourcePath);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
    ];
    if (function_exists('imagecreatetruecolor') && isset($loaders[$mime]) && function_exists($loaders[$mime])) {
        $source = @$loaders[$mime]($sourcePath);
        if (!$source) return null;
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return null;
        }
        $cropSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int)floor(($sourceWidth - $cropSize) / 2);
        $sourceY = (int)floor(($sourceHeight - $cropSize) / 2);
        $thumbnail = imagecreatetruecolor(500, 500);
        $background = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $background);
        imagecopyresampled($thumbnail, $source, 0, 0, $sourceX, $sourceY, 500, 500, $cropSize, $cropSize);
        $saved = @imagejpeg($thumbnail, $thumbnailPath, 68);
        imagedestroy($thumbnail);
        imagedestroy($source);
        return $saved ? $thumbnailPath : null;
    }

    // Production already requires ffmpeg for voice notes. Use it when PHP GD is
    // unavailable so the gallery never silently falls back to full-size files.
    $ffmpeg = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ffmpeg === '') return null;
    $filter = 'scale=500:500:force_original_aspect_ratio=increase,crop=500:500';
    $command = escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -loglevel error -y -i '
        . escapeshellarg($sourcePath) . ' -vf ' . escapeshellarg($filter)
        . ' -frames:v 1 -q:v 8 ' . escapeshellarg($thumbnailPath) . ' 2>&1';
    @exec($command, $output, $status);
    if ($status !== 0 || !is_file($thumbnailPath)) {
        @unlink($thumbnailPath);
        return null;
    }
    return $thumbnailPath;
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

// Pagination: 21 images per page
$perPage = 21;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$content = file_get_contents($content_path);

// Build gallery grid from /data/images, ordered by date added (newest first)
$imagesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
$thumbnailDir = $imagesDir . DIRECTORY_SEPARATOR . 'thumbnails';
$gridItems = '';
$paginationHtml = '';
if (is_dir($imagesDir)) {
    $imageFiles = glob($imagesDir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);

    $images = [];
    foreach ($imageFiles as $path) {
        if (!is_file($path)) continue;
        $mtime = @filemtime($path);
        if ($mtime === false) {
            $mtime = 0;
        }
        $size = @getimagesize($path);
        $width = is_array($size) && isset($size[0]) ? (int)$size[0] : null;
        $height = is_array($size) && isset($size[1]) ? (int)$size[1] : null;
        $images[] = [
            'path' => $path,
            'mtime' => $mtime,
            'width' => $width,
            'height' => $height,
        ];
    }

    // Sort by modification time (proxy for date added), newest first
    usort($images, function($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });

    $totalImages = count($images);
    $totalPages = max(1, (int)ceil($totalImages / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $pageImages = array_slice($images, $offset, $perPage);

    foreach ($pageImages as $img) {
        $filename = basename($img['path']);
        $url = '/data/images/' . rawurlencode($filename);
        $thumbnailPath = gallery_thumbnail_path($img['path'], $thumbnailDir);
        $thumbnailUrl = $thumbnailPath !== null
            ? '/data/images/thumbnails/' . rawurlencode(basename($thumbnailPath))
            : $url;
        $alt = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
        if ($img['width'] && $img['height']) {
            $resolution = $img['width'] . ' x ' . $img['height'];
        } else {
            $resolution = '';
        }

        $deleteForm = '';
        if ($isAdmin) {
            $deleteForm = '<form class="grid-delete-form" action="/api/gallery/delete/index.php" method="POST">'
                . '<input type="hidden" name="filename" value="' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" class="grid-delete-button" aria-label="delete ' . $alt . '"><i class="fa-solid fa-trash"></i> delete</button>'
                . '</form>';
        }

        $gridItems .= '<div class="grid-item">'
            . '<img class="grid-image" src="' . $thumbnailUrl . '" data-full-src="' . $url . '" alt="' . $alt . '" loading="lazy" decoding="async">'
            . '<div class="grid-caption">' . htmlspecialchars($resolution, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="grid-subcaption">' . $alt . '</div>'
            . $deleteForm
            . '</div>' . "\n";
    }

    if ($totalPages > 1) {
        $items = $page > 1
            ? '<a class="guestbook-page-btn pagination-arrow" href="/gallery?page=' . ($page - 1) . '#content-footer" aria-label="previous page">&lsaquo;</a>'
            : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&lsaquo;</span>';
        $items .= '<span class="guestbook-page-btn current" aria-current="page">' . $page . '</span>';
        $items .= $page < $totalPages
            ? '<a class="guestbook-page-btn pagination-arrow" href="/gallery?page=' . ($page + 1) . '#content-footer" aria-label="next page">&rsaquo;</a>'
            : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&rsaquo;</span>';
        $paginationHtml = '<nav class="guestbook-pagination content-pagination" aria-label="gallery pages" data-pagination-route="/gallery" data-pagination-current="' . $page . '" data-pagination-total="' . $totalPages . '" data-pagination-search="">' . $items . '</nav>';
    }
}

// Replace static grid markup with generated items and append pagination
if ($gridItems !== '') {
    $content = preg_replace('/<div id="grid">.*?<\/div>/s', '<div id="grid">' . $gridItems . '</div>' . $paginationHtml, $content);
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
