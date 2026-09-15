<?php
// Returns JSON indicating whether the current session user is an admin.
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridge_start_session();
header('Content-Type: application/json');

$isAdmin = (isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']));
fridge_refresh_is_admin_cookie($isAdmin);

echo json_encode(['isAdmin' => $isAdmin], JSON_UNESCAPED_SLASHES);
?>
