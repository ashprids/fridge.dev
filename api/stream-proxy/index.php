<?php
// Simple same-origin stream proxy to avoid mixed-content issues for toast listen-along
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';
set_time_limit(0);
ignore_user_abort(true);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

function find_template_file($filename) {
    $dir = __DIR__;
    $prev_dir = '';
    while ($dir !== $prev_dir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) return $filepath;
        $prev_dir = $dir;
        $dir = dirname($dir);
    }
    return null;
}

// Load toast configuration to know the expected stream host
$config_path = find_template_file('data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'toast.json');
if (!$config_path) {
    http_response_code(500);
    echo 'config not found';
    exit;
}

$config = json_decode(file_get_contents($config_path), true);
$baseUrl = isset($config['stream']['url']) ? trim($config['stream']['url']) : '';
if ($baseUrl === '') {
    http_response_code(500);
    echo 'stream url missing';
    exit;
}

$target = isset($_GET['u']) ? trim($_GET['u']) : $baseUrl;

// Validate target: only allow http/https and same host as configured stream
function normalize_url($url) {
    if (!$url) return null;
    $url = trim($url);
    if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
        $url = 'http://' . $url;
    }
    return $url;
}

$baseNorm = normalize_url($baseUrl);
$targetNorm = normalize_url($target);

if (!$baseNorm || !$targetNorm) {
    http_response_code(400);
    echo 'invalid url';
    exit;
}

$baseParts = parse_url($baseNorm);
$targetParts = parse_url($targetNorm);

if (!isset($targetParts['scheme']) || !in_array(strtolower($targetParts['scheme']), ['http', 'https'])) {
    http_response_code(400);
    echo 'unsupported scheme';
    exit;
}

if (!isset($baseParts['host'], $targetParts['host']) || strtolower($baseParts['host']) !== strtolower($targetParts['host'])) {
    http_response_code(403);
    echo 'forbidden host';
    exit;
}

$targetUrl = $targetNorm;

// Prefer cURL for robustness (avoids allow_url_fopen issues)
if (function_exists('curl_init')) {
    $sentHeaders = false;
    $contentType = 'audio/mpeg';

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: fridge.dev-stream-proxy'
        ],
        // Do not cap total time; streaming should run indefinitely
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_BUFFERSIZE => 8192,
        CURLOPT_NOPROGRESS => true,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$sentHeaders, &$contentType) {
            if (!$sentHeaders) {
                $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                if ($ct) $contentType = $ct;
                header('Content-Type: ' . $contentType);
                $sentHeaders = true;
            }
            echo $data;
            if (connection_aborted()) return 0; // stop streaming
            return strlen($data);
        },
    ]);

    $ok = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($ok === false || $err !== 0) {
        // fall back below
    }
    if ($ok !== false && $err === 0) exit;
}

// Fallback to stream context if cURL unavailable
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: fridge.dev-stream-proxy\r\n",
        // keep the connection open; stream_set_timeout below guards reads
        'timeout' => 0,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$stream = @fopen($targetUrl, 'rb', false, $context);
if (!$stream) {
    // Final fallback using raw socket (in case allow_url_fopen is disabled)
    $scheme = strtolower($targetParts['scheme']);
    $host = $targetParts['host'];
    $port = isset($targetParts['port']) ? (int)$targetParts['port'] : ($scheme === 'https' ? 443 : 80);
    $path = ($targetParts['path'] ?? '/') . (isset($targetParts['query']) ? '?' . $targetParts['query'] : '');
    $remote = ($scheme === 'https' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        http_response_code(502);
        echo 'unable to fetch stream';
        exit;
    }

    $headers = [
        "GET {$path} HTTP/1.0",
        "Host: {$host}",
        'User-Agent: fridge.dev-stream-proxy',
        'Connection: close',
        '',
        ''
    ];
    fwrite($socket, implode("\r\n", $headers));

    $contentType = 'audio/mpeg';
    $headerBuffer = '';
    while (!feof($socket)) {
        $line = fgets($socket, 2048);
        if ($line === false) break;
        $trim = trim($line);
        if ($trim === '') break; // end of headers
        $headerBuffer .= $line;
        if (stripos($line, 'Content-Type:') === 0) {
            $contentType = trim(substr($line, strlen('Content-Type:')));
        }
    }
    header('Content-Type: ' . $contentType);

    stream_set_timeout($socket, 0, 0);

    while (!feof($socket)) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) break;
        echo $chunk;
        if (connection_aborted()) break;
        flush();
    }
    fclose($socket);
    exit;
}

// Never time out while reading the live stream
@stream_set_timeout($stream, 0, 0);

$meta = stream_get_meta_data($stream);
$contentType = 'audio/mpeg';
if (!empty($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
    foreach ($meta['wrapper_data'] as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }
}
header('Content-Type: ' . $contentType);

while (!feof($stream)) {
    $chunk = fread($stream, 8192);
    if ($chunk === false) break;
    echo $chunk;
    if (connection_aborted()) break;
    flush();
}

fclose($stream);
exit;
