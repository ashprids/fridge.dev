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

$adminUsernames = [];
$accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
$accounts = json_decode((string)@file_get_contents($accountsPath), true);
foreach ((array)($accounts['accounts'] ?? []) as $account) {
    if (!is_array($account) || empty($account['isAdmin'])) continue;
    $name = strtolower((string)($account['username'] ?? ''));
    if ($name !== '') $adminUsernames[$name] = true;
}
$manualHardBans = fridg3_hard_ban_load();
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
        $banResults[$ip] = fridg3_hard_ban_list_contains($manualHardBans, $ip) || fridg3_hard_ban_source_contains($ip);
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
