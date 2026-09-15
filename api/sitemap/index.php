<?php
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'sitemap.php';
// Generate sitemap.xml at the project root. Admin-only.
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridge_start_session();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user']['isAdmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$root = dirname(__DIR__, 2);
$xmlContent = fridge_sitemap_xml($root);

$target = $root . DIRECTORY_SEPARATOR . 'sitemap.xml';
if (@file_put_contents($target, $xmlContent, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}

echo json_encode(['ok' => true, 'count' => substr_count($xmlContent, '<url>'), 'path' => '/sitemap.xml']);
?>
