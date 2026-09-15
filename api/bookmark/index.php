<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridge_start_session();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

if (!isset($_SESSION['user']) || empty($_SESSION['user']['username'])) {
    // Not logged in: local bookmarks still work; just no server persistence
    http_response_code(401);
    echo json_encode(['error' => 'not logged in']);
    exit;
}

// Load existing bookmarks for this user
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

$rootDir = dirname(__DIR__, 2);
$accountsFile = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
$accountsData = [];
if (is_file($accountsFile)) {
    $json = @file_get_contents($accountsFile);
    $accountsData = json_decode($json, true);
}
$username = (string)$_SESSION['user']['username'];
$existing = [];
if (isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
    foreach ($accountsData['accounts'] as $acct) {
        if (isset($acct['username']) && $acct['username'] === $username && isset($acct['bookmarks']) && is_array($acct['bookmarks'])) {
            $existing = array_map('strval', $acct['bookmarks']);
            break;
        }
    }
}

// Determine new bookmarks list based on payload shape
if (isset($data['bookmarks']) && is_array($data['bookmarks'])) {
    // Full replacement mode (kept for compatibility, though not used for logged-in clients now)
    // Full replacement mode (kept for compatibility)
    $bookmarks = array_map('strval', $data['bookmarks']);
} elseif (isset($data['postId'])) {
    // Toggle a single bookmark
    $id = strval($data['postId']);
    $bookmarks = $existing;
    $idx = array_search($id, $bookmarks, true);
    if ($idx === false) {
        $bookmarks[] = $id;
    } else {
        array_splice($bookmarks, $idx, 1);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

// Normalize bookmark IDs so they always match feed post filenames
// (strip any path components and optional .txt extension, then dedupe).
$normalized = [];
foreach ($bookmarks as $bid) {
    $bid = (string)$bid;
    $bid = basename($bid);
    $bid = preg_replace('/\.txt$/i', '', $bid);
    if ($bid !== '') {
        $normalized[] = $bid;
    }
}

$updated = false;
if (isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
    foreach ($accountsData['accounts'] as &$acct) {
        if (isset($acct['username']) && $acct['username'] === $username) {
            $acct['bookmarks'] = array_values(array_unique($normalized));
            $updated = true;
            break;
        }
    }
    unset($acct);
    if ($updated) {
        if (@file_put_contents($accountsFile, json_encode($accountsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'failed to write user bookmarks']);
            exit;
        }
    }
}
http_response_code(200);
echo json_encode(['ok' => true, 'count' => count($normalized)]);
