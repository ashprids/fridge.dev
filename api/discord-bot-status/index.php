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

// Read toast.json configuration
$config_path = find_template_file('data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'toast.json');
if (!$config_path) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

$config = json_decode(file_get_contents($config_path), true);
if (!$config) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid configuration file']);
    exit;
}

$bot_status = 'offline';
if (isset($config['bot']['status'])) {
    $status_raw = strtolower((string)$config['bot']['status']);
    $bot_status = $status_raw === 'online' ? 'online' : 'offline';
}

// Return bot status and stream info
$response = [
    'bot' => [
        'name' => 'Toast',
        'status' => $bot_status,
        'avatar' => 'https://cdn.discordapp.com/embed/avatars/0.png',
        'username' => 'toast#9266'
    ],
    'stream' => [
        'name' => $config['stream']['name'] ?? 'Unknown',
        'url' => $config['stream']['url'] ?? null,
        'playing' => true
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>
