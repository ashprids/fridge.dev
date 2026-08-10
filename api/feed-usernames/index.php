<?php
require_once dirname(__DIR__, 2) . '/lib/feed.php';
header('Content-Type: application/json');
$usernames = [];
foreach ((array)(fridg3_feed_load_accounts()['accounts'] ?? []) as $account) {
    $username = trim((string)($account['username'] ?? ''));
    if ($username !== '') $usernames[] = $username;
}
natcasesort($usernames);
echo json_encode(['ok' => true, 'usernames' => array_values($usernames)], JSON_UNESCAPED_SLASHES);
