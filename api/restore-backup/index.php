<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/backup-restore.php';
fridge_start_session(false);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function restore_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
if (empty($_SESSION['user']['isAdmin'])) restore_response(['ok' => false, 'error' => 'administrator access required'], 403);
$action = (string)($_GET['action'] ?? 'status');
$_SESSION['restore_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['restore_csrf'];
if ($action === 'status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $state = restore_state();
    if (restore_active() && in_array($state['stage'] ?? '', ['queued', 'validating', 'deleting', 'extracting'], true) && time() - ($state['updated'] ?? 0) > 15) {
        $workerLock = fopen(restore_workspace() . '/worker.lock', 'c');
        if ($workerLock && flock($workerLock, LOCK_EX | LOCK_NB)) {
            restore_progress('error', (float)($state['percent'] ?? 0), 'restore worker stopped. maintenance remains enabled; retry to resume.');
            flock($workerLock, LOCK_UN);
            $state = restore_state();
        }
        if ($workerLock) fclose($workerLock);
    }
    restore_response(['ok' => true, 'active' => restore_active(), 'state' => $state, 'csrf' => $csrf]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') restore_response(['ok' => false, 'error' => 'POST required'], 405);
if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_RESTORE_CSRF'] ?? ''))) restore_response(['ok' => false, 'error' => 'invalid request token'], 403);
try {
    if ($action === 'authenticate') {
        $last = (int)($_SESSION['restore_password_attempt'] ?? 0);
        if (time() - $last < 2) restore_response(['ok' => false, 'error' => 'please wait before trying your password again'], 429);
        $_SESSION['restore_password_attempt'] = time();
        $body = json_decode((string)file_get_contents('php://input'), true);
        $password = (string)($body['password'] ?? '');
        $accounts = json_decode((string)file_get_contents(restore_root() . '/data/accounts/accounts.json'), true);
        $valid = false;
        foreach ($accounts['accounts'] ?? [] as $account) {
            if (($account['username'] ?? '') !== ($_SESSION['user']['username'] ?? '') || empty($account['isAdmin'])) continue;
            $stored = (string)($account['password'] ?? '');
            $valid = password_get_info($stored)['algo'] !== null ? password_verify($password, $stored) : hash_equals($stored, $password);
        }
        if (!$valid) restore_response(['ok' => false, 'error' => 'password did not match'], 403);
        $_SESSION['restore_authorized_until'] = time() + 300;
        restore_response(['ok' => true]);
    }
    restore_prepare();
    $lock = fopen(restore_workspace() . '/upload.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock restore job');
    $state = restore_state();
    if ($action === 'start') {
        if (restore_active()) restore_response(['ok' => false, 'error' => 'a restore is already running'], 409);
        if ((int)($_SESSION['restore_authorized_until'] ?? 0) < time()) restore_response(['ok' => false, 'error' => 'password confirmation expired'], 403);
        if (!class_exists('ZipArchive') || !is_executable(PHP_BINDIR . '/php') || !function_exists('exec')) throw new RuntimeException('restoration requires PHP CLI and the PHP zip extension');
        foreach (glob(restore_root() . '/.bootstrap/status-*.json') ?: [] as $bootstrapStatus) {
            $bootstrap = json_decode((string)file_get_contents($bootstrapStatus), true);
            if (is_array($bootstrap) && empty($bootstrap['finished'])) throw new RuntimeException('finish developer-data bootstrap before restoring a backup');
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $size = filter_var($body['size'] ?? null, FILTER_VALIDATE_INT);
        $name = (string)($body['name'] ?? '');
        $fingerprint = (string)($body['fingerprint'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) throw new RuntimeException('missing archive fingerprint');
        if (!$size || $size < 22 || !preg_match('/^\d{2}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $name)) throw new RuntimeException('select an automatic data backup ZIP');
        if ($size > disk_free_space(restore_workspace())) throw new RuntimeException('not enough upload space');
        $id = bin2hex(random_bytes(16));
        $state = ['id' => $id, 'name' => $name, 'size' => $size, 'fingerprint' => $fingerprint, 'received' => 0, 'stage' => 'uploading', 'percent' => 0, 'message' => 'uploading backup...', 'updated' => time()];
        if (file_put_contents(restore_workspace() . '/backup.zip', '') === false) throw new RuntimeException('could not create upload');
        restore_write($state);
        if (file_put_contents(restore_workspace() . '/maintenance', $id, LOCK_EX) === false) throw new RuntimeException('could not lock maintenance');
        unset($_SESSION['restore_authorized_until']);
        restore_response(['ok' => true, 'active' => true, 'state' => $state]);
    }
    if (!restore_active() || !hash_equals((string)($state['id'] ?? ''), (string)($_GET['id'] ?? ''))) restore_response(['ok' => false, 'error' => 'restore job not found'], 409);
    if ($action === 'retry') {
        if (($state['stage'] ?? '') !== 'error') throw new RuntimeException('restore has not failed');
        restore_progress('queued', 50, 'resuming restoration...');
        restore_launch();
        restore_response(['ok' => true]);
    }
    if ($action !== 'chunk' || ($state['stage'] ?? '') !== 'uploading') restore_response(['ok' => false, 'error' => 'invalid restore action'], 409);
    $offset = filter_var($_GET['offset'] ?? null, FILTER_VALIDATE_INT);
    if ($offset !== $state['received']) restore_response(['ok' => false, 'error' => 'upload position changed; resuming', 'state' => $state], 409);
    $input = fopen('php://input', 'rb');
    $chunk = stream_get_contents($input, 2097153);
    if ($chunk === false || strlen($chunk) === 0 || strlen($chunk) > 2097152 || $offset + strlen($chunk) > $state['size']) throw new RuntimeException('invalid upload chunk');
    $file = fopen(restore_workspace() . '/backup.zip', 'c+b');
    if (!$file || fseek($file, $offset) !== 0 || fwrite($file, $chunk) !== strlen($chunk)) throw new RuntimeException('failed to store upload chunk');
    fflush($file);
    fclose($file);
    $state['received'] += strlen($chunk);
    $state['percent'] = round(50 * $state['received'] / $state['size'], 1);
    $state['updated'] = time();
    $finishedUpload = $state['received'] === $state['size'];
    if ($finishedUpload) { $state['stage'] = 'queued'; $state['message'] = 'backup uploaded; preparing restoration...'; }
    restore_write($state);
    if ($finishedUpload) restore_launch();
    restore_response(['ok' => true, 'state' => $state]);
} catch (Throwable $error) {
    restore_response(['ok' => false, 'error' => $error->getMessage()], 400);
}
