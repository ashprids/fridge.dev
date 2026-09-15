<?php
declare(strict_types=1);

function restore_root(): string { return defined('FRIDGE_RESTORE_TEST_ROOT') ? FRIDGE_RESTORE_TEST_ROOT : dirname(__DIR__); }
function restore_workspace(): string {
    return sys_get_temp_dir() . '/fridg3-restore-' . substr(hash('sha256', restore_root()), 0, 24);
}
function restore_prepare(): void {
    $dir = restore_workspace();
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('could not create restore workspace');
}
function restore_state(): array {
    $path = restore_workspace() . '/state.json';
    if (!is_file($path)) return [];
    $state = json_decode((string)file_get_contents($path), true);
    return is_array($state) ? $state : [];
}
function restore_write(array $state): void {
    restore_prepare();
    $path = restore_workspace() . '/state.json';
    $temp = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temp, $path)) {
        throw new RuntimeException('could not save restore progress');
    }
}
function restore_active(): bool { return is_file(restore_workspace() . '/maintenance'); }
function restore_progress(string $stage, float $percent, string $message): void {
    $state = restore_state();
    $state['stage'] = $stage;
    $state['percent'] = round($percent, 1);
    $state['message'] = $message;
    $state['updated'] = time();
    restore_write($state);
}
function restore_safe_entry(string $name, int $attributes = 0): bool {
    if ($name !== 'data/' && !str_starts_with($name, 'data/')) return false;
    if (str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/', $name)) return false;
    foreach (explode('/', rtrim($name, '/')) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
    $type = ($attributes >> 16) & 0170000;
    return in_array($type, [0, 0100000, 0040000], true);
}
function restore_validate_zip(ZipArchive $zip): int {
    $seen = [];
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $zip->getExternalAttributesIndex($i, $opsys, $attributes);
        $name = (string)$entry['name'];
        if (!restore_safe_entry($name, $attributes) || !empty($entry['encryption_method'])) throw new RuntimeException('archive contains an unsafe or encrypted entry');
        if (isset($seen[$name])) throw new RuntimeException('archive contains duplicate entries');
        $seen[$name] = true;
        $total += (int)$entry['size'];
    }
    foreach (array_keys($seen) as $name) {
        $parent = dirname(rtrim($name, '/'));
        while ($parent !== '.' && $parent !== 'data') {
            if (isset($seen[$parent])) throw new RuntimeException('archive has conflicting files and directories');
            $parent = dirname($parent);
        }
    }
    $accountsRaw = $zip->getFromName('data/accounts/accounts.json');
    $accounts = is_string($accountsRaw) ? json_decode($accountsRaw, true) : null;
    if (!is_array($accounts['accounts'] ?? null)) throw new RuntimeException('archive is missing valid accounts data');
    if (isset($seen['data/.development-copy.json']) || (
        $accounts['accounts'] === [] && isset($seen['data/journal/drafts/dev-placeholder.txt'])
    )) throw new RuntimeException('development data copies cannot be restored as backups');
    $admins = array_filter($accounts['accounts'], static fn($account) => is_array($account) && !empty($account['isAdmin']));
    if (!$admins) throw new RuntimeException('backup has no administrator account');
    return $total;
}
function restore_delete_tree(string $path, ?callable $progress = null): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) restore_delete_tree($item->getPathname(), $progress);
        if (!rmdir($path)) throw new RuntimeException('could not delete ' . basename($path));
    } elseif (!unlink($path)) throw new RuntimeException('could not delete ' . basename($path));
    if ($progress) $progress();
}
function restore_launch(): void {
    $binary = PHP_BINDIR . '/php';
    if (!is_executable($binary) || !function_exists('exec')) throw new RuntimeException('PHP CLI is required for background restoration');
    $cmd = escapeshellarg($binary) . ' ' . escapeshellarg(__DIR__ . '/backup-restore-worker.php') . ' > /dev/null 2>&1 < /dev/null &';
    exec($cmd);
}
function restore_run(): void {
    restore_prepare();
    $lock = fopen(restore_workspace() . '/worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $state = restore_state();
    if (!restore_active() || ($state['received'] ?? 0) !== ($state['size'] ?? -1)) return;
    $zip = new ZipArchive();
    try {
        restore_progress('validating', 50, 'checking uploaded backup...');
        if ($zip->open(restore_workspace() . '/backup.zip', ZipArchive::CHECKCONS) !== true) throw new RuntimeException('could not open uploaded backup');
        $total = restore_validate_zip($zip);
        if ($total > disk_free_space(restore_root())) throw new RuntimeException('not enough free space to restore this backup');
        // Verify every compressed stream and CRC before removing any live data.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (str_ends_with($entry['name'], '/')) continue;
            $stream = $zip->getStream($entry['name']);
            if (!$stream) throw new RuntimeException('could not read archive entry');
            $hash = hash_init('crc32b');
            $size = hash_update_stream($hash, $stream);
            fclose($stream);
            if ($size !== $entry['size'] || hash_final($hash) !== sprintf('%08x', $entry['crc'])) throw new RuntimeException('backup is corrupt');
            if ($i % 50 === 0) restore_progress('validating', 50 + 10 * ($i / max(1, $zip->numFiles)), 'verifying archive contents...');
        }
        $data = restore_root() . '/data';
        if (!is_dir($data) || is_link($data) || !is_writable($data)) throw new RuntimeException('data directory is not writable');
        restore_progress('deleting', 60, 'deleting current data directory...');
        $count = 0;
        if (is_dir($data)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $_) $count++;
        $deleted = 0;
        $onDelete = static function () use (&$deleted, $count): void {
            $deleted++;
            if ($deleted % 50 === 0) restore_progress('deleting', 60 + 10 * min(1, $deleted / max(1, $count)), 'deleting current data: ' . $deleted . '/' . $count);
        };
        // Keep the runtime-owned directory and its inherited ACLs: PHP cannot
        // remove it from the deploy-owned, read-only application root.
        foreach (new FilesystemIterator($data, FilesystemIterator::SKIP_DOTS) as $item) restore_delete_tree($item->getPathname(), $onDelete);
        restore_progress('extracting', 70, 'extracting backup into /data/...');
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $target = restore_root() . '/' . $entry['name'];
            if (str_ends_with($entry['name'], '/')) {
                if (!is_dir($target) && !mkdir($target, 0775, true)) throw new RuntimeException('could not create archive directory');
                continue;
            }
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
            $source = $zip->getStream($entry['name']);
            $destination = fopen($target, 'wb');
            if (!$source || !$destination) throw new RuntimeException('could not restore archive entry');
            $copied = stream_copy_to_stream($source, $destination);
            fclose($source);
            fclose($destination);
            if ($copied !== $entry['size']) throw new RuntimeException('incomplete restored file');
            $bytes += $copied;
            if ($i % 20 === 0) restore_progress('extracting', 70 + 29 * ($bytes / max(1, $total)), 'extracting backup: ' . ($i + 1) . '/' . $zip->numFiles);
        }
        $zip->close();
        if (!is_dir($data . '/etc')) mkdir($data . '/etc', 0775, true);
        if (file_put_contents($data . '/etc/wip', 'false', LOCK_EX) === false) throw new RuntimeException('could not disable maintenance');
        unlink(restore_workspace() . '/backup.zip');
        restore_progress('complete', 100, 'backup restored successfully.');
        unlink(restore_workspace() . '/maintenance');
    } catch (Throwable $error) {
        restore_progress('error', (float)(restore_state()['percent'] ?? 0), $error->getMessage() . '. maintenance remains enabled.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
