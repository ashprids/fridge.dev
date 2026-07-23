<?php

define('FRIDG3_SKIP_ACCESS_LOG', true);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';
fridg3_start_session();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user']['isAdmin']) || $_SESSION['user']['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $action = (string)($_SERVER['HTTP_X_FRIDG3_DEBUG_ACTION'] ?? '');
    if (
        strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0
        || !in_array($action, ['clear', 'hard-ban', 'whitelist'], true)
    ) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }

    if ($action !== 'clear') {
        $ip = trim((string)($_POST['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'invalid_ip']);
            exit;
        }

        $directory = dirname(fridg3_hard_ban_path());
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'hard_ban_write_failed']);
            exit;
        }
        $lock = @fopen($directory . DIRECTORY_SEPARATOR . 'hard-ban-admin.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'hard_ban_write_failed']);
            exit;
        }

        $saved = false;
        if ($action === 'whitelist') {
            $whitelist = fridg3_hard_ban_whitelist_load();
            if (!fridg3_hard_ban_list_contains($whitelist, $ip)) {
                $whitelist[] = $ip;
            }
            $saved = fridg3_hard_ban_whitelist_write($whitelist);
        } else {
            $hardBans = fridg3_hard_ban_load();
            if (!fridg3_hard_ban_list_contains($hardBans, $ip)) {
                $hardBans[] = $ip;
            }
            $saved = fridg3_hard_ban_admin_save($hardBans);
            if ($saved) {
                $whitelist = array_values(array_filter(
                    fridg3_hard_ban_whitelist_load(),
                    static fn(string $allowedIp): bool => !fridg3_hard_ban_ips_equal($allowedIp, $ip)
                ));
                $saved = fridg3_hard_ban_whitelist_write($whitelist);
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        if (!$saved) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'hard_ban_write_failed']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'ip' => $ip,
            'hardBanned' => $action === 'hard-ban',
        ]);
        exit;
    }

    $logPath = fridg3_access_log_path();
    $directory = dirname($logPath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'clear_failed']);
        exit;
    }
    $lock = @fopen($logPath . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if ($lock !== false) fclose($lock);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'clear_failed']);
        exit;
    }
    $temporary = $logPath . '.tmp.' . getmypid();
    $cleared = @file_put_contents($temporary, "[]\n") !== false;
    if ($cleared) {
        @chmod($temporary, 0600);
        $cleared = @rename($temporary, $logPath);
    }
    if (!$cleared && is_file($temporary)) @unlink($temporary);
    flock($lock, LOCK_UN);
    fclose($lock);
    if (!$cleared) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'clear_failed']);
        exit;
    }
    echo json_encode(['ok' => true, 'entries' => []]);
    exit;
}

$adminUsernames = [];
$accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
$accounts = json_decode((string)@file_get_contents($accountsPath), true);
foreach ((array)($accounts['accounts'] ?? []) as $account) {
    if (!is_array($account) || empty($account['isAdmin'])) continue;
    $name = strtolower((string)($account['username'] ?? ''));
    if ($name !== '') $adminUsernames[$name] = true;
}
$banResults = [];
$entries = [];
foreach (fridg3_read_access_logs() as $entry) {
    if (!is_array($entry)) continue;
    $ip = (string)($entry['ip'] ?? 'unknown');
    $username = (string)($entry['username'] ?? '');
    $role = (string)($entry['role'] ?? '');
    if (!in_array($role, ['guest', 'user', 'admin'], true)) {
        $role = $username === '' ? 'guest' : (isset($adminUsernames[strtolower($username)]) ? 'admin' : 'user');
    }
    if (!array_key_exists($ip, $banResults)) {
        $banResults[$ip] = fridg3_hard_ban_contains($ip);
    }
    $entries[] = [
        'timestamp' => (string)($entry['timestamp'] ?? ''),
        'ip' => $ip,
        'path' => (string)($entry['path'] ?? '/'),
        'status' => (int)($entry['status'] ?? 0),
        'username' => $username,
        'role' => $role,
        'hardBanned' => $banResults[$ip],
    ];
}

echo json_encode(['ok' => true, 'entries' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
