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
if (empty($_SESSION['user']['isAdmin'])) {
    header('Location: /music');
    exit;
}

$title = 'upload music';
$description = 'upload a music release.';
$musicUploadArtists = [
    'frdg3' => 'frdg3',
    'cactile' => 'Cactile',
];
$releaseTypes = ['Single', 'Remix', 'Album'];
$uploadError = '';

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

function music_upload_slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? $value : 'release';
}

function music_upload_unique_path(string $dir, string $baseName, string $extension): string {
    $baseName = music_upload_slugify($baseName);
    $extension = strtolower(trim($extension, '.'));
    $path = $dir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
    $i = 2;
    while (file_exists($path)) {
        $path = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $i . '.' . $extension;
        $i++;
    }
    return $path;
}

function music_upload_max_order(string $albumsDir): int {
    $max = 0;
    if (!is_dir($albumsDir)) {
        return $max;
    }
    foreach (glob($albumsDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $albumFile) {
        $data = json_decode((string)@file_get_contents($albumFile), true);
        if (is_array($data) && isset($data['order'])) {
            $max = max($max, (int)$data['order']);
        }
    }
    return $max;
}

function music_upload_file_at(array $files, int $index): ?array {
    if (!isset($files['name'][$index])) {
        return null;
    }
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function music_upload_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    if ($unit === 'g') {
        $number *= 1024;
    }
    if ($unit === 'g' || $unit === 'm') {
        $number *= 1024;
    }
    if ($unit === 'g' || $unit === 'm' || $unit === 'k') {
        $number *= 1024;
    }

    return (int)$number;
}

function music_upload_format_bytes(int $bytes): string {
    if ($bytes <= 0) {
        return 'unlimited';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    $precision = $value >= 10 || $unitIndex === 0 ? 0 : 1;
    return number_format($value, $precision) . ' ' . $units[$unitIndex];
}

function music_upload_empty_post_error(): string {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxSize = (string)ini_get('post_max_size');
    $uploadMaxFilesize = (string)ini_get('upload_max_filesize');
    $postMaxBytes = music_upload_ini_bytes($postMaxSize);
    $limitHint = 'PHP currently reports post_max_size=' . $postMaxSize
        . ' (' . music_upload_format_bytes($postMaxBytes) . ') and upload_max_filesize=' . $uploadMaxFilesize . '.';

    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        return 'the upload is larger than PHP accepts before this page can read it. '
            . $limitHint . ' Start the local PHP server with larger -d upload_max_filesize and -d post_max_size values, or deploy the included .user.ini/nginx changes.';
    }

    return 'the upload did not reach this page with form data. '
        . $limitHint . ' If you are using a local PHP server, open /music/upload/ and make sure the server is started with unlimited upload and post sizes.';
}

function music_upload_cover_art(array $upload, string $albumName, string $imagesDir, string &$error): ?array {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'the cover art upload failed.';
        return null;
    }

    $tmpPath = (string)($upload['tmp_name'] ?? '');
    $size = (int)($upload['size'] ?? 0);
    $originalName = (string)($upload['name'] ?? 'cover');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }
    $allowedExtensions = ['jpg', 'png', 'gif', 'webp'];
    if ($tmpPath === '' || $size <= 0 || $size > 12000000 || !is_uploaded_file($tmpPath)) {
        $error = 'the cover art file is invalid or too large.';
        return null;
    }
    if (!in_array($extension, $allowedExtensions, true) || @getimagesize($tmpPath) === false) {
        $error = 'cover art must be jpg, png, gif, or webp.';
        return null;
    }

    $coverPath = music_upload_unique_path($imagesDir, $albumName . '-cover', $extension);
    if (!move_uploaded_file($tmpPath, $coverPath)) {
        $error = 'could not save the uploaded cover art.';
        return null;
    }

    return [
        'path' => $coverPath,
        'webPath' => '/data/images/' . basename($coverPath),
    ];
}

function music_upload_old(string $key, string $default = ''): string {
    return htmlspecialchars((string)($_POST[$key] ?? $_GET[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}

function music_upload_schedule_value(string $value, string &$error): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        $error = 'publish date/time must be a valid date and time.';
        return '';
    }

    return $date->format(DateTimeInterface::ATOM);
}

function music_upload_track_rows(array $trackNames): string {
    if (!$trackNames) {
        $trackNames = [''];
    }
    $html = '';
    foreach (array_values($trackNames) as $index => $trackName) {
        $safeTrackName = htmlspecialchars((string)$trackName, ENT_QUOTES, 'UTF-8');
        $number = $index + 1;
        $html .= '<div class="music-upload-track" data-music-track-row>'
            . '<div class="music-upload-track-handle" data-music-track-number>' . $number . '</div>'
            . '<label>track title<input type="text" name="track_names[]" value="' . $safeTrackName . '" required></label>'
            . '<label>audio file<input type="file" name="audio[]" accept=".mp3,.wav,.m4a,.ogg,.flac,audio/*" required></label>'
            . '<div class="music-upload-track-actions">'
            . '<button id="two-buttons" class="music-upload-action-button" type="button" data-music-track-up>up</button>'
            . '<button id="two-buttons" class="music-upload-action-button" type="button" data-music-track-down>down</button>'
            . '<button id="two-buttons" class="music-upload-action-button" type="button" data-music-track-remove>remove</button>'
            . '</div>'
            . '</div>';
    }
    return $html;
}

function music_upload_handle(array $artists, array $releaseTypes, string &$error): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (empty($_POST) && empty($_FILES)) {
        $error = music_upload_empty_post_error();
        return;
    }
    if (($_POST['music_upload_action'] ?? '') !== 'upload') {
        return;
    }

    $artistKey = strtolower(trim((string)($_POST['artist'] ?? '')));
    $albumName = trim((string)($_POST['album_name'] ?? ''));
    $albumType = trim((string)($_POST['album_type'] ?? 'Single'));
    $albumCaption = trim((string)($_POST['album_caption'] ?? ''));
    $scheduledAt = music_upload_schedule_value((string)($_POST['scheduled_at'] ?? ''), $error);
    if ($error !== '') {
        return;
    }
    $albumArt = '';
    $trackNames = array_values(array_map('trim', (array)($_POST['track_names'] ?? [])));
    $files = $_FILES['audio'] ?? null;
    $coverUpload = is_array($_FILES['cover_art_upload'] ?? null) ? $_FILES['cover_art_upload'] : null;
    $hasCoverUpload = $coverUpload !== null && (int)($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!isset($artists[$artistKey])) {
        $error = 'choose a valid artist.';
        return;
    }
    if ($albumName === '') {
        $error = 'release title is required.';
        return;
    }
    if (!in_array($albumType, $releaseTypes, true)) {
        $error = 'choose a valid release type.';
        return;
    }
    if (!$hasCoverUpload) {
        $error = 'cover art upload is required.';
        return;
    }
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        $error = 'choose at least one audio file.';
        return;
    }

    $trackCount = count($trackNames);
    if ($trackCount < 1) {
        $error = 'add at least one track.';
        return;
    }

    $root = dirname(__DIR__, 2);
    $audioDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'audio';
    $imagesDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
    $albumsDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'music' . DIRECTORY_SEPARATOR . $artistKey;
    if (
        (!is_dir($audioDir) && !mkdir($audioDir, 0775, true))
        || (!is_dir($imagesDir) && !mkdir($imagesDir, 0775, true))
        || (!is_dir($albumsDir) && !mkdir($albumsDir, 0775, true))
    ) {
        $error = 'could not prepare music storage.';
        return;
    }

    $savedCoverPath = '';
    if ($coverUpload !== null) {
        $cover = music_upload_cover_art($coverUpload, $albumName, $imagesDir, $error);
        if ($error !== '') {
            return;
        }
        if ($cover !== null) {
            $savedCoverPath = $cover['path'];
            $albumArt = $cover['webPath'];
        }
    }

    $savedPaths = [];
    $songs = [];
    $allowedExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
    for ($i = 0; $i < $trackCount; $i++) {
        $trackName = $trackNames[$i] !== '' ? $trackNames[$i] : ($albumType === 'Album' ? 'Track ' . ($i + 1) : $albumName);
        $audio = music_upload_file_at($files, $i);
        if ($audio === null || (int)($audio['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'choose an audio file for track ' . ($i + 1) . '.';
            break;
        }
        if ((int)($audio['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $error = 'the audio upload failed for track ' . ($i + 1) . '.';
            break;
        }

        $tmpPath = (string)($audio['tmp_name'] ?? '');
        $size = (int)($audio['size'] ?? 0);
        $originalName = (string)($audio['name'] ?? 'track');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($tmpPath === '' || $size <= 0 || !is_uploaded_file($tmpPath)) {
            $error = 'track ' . ($i + 1) . ' is invalid.';
            break;
        }
        if (!in_array($extension, $allowedExtensions, true)) {
            $error = 'track ' . ($i + 1) . ' must be mp3, wav, m4a, ogg, or flac.';
            break;
        }

        $audioPath = music_upload_unique_path($audioDir, $albumName . '-' . ($i + 1) . '-' . $trackName, $extension);
        if (!move_uploaded_file($tmpPath, $audioPath)) {
            $error = 'could not save track ' . ($i + 1) . '.';
            break;
        }

        $savedPaths[] = $audioPath;
        $songs[] = [
            'name' => $trackName,
            'directory' => '/data/audio/' . basename($audioPath),
        ];
    }

    if ($error !== '') {
        foreach ($savedPaths as $path) {
            @unlink($path);
        }
        if ($savedCoverPath !== '') {
            @unlink($savedCoverPath);
        }
        return;
    }

    $jsonPath = music_upload_unique_path($albumsDir, $albumName, 'json');
    $order = music_upload_max_order($albumsDir) + 1;
    $release = [
        'album_name' => $albumName,
        'album_caption' => $albumCaption,
        'album_type' => $albumType,
        'album_art' => $albumArt,
        'album_art_directory' => $albumArt,
        'order' => $order,
        'songs' => $songs,
    ];
    if ($scheduledAt !== '') {
        $release['scheduled_at'] = $scheduledAt;
    }

    $json = json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (@file_put_contents($jsonPath, $json, LOCK_EX) === false) {
        foreach ($savedPaths as $path) {
            @unlink($path);
        }
        if ($savedCoverPath !== '') {
            @unlink($savedCoverPath);
        }
        $error = 'could not write the release metadata.';
        return;
    }

    header('Location: /music?uploaded=' . rawurlencode($artistKey));
    exit;
}

music_upload_handle($musicUploadArtists, $releaseTypes, $uploadError);

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

$user_name = htmlspecialchars($_SESSION['user']['name'] ?? $_SESSION['user']['username'], ENT_QUOTES, 'UTF-8');
$user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
$template = str_replace('{user_greeting}', $user_greeting, $template);
$accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
$logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
$template = str_replace($accountBtn, $logoutBtn, $template);

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}
$content = file_get_contents($content_path);

$selectedArtist = strtolower(trim((string)($_POST['artist'] ?? $_GET['artist'] ?? 'frdg3')));
if (!isset($musicUploadArtists[$selectedArtist])) {
    $selectedArtist = 'frdg3';
}
$selectedType = trim((string)($_POST['album_type'] ?? $_GET['album_type'] ?? 'Single'));
if (!in_array($selectedType, $releaseTypes, true)) {
    $selectedType = 'Single';
}

$artistOptions = '';
foreach ($musicUploadArtists as $key => $label) {
    $artistOptions .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"'
        . ($key === $selectedArtist ? ' selected' : '')
        . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
}

$releaseTypeOptions = '';
foreach ($releaseTypes as $type) {
    $releaseTypeOptions .= '<option value="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"'
        . ($type === $selectedType ? ' selected' : '')
        . '>' . htmlspecialchars(strtolower($type), ENT_QUOTES, 'UTF-8') . '</option>';
}

$notice = $uploadError !== ''
    ? '<div class="music-upload-notice music-upload-error">' . htmlspecialchars($uploadError, ENT_QUOTES, 'UTF-8') . '</div>'
    : '';
$trackNames = array_values(array_map('strval', (array)($_POST['track_names'] ?? [''])));

$replacements = [
    '{music_upload_notice}' => $notice,
    '{artist_options}' => $artistOptions,
    '{release_type_options}' => $releaseTypeOptions,
    '{album_name}' => music_upload_old('album_name'),
    '{album_caption}' => music_upload_old('album_caption'),
    '{scheduled_at}' => music_upload_old('scheduled_at'),
    '{track_rows}' => music_upload_track_rows($trackNames),
];

$content = strtr($content, $replacements);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
