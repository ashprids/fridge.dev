<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/notification-revision.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo json_encode(['ok' => true, 'revision' => fridg3_notification_revision_read()], JSON_UNESCAPED_SLASHES);
