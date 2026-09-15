<?php
declare(strict_types=1);
// Exercise the real API authorization paths with isolated session/data fixtures.
define('FRIDGE_RESTORE_TEST_ROOT', sys_get_temp_dir() . '/fridge-restore-access-' . bin2hex(random_bytes(8)));
function fridge_start_session(bool $enforceAccessRules = true): void {}
require dirname(__DIR__) . '/lib/backup-restore.php';
$case = $argv[1] ?? 'guest';
$_SESSION = ['restore_csrf' => 'fixture-csrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'start';
$expected = 403;
if ($case !== 'guest') $_SESSION['user'] = ['username' => 'fixture-admin', 'isAdmin' => $case !== 'moderator', 'isModerator' => true];
if ($case === 'no-password') $_SERVER['HTTP_X_RESTORE_CSRF'] = 'fixture-csrf';
if ($case === 'maintenance-conflict') {
    $_SERVER['HTTP_X_RESTORE_CSRF'] = 'fixture-csrf';
    $_SESSION['restore_authorized_until'] = time() + 60;
    restore_prepare();
    file_put_contents(restore_workspace() . '/maintenance', 'existing-job');
    $expected = 409;
}
ob_start();
register_shutdown_function(static function () use ($case, $expected): void {
    $response = json_decode(ob_get_clean(), true);
    $ok = http_response_code() === $expected && ($response['ok'] ?? null) === false;
    restore_delete_tree(restore_workspace());
    if (!$ok) { fwrite(STDERR, 'API access check failed: ' . $case . "\n"); exit(1); }
    echo 'API access check passed: ' . $case . "\n";
});
require dirname(__DIR__) . '/api/restore-backup/index.php';
