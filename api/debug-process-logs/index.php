<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
fridg3_start_session();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user']['isAdmin']) || $_SESSION['user']['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

function debug_process_log_path(): ?string
{
    $configured = (string)($_ENV['FRIDG3_PHP_PROCESS_LOG'] ?? $_SERVER['FRIDG3_PHP_PROCESS_LOG'] ?? getenv('FRIDG3_PHP_PROCESS_LOG') ?: '');
    $candidates = array_filter([
        $configured,
        (string)ini_get('error_log'),
        '/var/log/php-fpm.log',
        '/var/log/php-fpm/error.log',
        '/var/log/php/error.log',
        '/var/log/nginx/error.log',
        '/var/log/apache2/error.log',
    ]);

    foreach (array_unique($candidates) as $candidate) {
        if ($candidate === 'syslog' || str_starts_with($candidate, 'php://')) continue;
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved) && is_readable($resolved)) return $resolved;
    }
    return null;
}

$path = debug_process_log_path();
if ($path === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'log_unavailable', 'isAdmin' => true]);
    exit;
}

$stat = @stat($path);
if (!$stat) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'log_unavailable', 'isAdmin' => true]);
    exit;
}

$size = (int)$stat['size'];
$identity = (string)$stat['dev'] . ':' . (string)$stat['ino'];
$requestedIdentity = (string)($_GET['identity'] ?? '');
$requestedOffset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$hasCursor = $requestedIdentity !== '' && $requestedOffset !== false && $requestedOffset !== null;
if (!$hasCursor) {
    // Seed the debug panel with recent history instead of starting at EOF.
    $offset = max(0, $size - 65536);
} elseif ($requestedIdentity !== $identity || (int)$requestedOffset > $size) {
    $offset = 0;
} else {
    $offset = max(0, (int)$requestedOffset);
}

$maxBytes = 262144;
if (($size - $offset) > $maxBytes) $offset = $size - $maxBytes;
$contents = '';
$handle = @fopen($path, 'rb');
if ($handle !== false) {
    if (@fseek($handle, $offset) === 0) $contents = (string)stream_get_contents($handle, $maxBytes);
    fclose($handle);
}

// A tail offset can begin halfway through a line. Drop only that partial line.
if (!$hasCursor && $offset > 0 && $contents !== '') {
    $firstBreak = strpos($contents, "\n");
    if ($firstBreak !== false) {
        $discarded = $firstBreak + 1;
        $contents = substr($contents, $discarded);
        $offset += $discarded;
    }
}

$nextOffset = $offset + strlen($contents);
$lines = preg_split('/\R/u', $contents) ?: [];
$lines = array_values(array_filter($lines, static fn($line) => $line !== ''));

echo json_encode([
    'ok' => true,
    'source' => $path,
    'identity' => $identity,
    'offset' => $nextOffset,
    'lines' => $lines,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
