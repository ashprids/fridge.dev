<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
require_once dirname(__DIR__, 2) . '/lib/feed.php';

$ip = fridg3_feed_client_ip();
$record = fridg3_feed_banned_ip_record($ip);
if ($record === null) {
    echo json_encode(['ok' => true, 'restricted' => false]);
    exit;
}

$notificationId = trim((string)($record['notificationId'] ?? ''));
if ($notificationId === '') {
    $notificationId = hash('sha256', $ip . "\0" . (string)($record['bannedAt'] ?? 'legacy'));
}
$reason = trim((string)($record['reason'] ?? ''));

echo json_encode([
    'ok' => true,
    'restricted' => true,
    'notificationId' => $notificationId,
    'title' => 'Your IP address has been restricted from uploading content to the website',
    'reason' => $reason,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
