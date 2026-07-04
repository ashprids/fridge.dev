<?php
declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "sanitized data root is missing\n");
    exit(1);
}

function pathFor(string $root, string $relativePath): string
{
    return $root . '/' . ltrim($relativePath, '/');
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException("failed to create directory: {$path}");
    }
}

function writeJson(string $root, string $relativePath, mixed $value): void
{
    $path = pathFor($root, $relativePath);
    ensureDirectory(dirname($path));

    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("failed to encode json for {$relativePath}");
    }

    file_put_contents($path, $json . PHP_EOL, LOCK_EX);
}

function readJsonObject(string $root, string $relativePath): array
{
    $path = pathFor($root, $relativePath);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function clearDirectory(string $root, string $relativePath): void
{
    $path = pathFor($root, $relativePath);
    ensureDirectory($path);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

function removePath(string $root, string $relativePath): void
{
    $path = pathFor($root, $relativePath);
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (!is_dir($path) || is_link($path)) {
        unlink($path);
        return;
    }

    clearDirectory($root, $relativePath);
    rmdir($path);
}

function blankScalarValues(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $childValue) {
            $value[$key] = blankScalarValues($childValue);
        }

        return $value;
    }

    if (is_bool($value)) {
        return false;
    }

    if (is_int($value) || is_float($value)) {
        return 0;
    }

    return '';
}

function replaceKeys(mixed $value, array $replacements): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $childValue) {
        if (array_key_exists((string) $key, $replacements)) {
            $value[$key] = $replacements[(string) $key];
            continue;
        }

        $value[$key] = replaceKeys($childValue, $replacements);
    }

    return $value;
}

function isIpLikeString(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    return preg_match('/^\[?[a-f0-9:]{2,}\]?$/i', $value) === 1
        && str_contains($value, ':')
        && filter_var(trim($value, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function scrubIpValues(mixed $value): mixed
{
    if (!is_array($value)) {
        return is_string($value) && isIpLikeString($value) ? '' : $value;
    }

    $scrubbed = [];
    foreach ($value as $key => $childValue) {
        $stringKey = (string) $key;
        if (strtolower($stringKey) === 'ip') {
            $scrubbed[$key] = '';
            continue;
        }
        if (isIpLikeString($stringKey)) {
            continue;
        }

        $scrubbed[$key] = scrubIpValues($childValue);
    }

    return $scrubbed;
}

function sanitizeFeedReplies(string $root): void
{
    $replyDir = pathFor($root, 'feed/replies');
    ensureDirectory($replyDir);

    foreach (glob($replyDir . '/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            continue;
        }

        if (isset($decoded['replies']) && is_array($decoded['replies'])) {
            foreach ($decoded['replies'] as &$reply) {
                if (!is_array($reply)) {
                    continue;
                }

                if (!empty($reply['isGuest'])) {
                    $reply['ip'] = '';
                    unset($reply['guestBrowserId']);
                }

                $reply = scrubIpValues($reply);
            }
            unset($reply);
        }

        $sanitized = scrubIpValues($decoded);
        $json = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("failed to encode sanitized feed replies: {$path}");
        }

        file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    }
}

/*
 * Edit this block when new sensitive /data paths need scrubbing.
 * Keep the output useful for local dev, but never ship secrets,
 * account details, IP logs, webhook URLs, or private drafts.
 */
writeJson($root, 'accounts/accounts.json', [
    'accounts' => [],
]);

writeJson($root, 'accounts/login_attempts.json', new stdClass());
writeJson($root, 'etc/page_views.json', ['pages' => new stdClass(), 'updated_at' => null]);

$toast = readJsonObject($root, 'etc/toast.json');
$toast['bot'] = is_array($toast['bot'] ?? null) ? $toast['bot'] : [];
$toast['bot']['token'] = '';
$toast['bot']['client_id'] = '';
$toast['groq'] = is_array($toast['groq'] ?? null) ? $toast['groq'] : [];
$toast['groq']['api_key'] = '';
writeJson($root, 'etc/toast.json', $toast);

$toastPersonality = readJsonObject($root, 'etc/toast-personality.json');
writeJson($root, 'etc/toast-personality.json', replaceKeys($toastPersonality, [
    'private_lore' => '',
]));

$webhooks = readJsonObject($root, 'etc/webhooks.json');
writeJson($root, 'etc/webhooks.json', blankScalarValues($webhooks));

writeJson($root, 'guestbook/ip_index.json', new stdClass());
writeJson($root, 'feed/banned_ips.json', []);
sanitizeFeedReplies($root);
writeJson($root, 'contact/rate_limits.json', new stdClass());
writeJson($root, 'etc/toast-dm-history.json', new stdClass());
writeJson($root, 'etc/toast-feed-notify-state.json', [
    'mentions' => new stdClass(),
    'replies' => new stdClass(),
    'reply_mentions_initialized' => false,
]);
writeJson($root, 'etc/off-topic-archive.json', [
    'channels' => [],
    'exported_at' => null,
]);
writeJson($root, 'upload/rooms.json', ['rooms' => new stdClass()]);

clearDirectory($root, 'mdpaste');
clearDirectory($root, 'chat');

clearDirectory($root, 'journal/drafts');
file_put_contents(
    pathFor($root, 'journal/drafts/dev-placeholder.txt'),
    "USER:admin\nDevelopment placeholder draft\nThis draft exists so local journal draft views have harmless sample content.\nFORMAT:html\n<p>This is placeholder development content.</p>\n",
    LOCK_EX
);
