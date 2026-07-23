<?php

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$status = $input['status'] ?? null;

if (is_bool($status)) {
    $status = $status ? 'online' : 'offline';
}

if (is_string($status)) {
    $status = strtolower(trim($status));
}

if (!in_array($status, ['online', 'offline'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Status must be "online" or "offline"']);
    exit;
}

$config_path = find_template_file('data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'toast.json');
if (!$config_path) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Configuration file not found']);
    exit;
}

$config = json_decode(file_get_contents($config_path), true);
if (!$config) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid configuration file']);
    exit;
}

$config['bot']['status'] = $status;

$written = file_put_contents(
    $config_path,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

if ($written === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write configuration']);
    exit;
}

error_log(sprintf('[%s] Bot status set to %s', date('Y-m-d H:i:s'), $status));

echo json_encode([
    'ok' => true,
    'status' => $status
]);

?>
