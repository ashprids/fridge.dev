<?php
// API endpoint to track unique page views

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$rawPath = isset($payload['path']) ? (string)$payload['path'] : '/';
$parsedPath = parse_url($rawPath, PHP_URL_PATH);
$normalizedPath = is_string($parsedPath) ? $parsedPath : '/';
$normalizedPath = trim($normalizedPath);
if ($normalizedPath === '') {
    $normalizedPath = '/';
}
if ($normalizedPath[0] !== '/') {
    $normalizedPath = '/' . $normalizedPath;
}
$normalizedPath = preg_replace('#/+#', '/', $normalizedPath);
$normalizedPath = preg_replace('#/index\.php$#i', '', $normalizedPath);
$normalizedPath = rtrim($normalizedPath, '/');
if ($normalizedPath === '') {
    $normalizedPath = '/';
}

if (strpos($normalizedPath, '/api/') === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid path']);
    exit;
}

function extract_client_ip(): string {
    $headerCandidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($headerCandidates as $header) {
        if (!isset($_SERVER[$header]) || $_SERVER[$header] === '') {
            continue;
        }

        $raw = (string)$_SERVER[$header];
        $parts = explode(',', $raw);
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    return '0.0.0.0';
}

$clientIp = extract_client_ip();
$visitorKey = hash('sha256', $clientIp);

$rootDir = dirname(__DIR__, 2);
$dataDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc';
$dataFile = $dataDir . DIRECTORY_SEPARATOR . 'page_views.json';

if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to create data directory']);
    exit;
}

$fh = @fopen($dataFile, 'c+');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to open data file']);
    exit;
}

if (!flock($fh, LOCK_EX)) {
    fclose($fh);
    http_response_code(500);
    echo json_encode(['error' => 'failed to lock data file']);
    exit;
}

$existingRaw = stream_get_contents($fh);
$state = [];
if (is_string($existingRaw) && trim($existingRaw) !== '') {
    $decoded = json_decode($existingRaw, true);
    if (is_array($decoded)) {
        $state = $decoded;
    }
}

if (!isset($state['pages']) || !is_array($state['pages'])) {
    $state['pages'] = [];
}
if (!isset($state['pages'][$normalizedPath]) || !is_array($state['pages'][$normalizedPath])) {
    $state['pages'][$normalizedPath] = [
        'count' => 0,
        'visitors' => []
    ];
}

if (!isset($state['pages'][$normalizedPath]['count']) || !is_int($state['pages'][$normalizedPath]['count'])) {
    $state['pages'][$normalizedPath]['count'] = (int)($state['pages'][$normalizedPath]['count'] ?? 0);
}
if (!isset($state['pages'][$normalizedPath]['visitors']) || !is_array($state['pages'][$normalizedPath]['visitors'])) {
    $state['pages'][$normalizedPath]['visitors'] = [];
}

if (!array_key_exists($visitorKey, $state['pages'][$normalizedPath]['visitors'])) {
    $state['pages'][$normalizedPath]['visitors'][$visitorKey] = time();
    $state['pages'][$normalizedPath]['count']++;
}

$state['updated_at'] = gmdate('c');

rewind($fh);
ftruncate($fh, 0);
$encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false || fwrite($fh, $encoded) === false) {
    flock($fh, LOCK_UN);
    fclose($fh);
    http_response_code(500);
    echo json_encode(['error' => 'failed to write data file']);
    exit;
}

fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'path' => $normalizedPath,
    'count' => (int)$state['pages'][$normalizedPath]['count']
]);
