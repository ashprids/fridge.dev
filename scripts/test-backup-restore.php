<?php
declare(strict_types=1);
define('FRIDGE_RESTORE_TEST_ROOT', sys_get_temp_dir() . '/fridge-restore-test-' . bin2hex(random_bytes(8)));
require dirname(__DIR__) . '/lib/backup-restore.php';
require dirname(__DIR__) . '/lib/session.php';
require dirname(__DIR__) . '/lib/render.php';
function backup_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function backup_fixture(string $path, string $unsafe = '', bool $developer = false): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = [
        'data/accounts/accounts.json' => json_encode(['accounts' => $developer ? [] : [['username' => 'admin', 'isAdmin' => true, 'password' => 'fixture-only'], ['username' => 'alice']]]),
        'data/feed/post.txt' => "v2\n@alice\n2026-09-01 12:00:00\npost",
        'data/feed/replies/post.json' => json_encode(['replies' => [['id' => '1'], ['id' => '2'], ['id' => '3']]]),
        'data/feed/post-ips.json' => '{}',
        'data/journal/1.md' => 'journal',
        'data/journal/2.txt' => 'legacy journal',
        'data/journal/drafts/1.md' => 'draft',
        'data/guestbook/1.txt' => 'guest entry',
        'data/guestbook/ip_index.json' => '{}',
        'data/chat/abcdefgh1.json' => '{}',
        'data/chat/.presence/abcdefgh1.json' => '{}',
        'data/images/one.png' => str_repeat('image fixture', 200),
        'data/images/thumbnails/one.jpg' => 'thumbnail',
        'data/mdpaste/0123456789abcdef.json' => '{}',
        'data/etc/wip' => 'true',
    ];
    if ($developer) {
        $files['data/.development-copy.json'] = json_encode(['type' => 'fridge.dev-development-data', 'version' => 1]);
        $files['data/journal/drafts/dev-placeholder.txt'] = 'Development placeholder draft';
    }
    if ($unsafe !== '') $files[$unsafe] = 'unsafe';
    foreach ($files as $name => $body) $zip->addFromString($name, $body);
    $zip->close();
}
if (($argv[1] ?? '') === '--fixture') { backup_fixture($argv[2]); exit; }
try {
    mkdir(restore_root(), 0700, true);
    restore_prepare();
    mkdir(restore_root() . '/data');
    file_put_contents(restore_root() . '/data/keep.txt', 'existing data');
    file_put_contents(restore_workspace() . '/maintenance', 'test');
    backup_check(fridge_session_work_in_progress_enabled(), 'session maintenance ignored restore lock');
    backup_check(fridge_is_work_in_progress_enabled(restore_root()), 'renderer maintenance ignored restore lock');
    backup_fixture(restore_workspace() . '/backup.zip', 'data/../../escape.txt');
    $size = filesize(restore_workspace() . '/backup.zip');
    restore_write(['id' => 'test', 'size' => $size, 'received' => $size, 'stage' => 'queued']);
    restore_run();
    backup_check(restore_state()['stage'] === 'error', 'unsafe archive was accepted');
    backup_check(is_file(restore_root() . '/data/keep.txt') && restore_active(), 'unsafe archive changed data or disabled maintenance');
    backup_check(!restore_safe_entry('data/link', 0120777 << 16), 'symlink entry was accepted');
    backup_check(!restore_safe_entry('data/foo\\bar'), 'backslash path was accepted');
    backup_fixture(restore_workspace() . '/backup.zip', '', true);
    clearstatcache();
    $size = filesize(restore_workspace() . '/backup.zip');
    restore_write(['id' => 'test', 'size' => $size, 'received' => $size, 'stage' => 'queued']);
    restore_run();
    backup_check(restore_state()['stage'] === 'error', 'developer data copy was accepted');
    backup_check(is_file(restore_root() . '/data/keep.txt') && restore_active(), 'developer data copy changed data or disabled maintenance');
    backup_fixture(restore_workspace() . '/backup.zip');
    clearstatcache();
    $size = filesize(restore_workspace() . '/backup.zip');
    restore_write(['id' => 'test', 'size' => $size, 'received' => $size, 'stage' => 'queued']);
    restore_run();
    backup_check(restore_state()['stage'] === 'complete', 'restore failed: ' . (restore_state()['message'] ?? 'unknown'));
    backup_check(!restore_active(), 'maintenance lock survived successful restore');
    backup_check(!is_file(restore_root() . '/data/keep.txt'), 'old content was not removed');
    backup_check(is_file(restore_root() . '/data/feed/post.txt'), 'restored content missing');
    backup_check(file_get_contents(restore_root() . '/data/etc/wip') === 'false', 'backup maintenance value overrode completion');
    backup_check(!is_file(restore_workspace() . '/backup.zip'), 'uploaded archive was not removed');
    echo "Unsafe and developer archive rejection, maintenance retention, full replacement and completion checks passed.\n";
} finally {
    restore_delete_tree(restore_root());
    restore_delete_tree(restore_workspace());
}
