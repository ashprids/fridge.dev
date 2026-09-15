<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/lib/render.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!fridge_is_local_dev_server()) {
    http_response_code(404);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'developerMode' => true], JSON_UNESCAPED_SLASHES);
