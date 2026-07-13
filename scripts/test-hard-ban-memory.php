<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fridg3-hard-ban-' . bin2hex(random_bytes(8));
$sourceRoot = $testRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'banlists';
$nestedSourceRoot = $sourceRoot . DIRECTORY_SEPARATOR . 'nested';
mkdir($nestedSourceRoot, 0700, true);

function fridg3_hard_ban_path(): string
{
    return $GLOBALS['testRoot'] . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-banned-ips.txt';
}

function fridg3_hard_ban_identity_path(): string
{
    return $GLOBALS['testRoot'] . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-ban-identities.json';
}

function fridg3_hard_ban_source_directory(): string
{
    return $GLOBALS['sourceRoot'];
}

function fridg3_hard_ban_index_cache_directory(): string
{
    return $GLOBALS['sourceRoot'] . DIRECTORY_SEPARATOR . 'index';
}

function assertHardBanResult(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeHardBanTestTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function assertHardBanIndexIsSorted(string $indexDirectory): void
{
    foreach (glob($indexDirectory . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $path) {
        $recordLength = str_starts_with(basename($path), '4-') ? 6 : 30;
        $remainderLength = intdiv($recordLength, 2);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('could not read index bucket: ' . $path);
        }

        $previousEnd = null;
        while (!feof($handle)) {
            $record = fread($handle, $recordLength);
            if ($record === false || $record === '') {
                break;
            }
            assertHardBanResult(strlen($record) === $recordLength, 'index contains a partial record');
            $start = substr($record, 0, $remainderLength);
            $end = substr($record, $remainderLength);
            assertHardBanResult(strcmp($start, $end) <= 0, 'index range ends before it starts');
            assertHardBanResult($previousEnd === null || strcmp($start, $previousEnd) > 0, 'index ranges overlap or are unsorted');
            $previousEnd = $end;
        }
        fclose($handle);
    }
}

try {
    file_put_contents(fridg3_hard_ban_path(), "198.51.100.8\n");
    file_put_contents(fridg3_hard_ban_identity_path(), "{\"identities\":{}}\n");
    file_put_contents(
        $nestedSourceRoot . DIRECTORY_SEPARATOR . 'small.txt',
        "invalid-entry\n64.0.0.0/7\n203.0.113.0/24\n2000::/7\n2001:db8:abcd::/48\n"
    );
    $interruptedBuild = $sourceRoot . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . '.build-interrupted';
    mkdir($interruptedBuild, 0700, true);
    file_put_contents($interruptedBuild . DIRECTORY_SEPARATOR . 'partial.bin', 'partial');

    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';

    assertHardBanResult(fridg3_hard_ban_contains('198.51.100.8'), 'manual exact IP was not matched');
    assertHardBanResult(fridg3_hard_ban_contains('65.255.255.255'), 'cross-bucket IPv4 CIDR was not matched');
    assertHardBanResult(!is_dir($interruptedBuild), 'interrupted index build was not cleaned');
    assertHardBanResult(!fridg3_hard_ban_contains('66.0.0.0'), 'address above cross-bucket IPv4 CIDR was matched');
    assertHardBanResult(fridg3_hard_ban_contains('203.0.113.91'), 'source IPv4 CIDR was not matched');
    assertHardBanResult(fridg3_hard_ban_contains('21ff:ffff::1'), 'cross-bucket IPv6 CIDR was not matched');
    assertHardBanResult(!fridg3_hard_ban_contains('2200::1'), 'address above cross-bucket IPv6 CIDR was matched');
    assertHardBanResult(fridg3_hard_ban_contains('2001:db8:abcd::42'), 'source IPv6 CIDR was not matched');
    assertHardBanResult(!fridg3_hard_ban_contains('192.0.2.1'), 'unlisted IP was matched');
    $initialIndex = fridg3_hard_ban_source_index();
    assertHardBanResult($initialIndex !== null, 'source index was not created');
    assertHardBanResult(fridg3_hard_ban_source_index() === $initialIndex, 'unchanged source index was not reused');

    file_put_contents(
        $nestedSourceRoot . DIRECTORY_SEPARATOR . 'small.txt',
        "198.18.0.0/15\n",
        FILE_APPEND
    );
    assertHardBanResult(fridg3_hard_ban_contains('198.19.255.254'), 'new source CIDR was not matched after invalidation');
    assertHardBanResult(fridg3_hard_ban_source_index() !== $initialIndex, 'changed source list did not invalidate the index');

    file_put_contents(fridg3_hard_ban_path(), "198.51.100.8\n192.0.2.1\n");
    assertHardBanResult(fridg3_hard_ban_contains('192.0.2.1'), 'manual list update was hidden by source-result memoization');

    $oversizedPath = $sourceRoot . DIRECTORY_SEPARATOR . 'oversized-token.txt';
    file_put_contents($oversizedPath, str_repeat('x', 1024 * 1024) . "\n192.0.2.56\n");
    assertHardBanResult(fridg3_hard_ban_contains('192.0.2.56'), 'entry after oversized token was not matched');

    $largePath = $sourceRoot . DIRECTORY_SEPARATOR . 'large-valid-list.txt';
    $handle = fopen($largePath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('could not create large source-list fixture');
    }
    $block = str_repeat("198.51.100.9\n", 512);
    for ($written = 0; $written < 56 * 1024 * 1024; $written += strlen($block)) {
        fwrite($handle, $block);
    }
    fwrite($handle, "192.0.2.55\n");
    fclose($handle);

    $memoryBefore = memory_get_peak_usage(true);
    assertHardBanResult(fridg3_hard_ban_contains('192.0.2.55'), 'entry at the end of the large source list was not matched');
    $largeIndex = fridg3_hard_ban_source_index();
    assertHardBanResult($largeIndex !== null, 'large source index was not created');
    assertHardBanIndexIsSorted($largeIndex);
    $additionalPeakMemory = memory_get_peak_usage(true) - $memoryBefore;
    assertHardBanResult(
        $additionalPeakMemory <= 8 * 1024 * 1024,
        'large source scan used more than 8 MiB of additional peak memory'
    );

    echo 'Hard-ban memory test passed; additional peak memory: ' . $additionalPeakMemory . " bytes.\n";
} finally {
    removeHardBanTestTree($testRoot);
}
