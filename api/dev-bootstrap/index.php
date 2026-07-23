<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$renderHelperPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'render.php';
if (is_file($renderHelperPath)) {
    require_once $renderHelperPath;
}

const FRIDG3_DEV_BOOTSTRAP_FOLDER_ID = '1dltxdqQjfUfGwEEXVxUrOw5fuv9nk_ex';

function dev_bootstrap_debug_text(string $value): string
{
    $value = preg_replace('#https?://\S+#i', '[url omitted]', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    return substr($value, 0, 1000);
}

function dev_bootstrap_emit(string $stage, int $progress, string $message, array $extra = []): void
{
    $progress = max(0, min(100, $progress));
    $debug = '[BOOTSTRAP] server update stage=' . dev_bootstrap_debug_text($stage)
        . ' progress=' . $progress . '% popup_text="' . dev_bootstrap_debug_text($message) . '"';
    if (isset($extra['log']) && is_string($extra['log']) && trim($extra['log']) !== '') {
        $debug .= ' popup_detail="' . dev_bootstrap_debug_text($extra['log']) . '"';
    }
    if (isset($extra['archive']) && is_string($extra['archive']) && trim($extra['archive']) !== '') {
        $debug .= ' archive="' . dev_bootstrap_debug_text(basename($extra['archive'])) . '"';
    }
    fridg3_debug_log($debug);
    echo json_encode(array_merge([
        'ok' => true,
        'stage' => $stage,
        'progress' => $progress,
        'message' => $message,
        'debug' => $debug,
    ], $extra), JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
}

function dev_bootstrap_fail(string $message, int $status = 500): never
{
    http_response_code($status);
    $debug = '[BOOTSTRAP] server failure progress=100% HTTP=' . $status . ' popup_text="' . dev_bootstrap_debug_text($message) . '"';
    fridg3_debug_log($debug);
    echo json_encode([
        'ok' => false,
        'progress' => 100,
        'message' => $message,
        'debug' => $debug,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
    exit;
}

function dev_bootstrap_format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, (float)$bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    $precision = $unit === 0 ? 0 : ($value >= 100 ? 0 : 1);
    $formatted = number_format($value, $precision);
    if ($precision > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }
    return $formatted . $units[$unit];
}

function dev_bootstrap_parse_size_label(string $label): int
{
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*([KMGT]?B?|[KMGT])\b/i', trim($label), $match) !== 1) {
        return 0;
    }

    $value = (float)$match[1];
    $unit = strtoupper((string)$match[2]);
    $unit = rtrim($unit, 'B');
    $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4];
    $power = $powers[$unit] ?? 0;
    return (int)round($value * (1024 ** $power));
}

function dev_bootstrap_parse_size_from_text(string $text): int
{
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
        '/\(([0-9]+(?:\.[0-9]+)?\s*[KMGT]B?)\)/i',
        '/(?:size|download|file)[^0-9]{0,80}([0-9]+(?:\.[0-9]+)?\s*[KMGT]B?)/i',
        '/([0-9]+(?:\.[0-9]+)?\s*[KMGT]B?)[^<]{0,80}(?:too large|download|file)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decoded, $match) === 1) {
            $bytes = dev_bootstrap_parse_size_label((string)$match[1]);
            if ($bytes > 0) {
                return $bytes;
            }
        }
    }

    return 0;
}

function dev_bootstrap_download_log(int $downloaded, int $total, string $source = ''): string
{
    $bytes = $total > 0
        ? dev_bootstrap_format_bytes($downloaded) . '/' . dev_bootstrap_format_bytes($total)
        : dev_bootstrap_format_bytes($downloaded);
    $percent = $total > 0 ? ' (' . min(100, (int)floor(($downloaded / $total) * 100)) . '%)' : '';
    return trim($bytes . $percent . ($source !== '' ? ' - ' . $source : ''));
}

function dev_bootstrap_probe_content_length_with_system(string $url, string $curlBin, string $wgetBin): int
{
    if ($curlBin !== '') {
        $cmd = escapeshellcmd($curlBin)
            . ' -L --silent --show-error --head --connect-timeout 20'
            . ' -A ' . escapeshellarg('fridg3-dev-bootstrap/1.0')
            . ' ' . escapeshellarg($url);
    } elseif ($wgetBin !== '') {
        $cmd = escapeshellcmd($wgetBin)
            . ' --spider --server-response --timeout=20 --tries=1'
            . ' --user-agent=' . escapeshellarg('fridg3-dev-bootstrap/1.0')
            . ' ' . escapeshellarg($url)
            . ' 2>&1';
    } else {
        return 0;
    }

    $lines = [];
    $status = 0;
    @exec($cmd, $lines, $status);
    $length = 0;
    foreach ($lines as $line) {
        if (preg_match('/^\s*content-length:\s*(\d+)/i', (string)$line, $match) === 1) {
            $length = max($length, (int)$match[1]);
        }
    }

    return $length;
}

function dev_bootstrap_accounts_path(string $root): string
{
    return $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
}

function dev_bootstrap_has_admin_account(string $root): bool
{
    $path = dev_bootstrap_accounts_path($root);
    if (!is_file($path)) {
        return false;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    foreach ((array)($decoded['accounts'] ?? []) as $account) {
        if (!empty($account['isAdmin'])) {
            return true;
        }
    }

    return false;
}

function dev_bootstrap_http_get(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'fridg3-dev-bootstrap/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'failed to fetch Google Drive folder listing');
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 60,
            'user_agent' => 'fridg3-dev-bootstrap/1.0',
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('failed to fetch Google Drive folder listing');
    }
    return $body;
}

function dev_bootstrap_latest_archive_from_drive(string $folderId): array
{
    $html = dev_bootstrap_http_get('https://drive.google.com/drive/folders/' . rawurlencode($folderId));
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $candidates = [];
    if (preg_match_all('/\[\s*null\s*,\s*"([A-Za-z0-9_-]{20,})"\s*\].{0,3000}?([0-9]{2}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.zip)/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = (string)$match[2];
            $timestamp = DateTime::createFromFormat('d-m-y_H-i-s', substr($name, 0, -4));
            if (!$timestamp) {
                continue;
            }
            $candidates[$name] = [
                'id' => (string)$match[1],
                'name' => $name,
                'timestamp' => $timestamp->getTimestamp(),
            ];
        }
    }

    if (empty($candidates) && preg_match_all('/([A-Za-z0-9_-]{20,}).{0,800}?([0-9]{2}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.zip)/s', $html, $fallbackMatches, PREG_SET_ORDER)) {
        foreach ($fallbackMatches as $match) {
            $name = (string)$match[2];
            $timestamp = DateTime::createFromFormat('d-m-y_H-i-s', substr($name, 0, -4));
            if (!$timestamp) {
                continue;
            }
            $candidates[$name] = [
                'id' => (string)$match[1],
                'name' => $name,
                'timestamp' => $timestamp->getTimestamp(),
            ];
        }
    }

    if (empty($candidates)) {
        throw new RuntimeException('could not find a dev data zip in the Google Drive folder');
    }

    usort($candidates, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    return $candidates[0];
}

function dev_bootstrap_remove_path(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (!is_dir($path) || is_link($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException('failed to remove ' . basename($path));
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!@rmdir($itemPath)) {
                throw new RuntimeException('failed to remove directory ' . $itemPath);
            }
        } elseif (!@unlink($itemPath)) {
            throw new RuntimeException('failed to remove file ' . $itemPath);
        }
    }
    if (!@rmdir($path)) {
        throw new RuntimeException('failed to remove directory ' . $path);
    }
}

function dev_bootstrap_copy_path(string $source, string $dest): void
{
    if (is_link($source)) {
        $target = readlink($source);
        if ($target === false || !@symlink($target, $dest)) {
            throw new RuntimeException('failed to copy symlink ' . $source);
        }
        return;
    }

    if (!is_dir($source)) {
        if (!@copy($source, $dest)) {
            throw new RuntimeException('failed to copy file ' . $source);
        }
        return;
    }

    if (!@mkdir($dest, 0777, true) && !is_dir($dest)) {
        throw new RuntimeException('failed to create directory ' . $dest);
    }

    $iterator = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        dev_bootstrap_copy_path(
            $item->getPathname(),
            $dest . DIRECTORY_SEPARATOR . $item->getFilename()
        );
    }
}

function dev_bootstrap_download_drive_file(array $archive, string $destPath): void
{
    $probeUrl = 'https://drive.google.com/uc?export=download&confirm=t&id=' . rawurlencode((string)$archive['id']);
    $warningHtml = dev_bootstrap_http_get($probeUrl);
    $warningHtml = html_entity_decode($warningHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $downloadUrl = $probeUrl;
    $expectedBytes = dev_bootstrap_parse_size_from_text($warningHtml);

    if (preg_match('/<form[^>]+id="download-form"[^>]+action="([^"]+)"/i', $warningHtml, $actionMatch)) {
        $params = [];
        if (preg_match_all('/<input[^>]+type="hidden"[^>]+name="([^"]+)"[^>]+value="([^"]*)"/i', $warningHtml, $inputMatches, PREG_SET_ORDER)) {
            foreach ($inputMatches as $inputMatch) {
                $params[(string)$inputMatch[1]] = html_entity_decode((string)$inputMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $downloadUrl = html_entity_decode((string)$actionMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '?' . http_build_query($params);
    }

    dev_bootstrap_emit('download', 26, 'resolved archive download...', [
        'log' => $expectedBytes > 0
            ? 'Google Drive response resolved; expected size ' . dev_bootstrap_format_bytes($expectedBytes)
            : 'Google Drive response resolved; total size will be detected while downloading',
    ]);

    if (!function_exists('curl_init')) {
        dev_bootstrap_emit('download', 28, 'starting PHP stream download...', [
            'log' => 'PHP cURL unavailable; using the native HTTPS stream first',
        ]);
        try {
            dev_bootstrap_stream_download($downloadUrl, $destPath, $expectedBytes);
        } catch (Throwable $streamError) {
            dev_bootstrap_emit('download', 30, 'PHP stream download failed, trying system downloader...', [
                'log' => 'download: PHP stream failed - ' . $streamError->getMessage(),
            ]);
            dev_bootstrap_system_download($downloadUrl, $destPath, $expectedBytes, $streamError->getMessage());
        }
        return;
    }

    $out = @fopen($destPath, 'wb');
    if (!$out) {
        throw new RuntimeException('could not create temporary download file');
    }

    $lastProgress = 28;
    $lastLogAt = 0.0;
    $knownTotal = $expectedBytes;
    dev_bootstrap_emit('download', 28, 'starting cURL download...', [
        'log' => $knownTotal > 0
            ? 'PHP cURL selected; expected size ' . dev_bootstrap_format_bytes($knownTotal)
            : 'PHP cURL selected; waiting for the response content length',
    ]);
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'fridg3-dev-bootstrap/1.0',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_HEADERFUNCTION => static function ($resource, string $header) use (&$knownTotal): int {
            if (preg_match('/^content-length:\s*(\d+)/i', trim($header), $match) === 1) {
                $knownTotal = max($knownTotal, (int)$match[1]);
            }
            return strlen($header);
        },
        CURLOPT_PROGRESSFUNCTION => static function ($resource, float $downloadTotal, float $downloaded) use (&$lastProgress, &$lastLogAt, &$knownTotal): int {
            $total = (int)max($downloadTotal, $knownTotal);
            if ($total <= 0) {
                return 0;
            }
            $progress = 28 + (int)floor(($downloaded / $total) * 42);
            $now = microtime(true);
            if ($progress > $lastProgress || $now - $lastLogAt >= 1.0) {
                $lastProgress = min(70, $progress);
                $lastLogAt = $now;
                dev_bootstrap_emit('download', $lastProgress, 'downloading archive...', [
                    'log' => dev_bootstrap_download_log((int)$downloaded, $total),
                ]);
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($out);

    if ($ok !== true || $status >= 400) {
        throw new RuntimeException($error !== '' ? $error : 'Google Drive download failed');
    }
    dev_bootstrap_assert_zip_download($destPath);
}

function dev_bootstrap_last_error_message(): string
{
    $error = error_get_last();
    if (!is_array($error) || empty($error['message'])) {
        return '';
    }
    return (string)$error['message'];
}

function dev_bootstrap_assert_zip_download(string $destPath): void
{
    if (!is_file($destPath) || filesize($destPath) < 4) {
        throw new RuntimeException('downloaded archive was empty');
    }

    $signature = (string)@file_get_contents($destPath, false, null, 0, 4);
    if (strncmp($signature, "PK\x03\x04", 4) !== 0 && strncmp($signature, "PK\x05\x06", 4) !== 0 && strncmp($signature, "PK\x07\x08", 4) !== 0) {
        throw new RuntimeException('Google Drive returned a non-zip response');
    }
}

function dev_bootstrap_system_download(string $downloadUrl, string $destPath, int $expectedBytes = 0, string $previousError = ''): void
{
    $curlBin = trim((string)@shell_exec('command -v curl 2>/dev/null'));
    $wgetBin = trim((string)@shell_exec('command -v wget 2>/dev/null'));
    if ($expectedBytes <= 0) {
        $expectedBytes = dev_bootstrap_probe_content_length_with_system($downloadUrl, $curlBin, $wgetBin);
    }

    if ($curlBin !== '') {
        $tool = 'curl';
        $cmd = escapeshellcmd($curlBin)
            . ' -L --fail --connect-timeout 20 --retry 2 --retry-delay 1 --silent --show-error'
            . ' -A ' . escapeshellarg('fridg3-dev-bootstrap/1.0')
            . ' -o ' . escapeshellarg($destPath)
            . ' ' . escapeshellarg($downloadUrl);
    } elseif ($wgetBin !== '') {
        $tool = 'wget';
        $cmd = escapeshellcmd($wgetBin)
            . ' -O ' . escapeshellarg($destPath)
            . ' --timeout=20 --tries=3 --no-verbose'
            . ' --user-agent=' . escapeshellarg('fridg3-dev-bootstrap/1.0')
            . ' ' . escapeshellarg($downloadUrl);
    } else {
        throw new RuntimeException('could not open Google Drive download stream'
            . ($previousError !== '' ? ': ' . $previousError : '')
            . '; install PHP cURL, system curl, or wget');
    }

    dev_bootstrap_emit('download', 30, 'starting system download fallback...', [
        'log' => $tool . ' selected' . ($expectedBytes > 0 ? '; expected size ' . dev_bootstrap_format_bytes($expectedBytes) : '; total size unknown'),
    ]);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start system downloader');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $outputTail = [];
    $lastOutput = '';
    $lastProgress = 30;
    $lastLogAt = 0.0;
    $exitCode = null;
    while (true) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $chunk = trim(str_replace("\r", "\n", (string)$stdout . "\n" . (string)$stderr));
        if ($chunk !== '') {
            foreach (array_filter(array_map('trim', explode("\n", $chunk))) as $line) {
                $lastOutput = $line;
                $outputTail[] = $line;
                $outputTail = array_slice($outputTail, -8);
            }
        }

        clearstatcache(true, $destPath);
        $downloaded = is_file($destPath) ? (int)filesize($destPath) : 0;
        $total = $expectedBytes;
        $progress = $total > 0 ? 28 + (int)floor(($downloaded / $total) * 42) : $lastProgress;
        $now = microtime(true);
        if ($progress > $lastProgress || $lastOutput !== '' || $now - $lastLogAt >= 1.0) {
            $lastProgress = min(70, max($lastProgress, $progress));
            $lastLogAt = $now;
            dev_bootstrap_emit('download', $lastProgress, 'downloading archive...', [
                'log' => dev_bootstrap_download_log($downloaded, $total),
            ]);
            $lastOutput = '';
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = is_int($status['exitcode']) && $status['exitcode'] >= 0 ? $status['exitcode'] : null;
            break;
        }
        usleep(200000);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $chunk = trim(str_replace("\r", "\n", (string)$stdout . "\n" . (string)$stderr));
    if ($chunk !== '') {
        foreach (array_filter(array_map('trim', explode("\n", $chunk))) as $line) {
            $outputTail[] = $line;
            $outputTail = array_slice($outputTail, -8);
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeStatus = proc_close($process);
    $status = $exitCode ?? $closeStatus;

    if ($status !== 0) {
        throw new RuntimeException('system downloader failed'
            . ($previousError !== '' ? ' after PHP stream error: ' . $previousError : '')
            . '. ' . trim(implode("\n", $outputTail)));
    }

    dev_bootstrap_assert_zip_download($destPath);
}

function dev_bootstrap_stream_download(string $downloadUrl, string $destPath, int $expectedBytes = 0): void
{
    $context = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 0,
            'user_agent' => 'fridg3-dev-bootstrap/1.0',
        ],
    ]);
    $in = @fopen($downloadUrl, 'rb', false, $context);
    if (!$in) {
        $error = dev_bootstrap_last_error_message();
        throw new RuntimeException('could not open Google Drive download stream' . ($error !== '' ? ': ' . $error : ''));
    }

    $out = @fopen($destPath, 'wb');
    if (!$out) {
        fclose($in);
        throw new RuntimeException('could not create temporary download file');
    }

    $total = $expectedBytes;
    $meta = stream_get_meta_data($in);
    foreach ((array)($meta['wrapper_data'] ?? []) as $header) {
        if (preg_match('/^content-length:\s*(\d+)/i', (string)$header, $match)) {
            $total = max($total, (int)$match[1]);
        }
    }

    $downloaded = 0;
    $lastProgress = 28;
    $lastLogAt = 0.0;
    while (!feof($in)) {
        $chunk = fread($in, 1024 * 1024);
        if ($chunk === false) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('failed while reading Google Drive download');
        }
        if ($chunk === '') {
            continue;
        }
        if (fwrite($out, $chunk) === false) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('failed while writing downloaded archive');
        }
        $downloaded += strlen($chunk);
        if ($total > 0) {
            $progress = 28 + (int)floor(($downloaded / $total) * 42);
            $now = microtime(true);
            if ($progress > $lastProgress || $now - $lastLogAt >= 1.0) {
                $lastProgress = min(70, $progress);
                $lastLogAt = $now;
                dev_bootstrap_emit('download', $lastProgress, 'downloading archive...', [
                    'log' => dev_bootstrap_download_log($downloaded, $total),
                ]);
            }
        }
    }
    fclose($in);
    fclose($out);

    dev_bootstrap_assert_zip_download($destPath);
}

function dev_bootstrap_extract_zip(string $zipPath, string $extractDir): string
{
    if (!class_exists('ZipArchive')) {
        dev_bootstrap_extract_zip_with_unzip($zipPath, $extractDir);
        $dataDir = $extractDir . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir)) {
            throw new RuntimeException('downloaded zip did not contain a data directory');
        }
        return $dataDir;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('could not open downloaded zip');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../') || $normalized === '..') {
            $zip->close();
            throw new RuntimeException('zip contains an unsafe path');
        }
    }

    $total = max(1, $zip->numFiles);
    $lastProgress = 78;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!$zip->extractTo($extractDir, [$name])) {
            $zip->close();
            throw new RuntimeException('failed to extract ' . $name);
        }
        $progress = 78 + (int)floor((($i + 1) / $total) * 10);
        if ($progress > $lastProgress || $i === 0 || $i === $zip->numFiles - 1) {
            $lastProgress = min(88, $progress);
            dev_bootstrap_emit('extract', $lastProgress, 'extracting archive...', [
                'log' => 'extract ' . ($i + 1) . '/' . $total . ' (' . min(100, (int)floor((($i + 1) / $total) * 100)) . '%) - ' . $name,
            ]);
        }
    }
    $zip->close();

    $dataDir = $extractDir . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        throw new RuntimeException('downloaded zip did not contain a data directory');
    }

    return $dataDir;
}

function dev_bootstrap_extract_zip_with_unzip(string $zipPath, string $extractDir): void
{
    $unzip = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
    if ($unzip === '') {
        throw new RuntimeException('PHP zip extension or system unzip is required to extract the dev data archive');
    }

    $listCmd = escapeshellcmd($unzip) . ' -Z -1 ' . escapeshellarg($zipPath);
    $listOutput = [];
    $listStatus = 0;
    @exec($listCmd . ' 2>&1', $listOutput, $listStatus);
    if ($listStatus !== 0) {
        throw new RuntimeException('could not inspect zip paths: ' . implode("\n", $listOutput));
    }
    foreach ($listOutput as $name) {
        $normalized = str_replace('\\', '/', (string)$name);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../') || $normalized === '..') {
            throw new RuntimeException('zip contains an unsafe path');
        }
    }

    $total = max(1, count($listOutput));
    $cmd = escapeshellcmd($unzip)
        . ' -o '
        . escapeshellarg($zipPath)
        . ' -d '
        . escapeshellarg($extractDir);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start system unzip');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $extracted = 0;
    $lastProgress = 78;
    $outputTail = [];
    $exitCode = null;
    while (true) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $chunk = trim((string)$stdout . "\n" . (string)$stderr);
        if ($chunk !== '') {
            foreach (array_filter(array_map('trim', explode("\n", $chunk))) as $line) {
                $outputTail[] = $line;
                $outputTail = array_slice($outputTail, -8);
                if (preg_match('/^(extracting|inflating|creating):\s*(.+)$/i', $line, $match) === 1) {
                    $extracted++;
                    $progress = 78 + (int)floor(($extracted / $total) * 10);
                    if ($progress > $lastProgress || $extracted === 1 || $extracted >= $total) {
                        $lastProgress = min(88, $progress);
                        dev_bootstrap_emit('extract', $lastProgress, 'extracting archive...', [
                            'log' => 'extract ' . min($extracted, $total) . '/' . $total . ' (' . min(100, (int)floor(($extracted / $total) * 100)) . '%) - ' . trim((string)$match[2]),
                        ]);
                    }
                }
            }
        }

        $procStatus = proc_get_status($process);
        if (!$procStatus['running']) {
            $exitCode = is_int($procStatus['exitcode']) && $procStatus['exitcode'] >= 0 ? $procStatus['exitcode'] : null;
            break;
        }
        usleep(100000);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $chunk = trim((string)$stdout . "\n" . (string)$stderr);
    if ($chunk !== '') {
        foreach (array_filter(array_map('trim', explode("\n", $chunk))) as $line) {
            $outputTail[] = $line;
            $outputTail = array_slice($outputTail, -8);
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeStatus = proc_close($process);
    $status = $exitCode ?? $closeStatus;

    if ($status !== 0) {
        throw new RuntimeException('system unzip failed: ' . implode("\n", $outputTail));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    dev_bootstrap_fail('method not allowed', 405);
}

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    @ob_end_flush();
}

$root = dirname(__DIR__, 2);
$isLocalDev = function_exists('fridg3_is_local_dev_server') && fridg3_is_local_dev_server();
$isAdmin = isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']);
$hasAdminAccount = dev_bootstrap_has_admin_account($root);

if (!$isLocalDev) {
    dev_bootstrap_fail('dev data bootstrap is only available when developer mode is on.', 403);
}
if (!$isAdmin && $hasAdminAccount) {
    dev_bootstrap_fail('admin login required to replace local data.', 403);
}
session_write_close();

dev_bootstrap_emit('initialize', 2, 'preparing temporary bootstrap workspace...', [
    'log' => 'authorization passed; request detached from the session lock',
]);

$bootstrapRoot = $root . DIRECTORY_SEPARATOR . '.bootstrap';
$tmpRoot = $bootstrapRoot . DIRECTORY_SEPARATOR . 'run-' . bin2hex(random_bytes(6));
$zipPath = $tmpRoot . DIRECTORY_SEPARATOR . 'dev-data.zip';
$extractDir = $tmpRoot . DIRECTORY_SEPARATOR . 'extract';
$dataPath = $root . DIRECTORY_SEPARATOR . 'data';

try {
    if (!@mkdir($bootstrapRoot, 0777, true) && !is_dir($bootstrapRoot)) {
        throw new RuntimeException('could not create .bootstrap directory');
    }
    if (!@mkdir($tmpRoot, 0777, true) && !is_dir($tmpRoot)) {
        throw new RuntimeException('could not create temporary bootstrap directory');
    }
    if (!@mkdir($extractDir, 0777, true) && !is_dir($extractDir)) {
        throw new RuntimeException('could not create temporary extraction directory');
    }

    dev_bootstrap_emit('initialize', 5, 'temporary bootstrap workspace ready...', [
        'log' => 'download and extraction destinations created',
    ]);

    dev_bootstrap_emit('listing', 10, 'checking Google Drive folder...');
    $archive = dev_bootstrap_latest_archive_from_drive(FRIDG3_DEV_BOOTSTRAP_FOLDER_ID);
    dev_bootstrap_emit('found', 24, 'found ' . $archive['name'], [
        'archive' => $archive['name'],
    ]);

    dev_bootstrap_download_drive_file($archive, $zipPath);
    dev_bootstrap_emit('download', 72, 'downloaded ' . $archive['name']);

    dev_bootstrap_emit('extract', 78, 'extracting archive...');
    $extractedDataDir = dev_bootstrap_extract_zip($zipPath, $extractDir);
    dev_bootstrap_emit('extract', 88, 'archive extracted');

    dev_bootstrap_emit('delete', 90, 'deleting existing local data directory...');
    if (file_exists($dataPath) || is_link($dataPath)) {
        dev_bootstrap_remove_path($dataPath);
        dev_bootstrap_emit('delete', 92, 'existing local data directory deleted...', [
            'log' => 'old developer data removed; beginning replacement',
        ]);
    } else {
        dev_bootstrap_emit('delete', 92, 'no existing local data directory found...', [
            'log' => 'nothing needed to be deleted before installation',
        ]);
    }
    dev_bootstrap_emit('install', 94, 'installing downloaded data directory...');
    if (!@rename($extractedDataDir, $dataPath)) {
        try {
            dev_bootstrap_copy_path($extractedDataDir, $dataPath);
            dev_bootstrap_remove_path($extractedDataDir);
        } catch (Throwable $copyError) {
            if (file_exists($dataPath) || is_link($dataPath)) {
                dev_bootstrap_remove_path($dataPath);
            }
            throw new RuntimeException('could not install downloaded data directory: ' . $copyError->getMessage());
        }
    }

    dev_bootstrap_emit('done', 100, 'dev data installed from ' . $archive['name'], [
        'archive' => $archive['name'],
    ]);
} catch (Throwable $e) {
    dev_bootstrap_fail($e->getMessage());
} finally {
    if (is_dir($tmpRoot)) {
        try {
            dev_bootstrap_remove_path($tmpRoot);
            dev_bootstrap_emit('cleanup', 100, isset($archive['name']) ? 'dev data installed from ' . $archive['name'] : 'cleaning temporary bootstrap files...', [
                'log' => 'temporary download and extraction workspace removed',
            ]);
        } catch (Throwable $ignored) {
            /* best effort */
        }
    }
    if (isset($bootstrapRoot) && is_dir($bootstrapRoot)) {
        @rmdir($bootstrapRoot);
    }
}

?>
