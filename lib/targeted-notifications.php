<?php
declare(strict_types=1);
require_once __DIR__ . '/notification-revision.php';
function fridg3_targeted_notifications_path(): string { return dirname(__DIR__) . '/data/etc/targeted-notifications.json'; }
function fridg3_targeted_notifications_load(): array {
    $path = fridg3_targeted_notifications_path();
    $decoded = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}
function fridg3_targeted_notifications_save(array $records): bool {
    $path = fridg3_targeted_notifications_path();
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    $encoded = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $saved = $encoded !== false && @file_put_contents($path, $encoded, LOCK_EX) !== false;
    if ($saved) fridg3_notification_revision_touch();
    return $saved;
}

function fridg3_targeted_notifications_notify_admins(string $title, string $message, string $url, string $date, string $idSeed): bool {
    $accountsPath = dirname(__DIR__) . '/data/accounts/accounts.json';
    $accountsData = is_file($accountsPath) ? json_decode((string)@file_get_contents($accountsPath), true) : [];
    $accounts = is_array($accountsData) ? (array)($accountsData['accounts'] ?? []) : [];
    $records = fridg3_targeted_notifications_load();
    $adminCount = 0;
    foreach ($accounts as $account) {
        if (!is_array($account) || !filter_var($account['isAdmin'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
        $username = strtolower(ltrim(trim((string)($account['username'] ?? '')), '@'));
        if ($username === '') continue;
        $records[] = ['id' => $idSeed . '-' . substr(hash('sha256', $username), 0, 12), 'targetType' => 'user', 'target' => $username, 'title' => $title, 'message' => $message, 'url' => $url, 'date' => $date];
        $adminCount++;
    }
    return $adminCount === 0 || fridg3_targeted_notifications_save($records);
}
