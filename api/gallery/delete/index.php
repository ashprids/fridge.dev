<?php
// Delete images listed in the gallery. Admin-only.

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isset($_SESSION['user']) || empty($_SESSION['user']['isAdmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Accept either JSON payload or form submission
$rawInput = file_get_contents('php://input');
$filename = '';
if ($rawInput !== false && $rawInput !== '') {
    $payload = json_decode($rawInput, true);
    if (is_array($payload) && isset($payload['filename'])) {
        $filename = (string)$payload['filename'];
    }
}

if ($filename === '' && isset($_POST['filename'])) {
    $filename = (string)$_POST['filename'];
}

$filename = trim($filename);
if ($filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_filename']);
    exit;
}

$filename = basename($filename);
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_extension']);
    exit;
}

$rootDir = dirname(__DIR__, 3);
$imagesDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
$realImagesDir = realpath($imagesDir);
if ($realImagesDir === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'images_dir_missing']);
    exit;
}

$filePath = $imagesDir . DIRECTORY_SEPARATOR . $filename;
$realFilePath = realpath($filePath);
if ($realFilePath === false || strpos($realFilePath, $realImagesDir) !== 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_found']);
    exit;
}

if (!is_file($realFilePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_found']);
    exit;
}

if (!@unlink($realFilePath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'delete_failed']);
    exit;
}

$thumbnailPath = $imagesDir . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . hash('sha256', $filename) . '.jpg';
if (is_file($thumbnailPath)) {
    @unlink($thumbnailPath);
}

echo json_encode(['ok' => true]);
