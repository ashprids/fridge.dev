<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';

header('Content-Type: application/json');

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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON request body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$url = isset($input['url']) ? trim($input['url']) : '';
$name = isset($input['name']) ? trim($input['name']) : '';

if (!$url || !$name) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing url or name']);
    exit;
}

// Find toast.json configuration file
$config_path = find_template_file('data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'toast.json');
if (!$config_path) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Configuration file not found']);
    exit;
}

// Load current config
$config = json_decode(file_get_contents($config_path), true);
if (!$config) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid configuration file']);
    exit;
}

// Update stream settings
$config['stream']['url'] = $url;
$config['stream']['name'] = $name;

// Write updated config back to file
$written = file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($written === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write configuration']);
    exit;
}

// Write signal file to trigger immediate bot restart
$signal_file = dirname($config_path) . DIRECTORY_SEPARATOR . '.stream-update-signal';
file_put_contents($signal_file, time());

// Log the update
error_log(sprintf(
    '[%s] Stream updated: %s (%s)',
    date('Y-m-d H:i:s'),
    $name,
    $url
));

// Return success
http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Stream updated successfully',
    'stream' => [
        'url' => $url,
        'name' => $name
    ],
    'note' => 'Bot will restart playback with the new stream on next heartbeat or manual command'
]);

?>
