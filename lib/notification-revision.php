<?php
declare(strict_types=1);

function fridg3_notification_revision_path(): string {
    return dirname(__DIR__) . '/data/etc/notification-revision.txt';
}

function fridg3_notification_revision_read(): string {
    $path = fridg3_notification_revision_path();
    $value = is_file($path) ? trim((string)@file_get_contents($path)) : '';
    return $value !== '' ? $value : '0';
}

function fridg3_notification_revision_touch(): bool {
    $path = fridg3_notification_revision_path();
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    $value = sprintf('%.6f-%s', microtime(true), bin2hex(random_bytes(4)));
    return @file_put_contents($path, $value, LOCK_EX) !== false;
}
