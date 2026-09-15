<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
require_once $sessionBootstrapDir . "/lib/targeted-notifications.php";
require_once $sessionBootstrapDir . "/lib/video-embeds.php";
fridge_start_session();

$title = 'chat';
$description = 'one-time private conversations without account setup.';
$rootDir = dirname(__DIR__);
$chatDataDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'chat';
$chatKeyPath = $chatDataDir . DIRECTORY_SEPARATOR . '.chat_key';
const CHAT_MAX_ATTACHMENT_BYTES = 8388608;
const CHAT_MAX_VOICE_SOURCE_BYTES = 12000000;

function chat_find_template_file(string $filename): ?string {
    $dir = __DIR__;
    $prevDir = '';

    while ($dir !== $prevDir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prevDir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

function chat_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function chat_json_response(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_cookie_name(string $id): string {
    return 'fridg3_chat_' . $id;
}

function chat_get_conversation_id_from_request(): string {
    if (isset($_GET['id'])) {
        $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)$_GET['id']));
        return is_string($id) && chat_is_valid_conversation_id($id) ? $id : '';
    }

    if (isset($_SERVER['PATH_INFO']) && preg_match('/^\/([a-z0-9]{9}|[a-f0-9]{32})$/', (string)$_SERVER['PATH_INFO'], $matches)) {
        return strtolower($matches[1]);
    }

    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    if (preg_match('#/chat/([a-z0-9]{9}|[a-f0-9]{32})/?$#', $path, $matches)) {
        return strtolower($matches[1]);
    }

    return '';
}

function chat_is_valid_conversation_id(string $id): bool {
    return preg_match('/^(?:[a-z0-9]{9}|[a-f0-9]{32})$/', $id) === 1;
}

function chat_ensure_data_dir(string $chatDataDir): void {
    if (!is_dir($chatDataDir)) {
        @mkdir($chatDataDir, 0750, true);
    }
}

function chat_get_key(string $chatDataDir, string $chatKeyPath): string {
    $envKey = getenv('FRIDG3_CHAT_KEY');
    if (is_string($envKey) && $envKey !== '') {
        $decoded = base64_decode($envKey, true);
        if (is_string($decoded) && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }

        return hash('sha256', $envKey, true);
    }

    chat_ensure_data_dir($chatDataDir);
    if (is_file($chatKeyPath)) {
        $storedKey = @file_get_contents($chatKeyPath);
        if (is_string($storedKey) && strlen($storedKey) >= 32) {
            return substr($storedKey, 0, 32);
        }
    }

    $key = random_bytes(32);
    @file_put_contents($chatKeyPath, $key, LOCK_EX);
    @chmod($chatKeyPath, 0600);
    return $key;
}

function chat_conversation_path(string $chatDataDir, string $id): string {
    return $chatDataDir . DIRECTORY_SEPARATOR . $id . '.json';
}

function chat_generate_conversation_id(string $chatDataDir): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($chars) - 1;

    do {
        $id = '';
        for ($i = 0; $i < 9; $i++) {
            $id .= $chars[random_int(0, $max)];
        }
    } while (is_file(chat_conversation_path($chatDataDir, $id)));

    return $id;
}

function chat_presence_path(string $chatDataDir, string $id): string {
    return $chatDataDir . DIRECTORY_SEPARATOR . '.presence' . DIRECTORY_SEPARATOR . $id . '.json';
}

function chat_attachment_dir(string $chatDataDir, string $id): string {
    return $chatDataDir . DIRECTORY_SEPARATOR . '.attachments' . DIRECTORY_SEPARATOR . $id;
}

function chat_attachment_path(string $chatDataDir, string $conversationId, string $attachmentId): string {
    return chat_attachment_dir($chatDataDir, $conversationId) . DIRECTORY_SEPARATOR . $attachmentId . '.json';
}

function chat_temp_voice_path(): ?string {
    $path = tempnam(sys_get_temp_dir(), 'chat_voice_');
    if ($path === false) {
        return null;
    }
    @unlink($path);
    return $path . '.m4a';
}

function chat_remove_directory(string $directory): void {
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            chat_remove_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function chat_read_presence(string $chatDataDir, string $id): array {
    if (!chat_is_valid_conversation_id($id)) {
        return [];
    }

    $path = chat_presence_path($chatDataDir, $id);
    if (!is_file($path)) {
        return [];
    }

    $presence = json_decode((string)@file_get_contents($path), true);
    return is_array($presence) ? $presence : [];
}

function chat_write_presence(string $chatDataDir, string $id, array $presence): bool {
    if (!chat_is_valid_conversation_id($id)) {
        return false;
    }

    $directory = dirname(chat_presence_path($chatDataDir, $id));
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }

    $encoded = json_encode($presence, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return @file_put_contents(chat_presence_path($chatDataDir, $id), $encoded, LOCK_EX) !== false;
}

function chat_read_conversation(string $chatDataDir, string $chatKeyPath, string $id): ?array {
    if (!chat_is_valid_conversation_id($id)) {
        return null;
    }

    $path = chat_conversation_path($chatDataDir, $id);
    if (!is_file($path)) {
        return null;
    }

    $envelope = json_decode((string)@file_get_contents($path), true);
    if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1) {
        return null;
    }

    $nonce = base64_decode((string)($envelope['nonce'] ?? ''), true);
    $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($envelope['ciphertext'] ?? ''), true);
    if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
        return null;
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        chat_get_key($chatDataDir, $chatKeyPath),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if (!is_string($plaintext)) {
        return null;
    }

    $conversation = json_decode($plaintext, true);
    return is_array($conversation) ? $conversation : null;
}

function chat_write_conversation(string $chatDataDir, string $chatKeyPath, array $conversation): bool {
    chat_ensure_data_dir($chatDataDir);
    $id = (string)($conversation['id'] ?? '');
    if (!chat_is_valid_conversation_id($id)) {
        return false;
    }

    $plaintext = json_encode($conversation, JSON_UNESCAPED_SLASHES);
    if ($plaintext === false) {
        return false;
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        chat_get_key($chatDataDir, $chatKeyPath),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if (!is_string($ciphertext) || !is_string($tag)) {
        return false;
    }

    $envelope = json_encode([
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($envelope === false) {
        return false;
    }

    $path = chat_conversation_path($chatDataDir, $id);
    $tempPath = tempnam($chatDataDir, 'chat_');
    if ($tempPath === false) {
        return @file_put_contents($path, $envelope, LOCK_EX) !== false;
    }

    $ok = @file_put_contents($tempPath, $envelope, LOCK_EX) !== false && @rename($tempPath, $path);
    if (!$ok) {
        @unlink($tempPath);
    }
    return $ok;
}

function chat_delete_conversation(string $chatDataDir, string $id): bool {
    if (!chat_is_valid_conversation_id($id)) {
        return false;
    }

    $path = chat_conversation_path($chatDataDir, $id);
    @unlink(chat_presence_path($chatDataDir, $id));
    chat_remove_directory(chat_attachment_dir($chatDataDir, $id));
    return !is_file($path) || @unlink($path);
}

function chat_load_all_conversations(string $chatDataDir, string $chatKeyPath): array {
    if (!is_dir($chatDataDir)) {
        return [];
    }

    $conversations = [];
    foreach (glob($chatDataDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $id);
        if (is_array($conversation)) {
            $conversations[] = $conversation;
        }
    }

    usort($conversations, static function (array $a, array $b): int {
        return (int)($b['createdAt'] ?? 0) <=> (int)($a['createdAt'] ?? 0);
    });

    return $conversations;
}

function chat_find_account_conversation(string $chatDataDir, string $chatKeyPath, string $username): ?array {
    if ($username === '') {
        return null;
    }

    $matches = [];
    foreach (chat_load_all_conversations($chatDataDir, $chatKeyPath) as $conversation) {
        if ((string)($conversation['participantUsername'] ?? '') !== $username) {
            continue;
        }
        $messages = (array)($conversation['messages'] ?? []);
        $lastMessage = end($messages);
        $lastActivity = is_array($lastMessage)
            ? (int)($lastMessage['createdAt'] ?? 0)
            : (int)($conversation['claimedAt'] ?? $conversation['createdAt'] ?? 0);
        $conversation['_lastActivity'] = $lastActivity;
        $matches[] = $conversation;
    }

    usort($matches, static function (array $a, array $b): int {
        return (int)($b['_lastActivity'] ?? 0) <=> (int)($a['_lastActivity'] ?? 0);
    });

    return $matches[0] ?? null;
}

function chat_refresh_current_user_permissions(): void {
    if (!isset($_SESSION['user']['username'])) {
        return;
    }

    $accountsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    if (!is_file($accountsPath)) {
        return;
    }

    $accountsData = json_decode((string)@file_get_contents($accountsPath), true);
    if (!is_array($accountsData) || !isset($accountsData['accounts']) || !is_array($accountsData['accounts'])) {
        return;
    }

    foreach ($accountsData['accounts'] as $account) {
        if (($account['username'] ?? null) !== $_SESSION['user']['username']) {
            continue;
        }

        $_SESSION['user']['name'] = chat_h((string)($account['name'] ?? ''));
        $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
        $_SESSION['user']['postingRestricted'] = (bool)($account['postingRestricted'] ?? false);
        $_SESSION['user']['allowedPages'] = array_map('strval', (array)($account['allowedPages'] ?? []));
        return;
    }
}

function chat_user_can_manage(): bool {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    $allowedPages = array_map('strval', (array)($_SESSION['user']['allowedPages'] ?? []));
    return !empty($_SESSION['user']['isAdmin']) || in_array('chat', $allowedPages, true);
}

function chat_user_is_admin(): bool {
    return !empty($_SESSION['user']['isAdmin']);
}

function chat_username_is_admin(string $username): bool {
    $username = strtolower(trim($username));
    if ($username === '') return false;
    $accountsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $accountsData = is_file($accountsPath) ? json_decode((string)@file_get_contents($accountsPath), true) : [];
    foreach ((array)($accountsData['accounts'] ?? []) as $account) {
        if (!is_array($account) || strtolower(trim((string)($account['username'] ?? ''))) !== $username) continue;
        return filter_var($account['isAdmin'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
    return false;
}

function chat_current_username(): string {
    return isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
}

function chat_is_account_participant(array $conversation): bool {
    $username = chat_current_username();
    return $username !== ''
        && (string)($conversation['participantUsername'] ?? '') !== ''
        && hash_equals((string)$conversation['participantUsername'], $username);
}

function chat_get_viewer_role(array $conversation, string $conversationId, bool $canManage): string {
    if ($canManage) {
        return 'manager';
    }

    if (chat_is_account_participant($conversation)) {
        return 'participant';
    }

    $cookieSecret = (string)($_COOKIE[chat_cookie_name($conversationId)] ?? '');
    $cookieHash = $cookieSecret === '' ? '' : hash('sha256', $cookieSecret);
    $participantHash = (string)($conversation['participantHash'] ?? '');

    return $cookieHash !== '' && $participantHash !== '' && hash_equals($participantHash, $cookieHash)
        ? 'participant'
        : '';
}

function chat_presence_payload(array $presence, string $viewerRole): array {
    $otherRole = $viewerRole === 'manager' ? 'participant' : 'manager';
    $otherPresence = $presence[$otherRole] ?? 0;
    if (is_array($otherPresence)) {
        $lastSeen = (int)($otherPresence['lastSeen'] ?? 0);
        $isActive = (bool)($otherPresence['active'] ?? false);
        $typingUntil = (int)($otherPresence['typingUntil'] ?? 0);
    } else {
        $lastSeen = (int)$otherPresence;
        $isActive = true;
        $typingUntil = 0;
    }
    $isRecent = $lastSeen > 0 && (time() - $lastSeen) <= 15;
    $status = $isRecent ? ($isActive ? 'online' : 'away') : 'offline';
    $isTyping = $isRecent && $typingUntil >= time();

    return [
        'ok' => true,
        'viewerRole' => $viewerRole,
        'otherRole' => $otherRole,
        'otherOnline' => $status === 'online',
        'otherAway' => $status === 'away',
        'otherStatus' => $status,
        'otherTyping' => $isTyping,
        'otherLastSeen' => $lastSeen,
    ];
}

function chat_request_wants_json(): bool {
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $requestedWith = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

    return stripos($accept, 'application/json') !== false
        || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
}

function chat_user_can_view_conversation(array $conversation, string $conversationId, bool $canManage): bool {
    return chat_get_viewer_role($conversation, $conversationId, $canManage) !== '';
}

function chat_user_can_delete_conversation(array $conversation, bool $canManage): bool {
    return $canManage || chat_is_account_participant($conversation);
}

function chat_clean_filename(string $filename): string {
    $filename = trim(basename($filename));
    $filename = preg_replace('/[^\w.\- ]+/', '_', $filename);
    $filename = is_string($filename) ? trim($filename, " .\t\n\r\0\x0B") : '';

    return $filename === '' ? 'attachment' : substr($filename, 0, 120);
}

function chat_detect_mime(string $path, string $fallback = 'application/octet-stream'): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    return $fallback;
}

function chat_probe_audio_duration(string $path): ?float {
    if (!is_file($path) || !function_exists('shell_exec')) {
        return null;
    }

    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>/dev/null';
    $output = @shell_exec($cmd);
    if (!is_string($output)) {
        return null;
    }

    $duration = (float)trim($output);
    return $duration > 0 ? $duration : null;
}

function chat_transcode_voice_note(string $srcPath, string $destPath): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }

    @unlink($destPath);
    $cmd = 'ffmpeg -y -v error -i ' . escapeshellarg($srcPath)
        . ' -vn -ac 1 -ar 24000 -c:a aac -b:a 32k -movflags +faststart '
        . escapeshellarg($destPath) . ' 2>/dev/null';
    @shell_exec($cmd);

    if (!is_file($destPath) || (@filesize($destPath) ?: 0) <= 0) {
        @unlink($destPath);
        return false;
    }

    $duration = chat_probe_audio_duration($destPath);
    if ($duration === null || $duration > 121.0) {
        @unlink($destPath);
        return false;
    }

    return true;
}

function chat_encrypt_attachment(string $chatDataDir, string $chatKeyPath, string $conversationId, array $upload, string $kind = ''): ?array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = (int)($upload['size'] ?? 0);
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $isVoice = $kind === 'voice';
    $maxBytes = $isVoice ? CHAT_MAX_VOICE_SOURCE_BYTES : CHAT_MAX_ATTACHMENT_BYTES;
    if ($size <= 0 || $size > $maxBytes || !is_uploaded_file($tmpName)) {
        return null;
    }

    $name = chat_clean_filename((string)($upload['name'] ?? 'attachment'));
    $mime = chat_detect_mime($tmpName);
    $duration = null;
    $sourcePath = $tmpName;
    $cleanupPath = null;

    if ($isVoice) {
        $sourceDuration = chat_probe_audio_duration($tmpName);
        if ($sourceDuration !== null && $sourceDuration > 121.0) {
            return null;
        }
        $voicePath = chat_temp_voice_path();
        if ($voicePath === null || !chat_transcode_voice_note($tmpName, $voicePath)) {
            if ($voicePath !== null) {
                @unlink($voicePath);
            }
            return null;
        }
        $duration = chat_probe_audio_duration($voicePath) ?? $sourceDuration;
        $sourcePath = $voicePath;
        $cleanupPath = $voicePath;
        $name = preg_replace('/\.[^.]+$/', '', $name) . '.m4a';
        $mime = 'audio/mp4';
        $size = (int)(@filesize($voicePath) ?: 0);
    } elseif ($size > CHAT_MAX_ATTACHMENT_BYTES) {
        return null;
    }

    $data = @file_get_contents($sourcePath);
    if ($cleanupPath !== null) {
        @unlink($cleanupPath);
    }
    if (!is_string($data)) {
        return null;
    }

    $attachmentId = bin2hex(random_bytes(16));
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $data,
        'aes-256-gcm',
        chat_get_key($chatDataDir, $chatKeyPath),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if (!is_string($ciphertext) || !is_string($tag)) {
        return null;
    }

    $directory = chat_attachment_dir($chatDataDir, $conversationId);
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }

    $envelopeData = [
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'name' => $name,
        'mime' => $mime,
        'size' => $size,
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ];
    if ($isVoice) {
        $envelopeData['kind'] = 'voice';
        $envelopeData['duration'] = $duration;
    }
    $envelope = json_encode($envelopeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($envelope === false) {
        return null;
    }

    if (@file_put_contents(chat_attachment_path($chatDataDir, $conversationId, $attachmentId), $envelope, LOCK_EX) === false) {
        return null;
    }

    $metadata = [
        'id' => $attachmentId,
        'name' => $name,
        'mime' => $mime,
        'size' => $size,
    ];
    if ($isVoice) {
        $metadata['kind'] = 'voice';
        $metadata['duration'] = $duration;
    }
    return $metadata;
}

function chat_load_attachment(string $chatDataDir, string $chatKeyPath, string $conversationId, string $attachmentId): ?array {
    if (!chat_is_valid_conversation_id($conversationId) || !preg_match('/^[a-f0-9]{32}$/', $attachmentId)) {
        return null;
    }

    $path = chat_attachment_path($chatDataDir, $conversationId, $attachmentId);
    if (!is_file($path)) {
        return null;
    }

    $envelope = json_decode((string)@file_get_contents($path), true);
    if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1) {
        return null;
    }

    $nonce = base64_decode((string)($envelope['nonce'] ?? ''), true);
    $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($envelope['ciphertext'] ?? ''), true);
    if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
        return null;
    }

    $data = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        chat_get_key($chatDataDir, $chatKeyPath),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if (!is_string($data)) {
        return null;
    }

    return [
        'name' => chat_clean_filename((string)($envelope['name'] ?? 'attachment')),
        'mime' => (string)($envelope['mime'] ?? 'application/octet-stream'),
        'size' => (int)($envelope['size'] ?? strlen($data)),
        'kind' => (string)($envelope['kind'] ?? ''),
        'duration' => isset($envelope['duration']) ? (float)$envelope['duration'] : null,
        'data' => $data,
    ];
}

function chat_format_bytes(int $bytes): string {
    if ($bytes >= 1048576) {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }

    if ($bytes >= 1024) {
        return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    }

    return $bytes . ' B';
}

function chat_attachment_extension(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function chat_attachment_is_audio(string $mime, string $name): bool {
    return str_starts_with(strtolower($mime), 'audio/')
        || in_array(chat_attachment_extension($name), ['mp3', 'wav', 'ogg', 'oga', 'flac'], true);
}

function chat_attachment_is_video(string $mime, string $name): bool {
    return str_starts_with(strtolower($mime), 'video/')
        || in_array(chat_attachment_extension($name), ['mp4', 'webm', 'ogv', 'mov', 'm4v', 'mkv', 'avi'], true);
}

function chat_message_label(array $message, string $viewerRole, string $recipientName): string {
    $sender = (string)($message['sender'] ?? 'unknown');
    if ($sender === $viewerRole) {
        return 'you';
    }

    return $sender === 'manager' ? 'fridge' : $recipientName;
}

function chat_message_summary(array $message): string {
    if (!empty($message['deletedAt'])) {
        return 'message deleted';
    }

    $body = trim(preg_replace('/\s+/', ' ', (string)($message['body'] ?? '')));
    if ($body !== '') {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '...' : $body;
        }
        return strlen($body) > 120 ? substr($body, 0, 117) . '...' : $body;
    }

    $attachment = is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
    if ($attachment !== null) {
        if ((string)($attachment['kind'] ?? '') === 'voice') {
            return 'voice note';
        }
        return 'attachment: ' . chat_clean_filename((string)($attachment['name'] ?? 'file'));
    }

    return 'message';
}

function chat_extract_urls(string $body): array {
    if (preg_match_all('~https?://[^\s<>"\']+~iu', $body, $matches) !== 1) return [];
    $urls = [];
    foreach ($matches[0] as $candidate) {
        $url = rtrim((string)$candidate, '.,!?;:)\]}');
        if (filter_var($url, FILTER_VALIDATE_URL) && !isset($urls[$url])) $urls[$url] = true;
        if (count($urls) >= 4) break;
    }
    return array_keys($urls);
}

function chat_linkify_body(string $body): string {
    $parts = preg_split('~(https?://[^\s<>"\']+)~iu', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) return nl2br(chat_h($body), false);
    $html = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $html .= nl2br(chat_h($part), false);
            continue;
        }
        $url = rtrim($part, '.,!?;:)\]}');
        $suffix = substr($part, strlen($url));
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $html .= chat_h($part);
            continue;
        }
        $html .= '<a class="chat-message-link" href="' . chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . chat_h($url) . '</a>' . chat_h($suffix);
    }
    return $html;
}

function chat_giphy_embed_url(string $url): ?string {
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = (string)($parts['path'] ?? '');
    if ($host !== 'giphy.com') return null;
    if (preg_match('~/embed/([a-zA-Z0-9]+)~', $path, $match) || preg_match('~/gifs/(?:[^/]*-)?([a-zA-Z0-9]+)$~', $path, $match)) {
        return 'https://giphy.com/embed/' . rawurlencode($match[1]);
    }
    return null;
}

function chat_url_host_is_public(string $url): bool {
    $host = strtolower(rtrim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach (gethostbynamel($host) ?: [] as $ip) $ips[] = $ip;
        if (function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
            }
        }
    }
    if ($ips === []) return false;
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

function chat_fetch_link_metadata(string $url): ?array {
    if (!chat_url_host_is_public($url)) return null;
    $body = '';
    $contentType = '';
    $status = 0;
    $fetchSucceeded = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_USERAGENT => 'fridge.dev chat link preview/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentType): int {
            if (stripos($header, 'Content-Type:') === 0) $contentType = trim(substr($header, 13));
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            $remaining = 262144 - strlen($body);
            if ($remaining <= 0) return 0;
            $body .= substr($chunk, 0, $remaining);
            return strlen($chunk) <= $remaining ? strlen($chunk) : 0;
        },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $curlError = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $stoppedAtLimit = $ok === false && defined('CURLE_WRITE_ERROR') && $curlError === CURLE_WRITE_ERROR && strlen($body) >= 262144;
        $fetchSucceeded = $ok !== false || $stoppedAtLimit;
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 4, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true,
            'header' => "Accept: text/html,application/xhtml+xml;q=0.9\r\nUser-Agent: fridge.dev chat link preview/1.0\r\n",
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (is_resource($stream)) {
            $body = (string)stream_get_contents($stream, 262144);
            $headers = $http_response_header ?? [];
            fclose($stream);
            foreach ($headers as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~i', $header, $match)) $status = (int)$match[1];
                if (stripos($header, 'Content-Type:') === 0) $contentType = trim(substr($header, 13));
            }
            $fetchSucceeded = true;
        }
    }
    if (!$fetchSucceeded || $status < 200 || $status >= 300) return null;
    if (stripos($contentType, 'image/') !== false) {
        return ['title' => rawurldecode(basename((string)(parse_url($url, PHP_URL_PATH) ?? ''))) ?: 'linked image', 'description' => '', 'image' => $url];
    }
    if ($contentType !== '' && stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml+xml') === false) return null;

    $title = '';
    $description = '';
    $image = '';
    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        if (@$document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($document->getElementsByTagName('meta') as $meta) {
                $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
                $value = trim($meta->getAttribute('content'));
                if ($value === '') continue;
                if ($description === '' && in_array($key, ['og:description', 'twitter:description', 'description'], true)) $description = $value;
                if ($title === '' && in_array($key, ['og:title', 'twitter:title'], true)) $title = $value;
                if ($image === '' && in_array($key, ['og:image', 'twitter:image', 'twitter:image:src'], true)) $image = $value;
            }
            if ($title === '') {
                $titles = $document->getElementsByTagName('title');
                if ($titles->length > 0) $title = trim((string)$titles->item(0)?->textContent);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if ($description === '' && preg_match_all('/<meta\b[^>]*>/i', $body, $metaTags)) {
        foreach ($metaTags[0] as $tag) {
            $attributes = [];
            if (preg_match_all('/([a-zA-Z:-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) $attributes[strtolower($match[1])] = $match[3];
            }
            $key = strtolower(trim((string)($attributes['property'] ?? $attributes['name'] ?? '')));
            $value = trim((string)($attributes['content'] ?? ''));
            if ($description === '' && $value !== '' && in_array($key, ['og:description', 'twitter:description', 'description'], true)) $description = $value;
            if ($title === '' && $value !== '' && in_array($key, ['og:title', 'twitter:title'], true)) $title = $value;
            if ($image === '' && $value !== '' && in_array($key, ['og:image', 'twitter:image', 'twitter:image:src'], true)) $image = $value;
        }
    }
    if ($title === '' && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $body, $match)) $title = $match[1];
    $clean = static function (string $value, int $limit): string {
        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    };
    $title = $clean($title, 160);
    $description = $clean($description, 320);
    if (str_starts_with($image, '//')) $image = 'https:' . $image;
    if ($image !== '' && !preg_match('~^https?://~i', $image)) {
        $baseParts = parse_url($url);
        $origin = (string)($baseParts['scheme'] ?? 'https') . '://' . (string)($baseParts['host'] ?? '');
        $image = $origin . '/' . ltrim($image, '/');
    }
    if ($image !== '' && !filter_var($image, FILTER_VALIDATE_URL)) $image = '';
    return ($title === '' && $description === '' && $image === '') ? null : ['title' => $title, 'description' => $description, 'image' => $image];
}

function chat_collect_link_metadata(string $body): array {
    $metadata = [];
    foreach (chat_extract_urls($body) as $url) {
        $parts = parse_url($url);
        $extension = strtolower(pathinfo((string)($parts['path'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg','jpeg','png','gif','webp','avif','mp4','webm','ogv','mov','m4v','mp3','wav','ogg','oga','flac','m4a'], true)) continue;
        $meta = chat_fetch_link_metadata($url);
        if ($meta !== null) $metadata[$url] = $meta;
    }
    return $metadata;
}

function chat_backfill_link_metadata(array &$conversation, int $limit = 5): bool {
    $messages = (array)($conversation['messages'] ?? []);
    $changed = false;
    $checked = 0;
    foreach ($messages as &$message) {
        if (!is_array($message) || (int)($message['linkMetadataVersion'] ?? 0) >= 2 || !empty($message['deletedAt'])) continue;
        $body = (string)($message['body'] ?? '');
        if (chat_extract_urls($body) !== []) {
            $metadata = chat_collect_link_metadata($body);
            if ($metadata !== []) $message['linkMetadata'] = $metadata;
        }
        $message['linkMetadataChecked'] = true;
        $message['linkMetadataVersion'] = 2;
        $changed = true;
        $checked++;
        if ($checked >= $limit) break;
    }
    unset($message);
    if ($changed) $conversation['messages'] = $messages;
    return $changed;
}

function chat_link_embeds_html(string $body, array $metadata = []): string {
    $html = '';
    foreach (chat_extract_urls($body) as $url) {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? 'link'));
        $path = (string)($parts['path'] ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $video = fridge_external_video_embed_data($url);
        $giphyUrl = chat_giphy_embed_url($url);
        $meta = is_array($metadata[$url] ?? null) ? $metadata[$url] : [];
        $cardTitle = trim((string)($meta['title'] ?? '')) ?: $host;
        $cardDescription = trim((string)($meta['description'] ?? ''));
        if ($video !== null) {
            $html .= '<div class="chat-link-embed chat-link-video">' . fridge_external_video_embed_html($video) . '</div>';
        } elseif ($giphyUrl !== null) {
            $html .= '<div class="chat-link-embed chat-link-video chat-link-giphy"><iframe src="' . chat_h($giphyUrl) . '" title="Giphy animation" loading="lazy" allowfullscreen></iframe></div>';
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            $html .= '<a class="chat-link-embed chat-link-image" href="' . chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow"><img src="' . chat_h($url) . '" alt="linked image" loading="lazy"></a>';
        } elseif (in_array($extension, ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)) {
            $html .= '<div class="chat-link-embed chat-link-video chat-link-direct-video"><video controls preload="metadata" playsinline src="' . chat_h($url) . '"></video></div>';
        } elseif (in_array($extension, ['mp3', 'wav', 'ogg', 'oga', 'flac', 'm4a'], true)) {
            $html .= '<div class="chat-link-embed"><audio controls preload="metadata" src="' . chat_h($url) . '"></audio><a href="' . chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . chat_h(basename($path) ?: $host) . '</a></div>';
        } else {
            $label = basename($path);
            $label = $label !== '' ? rawurldecode($label) : $host;
            $cardDescription = trim((string)($meta['description'] ?? '')) ?: $label;
            $cardImage = trim((string)($meta['image'] ?? ''));
            $html .= '<a class="chat-link-embed chat-link-card" href="' . chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">'
                . ($cardImage !== '' ? '<img src="' . chat_h($cardImage) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '')
                . '<strong>' . chat_h($cardTitle) . '</strong><span>' . chat_h($cardDescription) . '</span></a>';
        }
    }
    return $html === '' ? '' : '<div class="chat-link-embeds">' . $html . '</div>';
}

function chat_normalize_emoji(string $emoji): string {
    $emoji = trim($emoji);
    if (
        $emoji === ''
        || strlen($emoji) > 64
        || preg_match('/[\x00-\x1F\x7F]/u', $emoji)
        || !preg_match('/(?:\p{Extended_Pictographic}|\p{Regional_Indicator}|[#*0-9]\x{FE0F}?\x{20E3})/u', $emoji)
    ) {
        return '';
    }

    return $emoji;
}

function chat_messages_revision(array $messages): string {
    return sha1((string)json_encode($messages, JSON_UNESCAPED_SLASHES));
}

function chat_last_message_payload(array $messages): array {
    $lastMessage = end($messages);
    return [
        'lastMessageId' => is_array($lastMessage) ? (string)($lastMessage['id'] ?? '') : '',
        'lastMessageSender' => is_array($lastMessage) ? (string)($lastMessage['sender'] ?? '') : '',
    ];
}

function chat_set_participant_cookie(string $id, string $secret, ?int $expires = null): void {
    setcookie(chat_cookie_name($id), $secret, [
        'expires' => $expires ?? time() + 60 * 60 * 24 * 365,
        'path' => '/chat',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[chat_cookie_name($id)] = $secret;
}

function chat_render_page(string $title, string $description, string $content): void {
    $content = '<style id="chat-page-shell-overrides">#content-footer{display:none!important}</style>' . $content;
    $renderHelperPath = chat_find_template_file('lib/render.php');
    if ($renderHelperPath) {
        require_once $renderHelperPath;
    }

    $templateName = function_exists('get_preferred_template_name')
        ? get_preferred_template_name(__DIR__)
        : 'template.html';
    $templatePath = chat_find_template_file($templateName);
    if (!$templatePath && $templateName !== 'template.html') {
        $templatePath = chat_find_template_file('template.html');
    }
    if (!$templatePath) {
        die('page template not found. report this issue to ashton@fridge.dev.');
    }

    $html = (string)file_get_contents($templatePath);
    if (function_exists('apply_preferred_theme_stylesheet')) {
        $html = apply_preferred_theme_stylesheet($html, __DIR__);
    }

    $html = str_replace('{content}', $content, $html);
    $html = str_replace('{title}', $title, $html);
    $html = str_replace('{description}', $description, $html);

    $userGreeting = '';
    if (isset($_SESSION['user']['name'])) {
        $userGreeting = '<div id="user-greeting">Hello, ' . chat_h((string)$_SESSION['user']['name']) . '!</div>';
        $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
        $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
        $html = str_replace($accountBtn, $logoutBtn, $html);
    }

    echo str_replace('{user_greeting}', $userGreeting, $html);
}

function chat_render_error(string $heading, string $subheading, int $statusCode = 403): void {
    http_response_code($statusCode);
    chat_render_page($heading, 'private chat access notice.', '<h1>' . chat_h($heading) . '</h1><h2>' . chat_h($subheading) . '</h2><br><p><a href="/">return home</a></p>');
    exit;
}

function chat_message_html(array $conversation, string $viewerRole): string {
    $messages = (array)($conversation['messages'] ?? []);
    if ($messages === []) {
        return '<div class="chat-empty">no messages yet.</div>';
    }

    $messagesById = [];
    foreach ($messages as $message) {
        if (is_array($message)) {
            $messageId = (string)($message['id'] ?? '');
            if ($messageId !== '') {
                $messagesById[$messageId] = $message;
            }
        }
    }

    $html = '';
    $lastDateKey = '';
    $recipientName = trim((string)($conversation['name'] ?? 'recipient'));
    if ($recipientName === '') {
        $recipientName = 'recipient';
    }
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $createdAt = (int)($message['createdAt'] ?? time());
        $dateKey = date('Y-m-d', $createdAt);
        if ($dateKey !== $lastDateKey) {
            $html .= '<div class="chat-date-divider"><span>' . chat_h(date('M j, Y', $createdAt)) . '</span></div>';
            $lastDateKey = $dateKey;
        }

        $sender = (string)($message['sender'] ?? 'unknown');
        $isOwn = $sender === $viewerRole;
        $isDeleted = !empty($message['deletedAt']);
        $hiddenFor = is_array($message['hiddenFor'] ?? null) ? $message['hiddenFor'] : [];
        $isHidden = !$isDeleted && !empty($hiddenFor[$viewerRole]);
        $senderLabel = chat_message_label($message, $viewerRole, $recipientName);
        $time = date('H:i', $createdAt);
        $messageBody = (string)($message['body'] ?? '');
        $body = $isDeleted ? 'message deleted' : ($isHidden ? 'message hidden' : chat_linkify_body($messageBody));
        $linkMetadata = is_array($message['linkMetadata'] ?? null) ? $message['linkMetadata'] : [];
        $linkEmbedsHtml = !$isDeleted && !$isHidden ? chat_link_embeds_html($messageBody, $linkMetadata) : '';
        $messageSummary = chat_message_summary($message);
        $replyHtml = '';
        $replyTo = (string)($message['replyTo'] ?? '');
        $replyTargetHiddenFor = $replyTo !== '' && isset($messagesById[$replyTo]) && is_array($messagesById[$replyTo]['hiddenFor'] ?? null)
            ? $messagesById[$replyTo]['hiddenFor']
            : [];
        if (!$isDeleted && !$isHidden && $replyTo !== '' && isset($messagesById[$replyTo]) && empty($messagesById[$replyTo]['deletedAt']) && empty($replyTargetHiddenFor[$viewerRole])) {
            $replyMessage = $messagesById[$replyTo];
            $replyHtml = '<button class="chat-reply-reference" type="button" data-scroll-message="' . chat_h($replyTo) . '">'
                . '<strong>' . chat_h(chat_message_label($replyMessage, $viewerRole, $recipientName)) . '</strong>'
                . '<span>' . chat_h(chat_message_summary($replyMessage)) . '</span>'
                . '</button>';
        }
        $attachmentHtml = '';
        $hasImageAttachment = false;
        $hasMediaAttachment = false;
        $attachment = !$isDeleted && !$isHidden && is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
        if ($attachment !== null && isset($conversation['id'])) {
            $attachmentId = (string)($attachment['id'] ?? '');
            $attachmentName = chat_clean_filename((string)($attachment['name'] ?? 'attachment'));
            $attachmentMime = (string)($attachment['mime'] ?? 'application/octet-stream');
            $attachmentSize = chat_format_bytes((int)($attachment['size'] ?? 0));
            $attachmentKind = (string)($attachment['kind'] ?? '');
            $attachmentUrl = '/chat/' . rawurlencode((string)$conversation['id']) . '?action=attachment&file=' . rawurlencode($attachmentId);

            if (str_starts_with($attachmentMime, 'image/')) {
                $hasImageAttachment = true;
                $attachmentHtml = '<div class="chat-attachment chat-attachment-image"><img src="' . chat_h($attachmentUrl) . '" alt="' . chat_h($attachmentName) . '"></div>';
            } elseif (chat_attachment_is_audio($attachmentMime, $attachmentName)) {
                $hasMediaAttachment = true;
                $attachmentHtml = '<div class="chat-attachment chat-attachment-media chat-attachment-audio' . ($attachmentKind === 'voice' ? ' chat-attachment-voice' : '') . '">'
                    . '<audio class="chat-media-element" preload="metadata" src="' . chat_h($attachmentUrl) . '"></audio>'
                    . '<a class="chat-attachment-download" href="' . chat_h($attachmentUrl) . '"><i class="fa-solid ' . ($attachmentKind === 'voice' ? 'fa-microphone' : 'fa-file-audio') . '"></i><span>' . chat_h($attachmentKind === 'voice' ? 'voice note' : $attachmentName) . '</span><small>' . chat_h($attachmentSize) . '</small></a>'
                    . '<div class="chat-media-player" data-media-kind="audio">'
                    . '<button class="chat-media-play" type="button" aria-label="play attachment"><i class="fa-solid fa-play"></i></button>'
                    . '<input class="chat-media-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek attachment">'
                    . '<span class="chat-media-time">0:00 / 0:00</span>'
                    . '<button class="chat-media-speed" type="button" aria-label="playback speed"><span class="chat-media-speed-label">1x</span></button>'
                    . '</div>'
                    . '</div>';
            } elseif (chat_attachment_is_video($attachmentMime, $attachmentName)) {
                $hasMediaAttachment = true;
                $attachmentHtml = '<div class="chat-attachment chat-attachment-media chat-attachment-video">'
                    . '<video class="chat-media-element" preload="metadata" playsinline src="' . chat_h($attachmentUrl) . '"></video>'
                    . '<a class="chat-attachment-download" href="' . chat_h($attachmentUrl) . '"><i class="fa-solid fa-file-video"></i><span>' . chat_h($attachmentName) . '</span><small>' . chat_h($attachmentSize) . '</small></a>'
                    . '<div class="chat-media-player" data-media-kind="video">'
                    . '<button class="chat-media-play" type="button" aria-label="play attachment"><i class="fa-solid fa-play"></i></button>'
                    . '<input class="chat-media-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek attachment">'
                    . '<span class="chat-media-time">0:00 / 0:00</span>'
                    . '<button class="chat-media-mute" type="button" aria-label="mute attachment"><i class="fa-solid fa-volume-high"></i></button>'
                    . '<input class="chat-media-volume" type="range" min="0" max="1" value="1" step="0.01" aria-label="attachment volume">'
                    . '</div>'
                    . '</div>';
            } else {
                $attachmentHtml = '<a class="chat-attachment chat-attachment-file" href="' . chat_h($attachmentUrl) . '"><span>' . chat_h($attachmentName) . '</span><small>' . chat_h($attachmentSize) . '</small></a>';
            }
        }

        $reactionHtml = '';
        $reactions = !$isDeleted && is_array($message['reactions'] ?? null) ? $message['reactions'] : [];
        foreach ($reactions as $emoji => $roles) {
            $emoji = chat_normalize_emoji((string)$emoji);
            if ($emoji === '' || !is_array($roles)) {
                continue;
            }
            $count = 0;
            $reacted = false;
            foreach ($roles as $role => $active) {
                if ($active) {
                    $count++;
                    if ((string)$role === $viewerRole) {
                        $reacted = true;
                    }
                }
            }
            if ($count < 1) {
                continue;
            }
            $reactionHtml .= '<button class="chat-reaction' . ($reacted ? ' reacted' : '') . '" type="button" data-message-id="' . chat_h((string)($message['id'] ?? '')) . '" data-emoji="' . chat_h($emoji) . '" aria-label="reaction ' . chat_h($emoji) . ' from ' . $count . ' user(s)">' . chat_h($emoji) . '</button>';
        }
        if ($reactionHtml !== '') {
            $reactionHtml = '<div class="chat-reactions">' . $reactionHtml . '</div>';
        }

        $messageClasses = [
            'chat-message',
            'chat-message-' . ($isOwn ? 'own' : 'other'),
            'chat-message-' . $sender,
        ];
        if ($hasImageAttachment) {
            $messageClasses[] = 'chat-message-has-image';
        }
        if ($hasMediaAttachment) {
            $messageClasses[] = 'chat-message-has-media';
        }
        if ($isDeleted) {
            $messageClasses[] = 'chat-message-deleted';
        }
        if ($isHidden) {
            $messageClasses[] = 'chat-message-hidden';
        }

        $html .= '<article class="' . chat_h(implode(' ', $messageClasses)) . '" data-message-id="' . chat_h((string)($message['id'] ?? '')) . '" data-message-own="' . ($isOwn ? '1' : '0') . '" data-message-deleted="' . ($isDeleted ? '1' : '0') . '">'
            . '<div class="chat-message-meta"><strong>' . chat_h($senderLabel) . '</strong><span data-exact-datetime="' . chat_h(date(DATE_ATOM, $createdAt)) . '">' . chat_h($time) . '</span></div>'
            . '<div class="chat-message-quote-source" hidden>' . chat_h($messageSummary) . '</div>'
            . $replyHtml
            . ($body !== '' ? '<div class="chat-message-body">' . $body . '</div>' : '')
            . $attachmentHtml
            . $linkEmbedsHtml
            . ($isHidden ? '' : $reactionHtml)
            . '</article>';
    }

    return $html;
}

chat_refresh_current_user_permissions();
$conversationId = chat_get_conversation_id_from_request();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$canManage = chat_user_can_manage();
$postingRestricted = fridge_current_user_posting_restricted();

if ($action === 'status' && $conversationId !== '') {
    chat_json_response(['exists' => is_file(chat_conversation_path($chatDataDir, $conversationId))]);
}

if ($action === 'active-account-chat' && $conversationId === '') {
    if ($canManage) {
        chat_json_response(['ok' => true, 'chat' => null, 'chats' => []]);
    }
    $accessibleChats = [];
    $username = chat_current_username();
    foreach (chat_load_all_conversations($chatDataDir, $chatKeyPath) as $candidate) {
        $candidateId = (string)($candidate['id'] ?? '');
        if (!chat_is_valid_conversation_id($candidateId)) {
            continue;
        }
        $viewerRole = '';
        if ($username !== '' && (string)($candidate['participantUsername'] ?? '') === $username) {
            $viewerRole = 'participant';
        } else {
            $secret = (string)($_COOKIE[chat_cookie_name($candidateId)] ?? '');
            $participantHash = (string)($candidate['participantHash'] ?? '');
            if ($secret !== '' && $participantHash !== '' && hash_equals($participantHash, hash('sha256', $secret))) {
                $viewerRole = 'participant';
            }
        }
        if ($viewerRole === '') {
            continue;
        }
        $messages = (array)($candidate['messages'] ?? []);
        $lastMessage = end($messages);
        $lastIncomingMessageId = '';
        foreach (array_reverse($messages) as $message) {
            if (is_array($message) && (string)($message['sender'] ?? '') !== $viewerRole) {
                $lastIncomingMessageId = (string)($message['id'] ?? '');
                break;
            }
        }
        $accessibleChats[] = [
            'id' => $candidateId,
            'name' => (string)($candidate['name'] ?? 'private chat'),
            'url' => '/chat/' . $candidateId,
            'viewerRole' => $viewerRole,
            'lastMessageId' => is_array($lastMessage) ? (string)($lastMessage['id'] ?? '') : '',
            'lastMessageSender' => is_array($lastMessage) ? (string)($lastMessage['sender'] ?? '') : '',
            'lastIncomingMessageId' => $lastIncomingMessageId,
        ];
    }
    $activeConversation = $accessibleChats[0] ?? null;
    if ($activeConversation === null) {
        chat_json_response(['ok' => true, 'chat' => null, 'chats' => []]);
    }
    chat_json_response([
        'ok' => true,
        'chat' => $activeConversation,
        'chats' => $accessibleChats,
    ]);
}

if ($action === 'presence' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null) {
        chat_json_response(['ok' => false, 'exists' => false]);
    }

    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    if ($viewerRole === '') {
        http_response_code(403);
        chat_json_response(['ok' => false, 'exists' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $presence = chat_read_presence($chatDataDir, $conversationId);
        $state = (string)($_POST['state'] ?? 'online');
        $isActive = $state === 'online';
        $isTyping = $isActive && (string)($_POST['typing'] ?? '') === '1';
        $presence[$viewerRole] = [
            'lastSeen' => time(),
            'active' => $isActive,
            'typingUntil' => $isTyping ? time() + 5 : 0,
        ];
        chat_write_presence($chatDataDir, $conversationId, $presence);
    } else {
        $presence = chat_read_presence($chatDataDir, $conversationId);
    }

    chat_json_response(chat_presence_payload($presence, $viewerRole) + ['exists' => true]);
}

if ($action === 'messages' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null) {
        chat_json_response(['ok' => false, 'exists' => false]);
    }

    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    if ($viewerRole === '') {
        http_response_code(403);
        chat_json_response(['ok' => false, 'exists' => true]);
    }

    $messages = (array)($conversation['messages'] ?? []);

    chat_json_response([
        'ok' => true,
        'exists' => true,
        'html' => chat_message_html($conversation, $viewerRole),
        'count' => count($messages),
        ...chat_last_message_payload($messages),
        'revision' => chat_messages_revision($messages),
    ]);
}

if ($action === 'react' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null) {
        chat_json_response(['ok' => false, 'exists' => false]);
    }

    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    if ($viewerRole === '') {
        http_response_code(403);
        chat_json_response(['ok' => false, 'exists' => true]);
    }

    $messageId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['messageId'] ?? '')));
    $emoji = chat_normalize_emoji((string)($_POST['emoji'] ?? ''));
    if ($messageId === '' || $emoji === '') {
        http_response_code(400);
        chat_json_response(['ok' => false, 'exists' => true, 'error' => 'invalid reaction.']);
    }

    $messages = (array)($conversation['messages'] ?? []);
    $updated = false;
    foreach ($messages as &$message) {
        if (!is_array($message) || (string)($message['id'] ?? '') !== $messageId) {
            continue;
        }
        if (!empty($message['deletedAt'])) {
            http_response_code(400);
            chat_json_response(['ok' => false, 'exists' => true, 'error' => 'message deleted.']);
        }
        $reactions = is_array($message['reactions'] ?? null) ? $message['reactions'] : [];
        $roles = is_array($reactions[$emoji] ?? null) ? $reactions[$emoji] : [];
        if (!empty($roles[$viewerRole])) {
            unset($roles[$viewerRole]);
        } else {
            $roles[$viewerRole] = true;
        }
        if ($roles === []) {
            unset($reactions[$emoji]);
        } else {
            $reactions[$emoji] = $roles;
        }
        if ($reactions === []) {
            unset($message['reactions']);
        } else {
            $message['reactions'] = $reactions;
        }
        $updated = true;
        break;
    }
    unset($message);

    if (!$updated) {
        http_response_code(404);
        chat_json_response(['ok' => false, 'exists' => true, 'error' => 'message not found.']);
    }

    $conversation['messages'] = $messages;
    chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
    chat_json_response([
        'ok' => true,
        'exists' => true,
        'html' => chat_message_html($conversation, $viewerRole),
        'count' => count($messages),
        ...chat_last_message_payload($messages),
        'revision' => chat_messages_revision($messages),
    ]);
}

if ($action === 'delete-message' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null) {
        chat_json_response(['ok' => false, 'exists' => false]);
    }

    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    if ($viewerRole === '') {
        http_response_code(403);
        chat_json_response(['ok' => false, 'exists' => true]);
    }

    $messageId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['messageId'] ?? '')));
    if ($messageId === '') {
        http_response_code(400);
        chat_json_response(['ok' => false, 'exists' => true, 'error' => 'invalid message.']);
    }

    $messages = (array)($conversation['messages'] ?? []);
    $updated = false;
    foreach ($messages as &$message) {
        if (!is_array($message) || (string)($message['id'] ?? '') !== $messageId) {
            continue;
        }
        if (!$canManage && (string)($message['sender'] ?? '') !== $viewerRole) {
            http_response_code(403);
            chat_json_response(['ok' => false, 'exists' => true, 'error' => 'you can only delete your own messages.']);
        }
        $attachment = is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
        $attachmentId = $attachment !== null ? (string)($attachment['id'] ?? '') : '';
        if (preg_match('/^[a-f0-9]{32}$/', $attachmentId) === 1) {
            @unlink(chat_attachment_path($chatDataDir, $conversationId, $attachmentId));
        }
        $message['body'] = '';
        $message['deletedAt'] = time();
        $message['deletedBy'] = $viewerRole;
        unset($message['attachment'], $message['reactions']);
        $updated = true;
        break;
    }
    unset($message);

    if (!$updated) {
        http_response_code(404);
        chat_json_response(['ok' => false, 'exists' => true, 'error' => 'message not found.']);
    }

    $conversation['messages'] = $messages;
    chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
    chat_json_response([
        'ok' => true,
        'exists' => true,
        'html' => chat_message_html($conversation, $viewerRole),
        'count' => count($messages),
        ...chat_last_message_payload($messages),
        'revision' => chat_messages_revision($messages),
    ]);
}

if ($action === 'hide-message' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null) {
        chat_json_response(['ok' => false, 'exists' => false]);
    }
    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    if ($viewerRole === '') {
        http_response_code(403);
        chat_json_response(['ok' => false, 'exists' => true]);
    }
    $messageId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['messageId'] ?? '')));
    $messages = (array)($conversation['messages'] ?? []);
    $updated = false;
    foreach ($messages as &$message) {
        if (!is_array($message) || (string)($message['id'] ?? '') !== $messageId) continue;
        if (!empty($message['deletedAt']) || (string)($message['sender'] ?? '') === $viewerRole) {
            http_response_code(400);
            chat_json_response(['ok' => false, 'exists' => true, 'error' => 'only the other person\'s message can be hidden.']);
        }
        $hiddenFor = is_array($message['hiddenFor'] ?? null) ? $message['hiddenFor'] : [];
        if (!empty($hiddenFor[$viewerRole])) unset($hiddenFor[$viewerRole]);
        else $hiddenFor[$viewerRole] = true;
        if ($hiddenFor === []) unset($message['hiddenFor']);
        else $message['hiddenFor'] = $hiddenFor;
        $updated = true;
        break;
    }
    unset($message);
    if (!$updated) {
        http_response_code(404);
        chat_json_response(['ok' => false, 'exists' => true, 'error' => 'message not found.']);
    }
    $conversation['messages'] = $messages;
    chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
    chat_json_response([
        'ok' => true,
        'exists' => true,
        'html' => chat_message_html($conversation, $viewerRole),
        'count' => count($messages),
        ...chat_last_message_payload($messages),
        'revision' => chat_messages_revision($messages),
    ]);
}

if ($action === 'attachment' && $conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    if ($conversation === null || !chat_user_can_view_conversation($conversation, $conversationId, $canManage)) {
        chat_render_error('chat access denied', 'that attachment is only available inside this chat.', 403);
    }

    $attachmentId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['file'] ?? '')));
    $attachment = is_string($attachmentId)
        ? chat_load_attachment($chatDataDir, $chatKeyPath, $conversationId, $attachmentId)
        : null;
    if ($attachment === null) {
        chat_render_error('attachment unavailable', 'that file is missing or already deleted.', 404);
    }

    $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', $attachment['mime']) ? $attachment['mime'] : 'application/octet-stream';
    $attachmentName = (string)$attachment['name'];
    $isInlineMedia = str_starts_with($mime, 'image/')
        || chat_attachment_is_audio($mime, $attachmentName)
        || chat_attachment_is_video($mime, $attachmentName);
    $disposition = $isInlineMedia ? 'inline' : 'attachment';
    $attachmentData = (string)$attachment['data'];
    $attachmentLength = strlen($attachmentData);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($attachmentName, "\\\"") . '"');
    header('X-Content-Type-Options: nosniff');
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1 && $attachmentLength > 0) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = max(1, (int)$matches[2]);
            $start = max(0, $attachmentLength - $suffixLength);
            $end = $attachmentLength - 1;
        } else {
            $start = $matches[1] === '' ? 0 : (int)$matches[1];
            $end = $matches[2] === '' ? $attachmentLength - 1 : (int)$matches[2];
        }
        $start = max(0, min($start, $attachmentLength - 1));
        $end = max($start, min($end, $attachmentLength - 1));
        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $attachmentLength);
        header('Content-Length: ' . $length);
        echo substr($attachmentData, $start, $length);
        exit;
    }
    header('Content-Length: ' . $attachmentLength);
    echo $attachmentData;
    exit;
}

if ($conversationId === '' && !$canManage) {
    if (isset($_SESSION['user']['username'])) {
        chat_render_error('chat access denied', 'your account does not have the chat permission.', 403);
    }
    header('Location: /account/login');
    exit;
}

if ($conversationId === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        if ($postingRestricted) {
            header('Location: /chat?error=' . rawurlencode('your account has been restricted.'));
            exit;
        }
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            header('Location: /chat?error=' . rawurlencode('conversation name is required and must be 120 chars or less.'));
            exit;
        }

        $id = chat_generate_conversation_id($chatDataDir);
        $conversation = [
            'id' => $id,
            'name' => $name,
            'createdAt' => time(),
            'createdBy' => (string)($_SESSION['user']['username'] ?? 'unknown'),
            'participantHash' => '',
            'claimedAt' => null,
            'messages' => [],
        ];

        if (!chat_write_conversation($chatDataDir, $chatKeyPath, $conversation)) {
            header('Location: /chat?error=' . rawurlencode('failed to create chat. check data/chat permissions.'));
            exit;
        }

        header('Location: /chat');
        exit;
    }
}

if ($conversationId !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'revoke') {
        if (!chat_user_is_admin()) {
            chat_render_error('chat access denied', 'only administrators can revoke chat ownership.', 403);
        }
        $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
        if ($conversation === null) {
            chat_render_error('this conversation has ended', 'the conversation data has already been deleted from the server.', 410);
        }
        $hadBrowserOwner = !empty($conversation['participantHash']);
        unset(
            $conversation['participantUsername'],
            $conversation['participantHash'],
            $conversation['pendingParticipantHash'],
            $conversation['pendingParticipantAt'],
            $conversation['claimedAt']
        );
        $conversation['participantHash'] = '';
        $conversation['claimedAt'] = null;
        if (!chat_write_conversation($chatDataDir, $chatKeyPath, $conversation)) {
            chat_render_error('chat revoke failed', 'the conversation ownership could not be updated. check data/chat permissions.', 500);
        }
        $presence = chat_read_presence($chatDataDir, $conversationId);
        unset($presence['participant']);
        chat_write_presence($chatDataDir, $conversationId, $presence);
        if ($hadBrowserOwner) {
            chat_set_participant_cookie($conversationId, '', time() - 3600);
        }
        header('Location: /chat/' . rawurlencode($conversationId));
        exit;
    }

    if ($action === 'delete') {
        $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
        if ($conversation === null) {
            chat_render_error('this conversation has ended', 'the conversation data has already been deleted from the server.', 410);
        }
        if (!chat_user_can_delete_conversation($conversation, $canManage)) {
            chat_render_error('chat access denied', 'only chat managers and the linked recipient account can delete this conversation.', 403);
        }

        $participantUsername = trim((string)($conversation['participantUsername'] ?? ''));
        $conversationName = trim((string)($conversation['name'] ?? 'private chat'));
        $deletedConversation = chat_delete_conversation($chatDataDir, $conversationId);
        if ($deletedConversation && $canManage && $participantUsername !== '' && !chat_username_is_admin($participantUsername)) {
            fridge_targeted_notifications_notify_user(
                $participantUsername,
                'Conversation ended',
                'Your conversation with fridge.dev (' . ($conversationName !== '' ? $conversationName : 'private chat') . ') was ended.',
                '/notifications',
                date('Y-m-d H:i:s'),
                'chat-ended-' . $conversationId . '-' . time()
            );
        }
        header('Location: ' . ($canManage ? '/chat?deleted=1' : '/?chat_deleted=1'));
        exit;
    }

    if ($action === 'send') {
        if ($postingRestricted) {
            if (chat_request_wants_json()) {
                http_response_code(403);
                chat_json_response(['ok' => false, 'exists' => true, 'error' => 'your account has been restricted.']);
            }
            header('Location: /chat/' . rawurlencode($conversationId));
            exit;
        }
        $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
        if ($conversation === null) {
            if (chat_request_wants_json()) {
                chat_json_response(['ok' => false, 'exists' => false]);
            }
            chat_render_error('this conversation has ended', 'the conversation data is gone from the server.', 410);
        }

        $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
        if ($viewerRole === '') {
            if (chat_request_wants_json()) {
                http_response_code(403);
                chat_json_response(['ok' => false, 'exists' => true]);
            }
            chat_render_error('chat access denied', 'this link has already been claimed by another browser.', 403);
        }

        $body = trim((string)($_POST['message'] ?? ''));
        $upload = is_array($_FILES['attachment'] ?? null) ? $_FILES['attachment'] : null;
        $attachmentKind = preg_replace('/[^a-z]/', '', strtolower((string)($_POST['attachmentKind'] ?? '')));
        $attachment = $upload !== null
            ? chat_encrypt_attachment($chatDataDir, $chatKeyPath, $conversationId, $upload, $attachmentKind === 'voice' ? 'voice' : '')
            : null;
        $uploadError = $upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $attachment === null;

        if ($uploadError && chat_request_wants_json()) {
            http_response_code(400);
            $errorText = $attachmentKind === 'voice'
                ? 'voice note failed. keep it under 2 minutes and try again.'
                : 'attachment failed. max size is 8 MB.';
            chat_json_response(['ok' => false, 'exists' => true, 'error' => $errorText]);
        }

        if (($body !== '' || $attachment !== null) && strlen($body) <= 4000) {
            $messages = (array)($conversation['messages'] ?? []);
            $replyTo = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['replyTo'] ?? '')));
            $validReplyTo = '';
            if ($replyTo !== '') {
                foreach ($messages as $existingMessage) {
                    if (is_array($existingMessage) && (string)($existingMessage['id'] ?? '') === $replyTo) {
                        $validReplyTo = $replyTo;
                        break;
                    }
                }
            }
            $message = [
                'id' => bin2hex(random_bytes(8)),
                'sender' => $viewerRole,
                'body' => $body,
                'createdAt' => time(),
            ];
            if ($validReplyTo !== '') {
                $message['replyTo'] = $validReplyTo;
            }
            if ($attachment !== null) {
                $message['attachment'] = $attachment;
            }
            $linkMetadata = chat_collect_link_metadata($body);
            if ($linkMetadata !== []) {
                $message['linkMetadata'] = $linkMetadata;
            }
            $message['linkMetadataChecked'] = true;
            $message['linkMetadataVersion'] = 2;
            $messages[] = $message;
            $conversation['messages'] = $messages;
            $conversationSaved = chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
            if ($conversationSaved && $viewerRole === 'participant') {
                $conversationName = trim((string)($conversation['name'] ?? 'recipient'));
                if ($conversationName === '') $conversationName = 'recipient';
                fridge_targeted_notifications_replace_admin_group(
                    'chat-message',
                    'New chat message',
                    'There is a new reply to the conversation with ' . $conversationName . '.',
                    '/chat/' . $conversationId,
                    date('Y-m-d H:i:s'),
                    'chat-' . $conversationId . '-' . (string)$message['id']
                );
            } elseif ($conversationSaved && $viewerRole === 'manager') {
                $participantUsername = trim((string)($conversation['participantUsername'] ?? ''));
                if ($participantUsername !== '' && !chat_username_is_admin($participantUsername)) {
                    fridge_targeted_notifications_replace_user_group(
                        $participantUsername,
                        'chat-message',
                        'New chat message',
                        'There is a new message in your conversation with fridge.dev.',
                        '/chat/' . $conversationId,
                        date('Y-m-d H:i:s'),
                        'chat-recipient-' . $conversationId . '-' . (string)$message['id']
                    );
                }
            }
        }

        if (chat_request_wants_json()) {
            $messages = (array)($conversation['messages'] ?? []);
            chat_json_response([
                'ok' => true,
                'exists' => true,
                'html' => chat_message_html($conversation, $viewerRole),
                'count' => count($messages),
                ...chat_last_message_payload($messages),
                'revision' => chat_messages_revision($messages),
            ]);
        }

        header('Location: /chat/' . rawurlencode($conversationId));
        exit;
    }
}

if ($conversationId !== '') {
    $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
    $cookieName = chat_cookie_name($conversationId);
    $cookieSecret = (string)($_COOKIE[$cookieName] ?? '');

    if ($conversation === null) {
        if ($cookieSecret !== '') {
            chat_render_error('this conversation has ended', 'the conversation data has been deleted from the server.', 410);
        }
        chat_render_error('chat unavailable', 'that chat is missing, ended, or never existed.', 404);
    }

    if (!$canManage) {
        $currentUsername = chat_current_username();
        $participantUsername = (string)($conversation['participantUsername'] ?? '');
        $participantHash = (string)($conversation['participantHash'] ?? '');
        if ($participantUsername !== '') {
            if ($currentUsername === '' || !hash_equals($participantUsername, $currentUsername)) {
                chat_render_error('chat access denied', 'this invite is linked to another account.', 403);
            }
        } elseif ($participantHash === '') {
            if ($currentUsername !== '') {
                $conversation['participantUsername'] = $currentUsername;
                $conversation['claimedAt'] = time();
                unset($conversation['pendingParticipantHash'], $conversation['pendingParticipantAt']);
                chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
            } else {
            $pendingHash = (string)($conversation['pendingParticipantHash'] ?? '');
            $pendingAt = (int)($conversation['pendingParticipantAt'] ?? 0);
            $cookieHash = $cookieSecret === '' ? '' : hash('sha256', $cookieSecret);

            if ($pendingHash !== '' && $cookieHash !== '' && hash_equals($pendingHash, $cookieHash)) {
                $conversation['participantHash'] = $pendingHash;
                $conversation['claimedAt'] = time();
                unset($conversation['pendingParticipantHash'], $conversation['pendingParticipantAt']);
                chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
            } else {
                $secret = bin2hex(random_bytes(32));
                $conversation['pendingParticipantHash'] = hash('sha256', $secret);
                $conversation['pendingParticipantAt'] = time();
                chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
                chat_set_participant_cookie($conversationId, $secret);

                $authUrl = '/chat/' . rawurlencode($conversationId);
                chat_render_page('chat invite', "you've been invited to a private, secure chat on fridge.dev.", '<h1>joining chat...</h1><h2>locking this chat to your browser.</h2><br><script>setTimeout(function(){ window.location.href = ' . json_encode($authUrl) . '; }, 900);</script><p><a href="' . chat_h($authUrl) . '">continue</a></p>');
                exit;
            }
            }
        } else {
            $cookieHash = $cookieSecret === '' ? '' : hash('sha256', $cookieSecret);
            if ($cookieHash === '' || !hash_equals($participantHash, $cookieHash)) {
                chat_render_error('chat access denied', 'this invite has already been used.', 403);
            }
        }
    }

    if (chat_backfill_link_metadata($conversation)) {
        chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
    }

    $viewerRole = chat_get_viewer_role($conversation, $conversationId, $canManage);
    $showRecipientIntro = !$canManage && $viewerRole === 'participant' && empty($conversation['recipientIntroSeenAt']);
    if ($showRecipientIntro) {
        $conversation['recipientIntroSeenAt'] = time();
        chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
    }
    $recipientName = trim((string)($conversation['name'] ?? 'recipient'));
    if ($recipientName === '') {
        $recipientName = 'recipient';
    }
    $chatScript = '<script src="/js/chat-conversation.js?v=20260915-namespace-1"></script>';
    $canDeleteConversation = chat_user_can_delete_conversation($conversation, $canManage);
    $canRevokeConversation = chat_user_is_admin()
        && (!empty($conversation['participantHash']) || !empty($conversation['participantUsername']));
    $isAccountLinkedRecipient = !$canManage && chat_is_account_participant($conversation);
    $content = '<style id="chat-final-theme-overrides">body .chat-view{--chat-own-bg:#245856;--chat-own-fg:#fff}body .chat-view .chat-message-own{background:#245856!important;background-image:none!important;color:#fff!important}body .chat-view .chat-message-own .chat-message-meta,body .chat-view .chat-message-own .chat-message-body,body .chat-view .chat-message-own .chat-attachment{color:#fff!important}body .chat-view .chat-reply-reference,body .chat-view .chat-reply-compose{background:#252b30!important;background-image:none!important;color:#fff!important}body .chat-view .chat-reply-reference strong,body .chat-view .chat-reply-reference span,body .chat-view .chat-reply-compose strong,body .chat-view .chat-reply-compose span{color:#fff!important}</style>'
        . '<section class="chat-view" data-chat-id="' . chat_h($conversationId) . '" data-can-manage="' . ($canManage ? '1' : '0') . '" data-posting-restricted="' . ($postingRestricted ? '1' : '0') . '" data-viewer-role="' . chat_h($viewerRole) . '" data-recipient-name="' . chat_h($recipientName) . '" data-show-recipient-intro="' . ($showRecipientIntro ? '1' : '0') . '" data-account-linked-recipient="' . ($isAccountLinkedRecipient ? '1' : '0') . '">'
        . '<div class="chat-header-row"><div class="chat-header-main"><h2>fridge.dev chat</h2><div class="chat-presence" id="chat-presence" aria-live="polite">checking if the other user is online...</div></div>'
        . '<div class="chat-header-actions">'
        . ($canRevokeConversation ? '<form class="chat-revoke-form" method="post" action="/chat/' . chat_h($conversationId) . '" data-no-spa="1" data-site-confirm data-confirm-title="revoke chat?" data-confirm-detail="this releases the current recipient and lets someone else claim the chat link. existing messages are kept." data-confirm-text="revoke"><input type="hidden" name="action" value="revoke"><button class="chat-delete-button" type="submit">revoke chat</button></form>' : '')
        . ($canDeleteConversation ? '<form class="chat-delete-form" method="post" action="/chat/' . chat_h($conversationId) . '" data-no-spa="1" data-confirm-text="end chat"><input type="hidden" name="action" value="delete"><button class="danger-button chat-delete-button" type="submit">end chat</button></form>' : '')
        . '</div>'
        . '</div>'
        . '<div class="chat-messages-wrap"><div class="chat-messages" id="chat-messages" aria-live="polite">' . chat_message_html($conversation, $viewerRole) . '</div><div class="chat-typing-indicator" id="chat-typing-indicator" aria-live="polite"></div></div>'
        . '<form class="chat-send-form" method="post" action="/chat/' . chat_h($conversationId) . '" enctype="multipart/form-data" data-no-spa="1">'
        . '<input type="hidden" name="action" value="send">'
        . '<input type="hidden" name="replyTo" value="">'
        . '<div class="chat-reply-compose" aria-live="polite"><div><strong></strong><span></span></div><button type="button" aria-label="cancel reply">x</button></div>'
        . '<input type="hidden" name="attachmentKind" value="">'
        . '<input class="chat-attachment-input" name="attachment" type="file" accept="image/*,audio/*,video/*,.pdf,.txt,.md,.zip,.7z,.rar,.mp3,.wav,.ogg,.oga,.flac,.mp4,.webm,.ogv,.mov,.m4v,.mkv,.avi,.json,.csv" hidden>'
        . '<button class="chat-attach-button" type="button" data-tooltip="add file or voice note" aria-label="add file or voice note"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>'
        . '<div class="chat-attach-menu"><button type="button" data-chat-compose-action="upload"><i class="fa-solid fa-paperclip"></i><span>upload file</span></button><button type="button" data-chat-compose-action="voice"><i class="fa-solid fa-microphone"></i><span>record voice note</span></button></div>'
        . '<div class="chat-voice-recorder" hidden></div>'
        . '<textarea name="message" rows="2" maxlength="4000" placeholder="message"></textarea>'
        . '<button class="chat-emoji-button" type="button" data-tooltip="emoji">☺</button>'
        . '<button class="chat-send-button chat-hold-voice-button" type="button" data-tooltip="record a voice note" aria-label="record a voice note" aria-expanded="false"><i class="fa-solid fa-microphone" aria-hidden="true"></i></button>'
        . '<button class="chat-send-button" type="submit"><span>send</span><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>'
        . '<div class="chat-file-indicator" aria-live="polite"></div>'
        . '</form>'
        . '<div class="chat-context-menu" role="menu"><button type="button" data-chat-action="reply">reply</button><button type="button" data-chat-action="react">react</button><button type="button" data-chat-action="delete">delete</button></div>'
        . '<div class="chat-emoji-picker"><input class="chat-emoji-search" type="search" placeholder="search emoji" autocomplete="off"><div class="chat-emoji-grid"></div></div>'
        . ($canManage ? '<p><a href="/chat">back to chat dashboard</a></p>' : '')
        . $chatScript
        . '</section>';

    if ($postingRestricted) {
        $content = (string)preg_replace_callback(
            '/<form class="chat-send-form".*?<\/form>/s',
            static fn (array $matches): string => fridge_posting_restriction_notice() . fridge_disable_composer_controls($matches[0]),
            $content,
            1
        );
    }

    chat_render_page('private chat', $description, $content);
    exit;
}

$createdId = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['created'] ?? '')));
if (!is_string($createdId) || !chat_is_valid_conversation_id($createdId)) {
    $createdId = '';
}
$error = trim((string)($_GET['error'] ?? ''));
$deleted = isset($_GET['deleted']);
$conversations = chat_load_all_conversations($chatDataDir, $chatKeyPath);
$cards = [];

foreach ($conversations as $conversation) {
    $id = (string)($conversation['id'] ?? '');
    if (!chat_is_valid_conversation_id($id)) {
        continue;
    }

    $sharePath = '/chat/' . $id;
    $shareUrl = 'https://fridge.dev' . $sharePath;
    $claimed = !empty($conversation['participantHash']) || !empty($conversation['participantUsername']);
    $messageCount = count((array)($conversation['messages'] ?? []));
    $cards[] = '<article class="chat-admin-card">'
        . '<div><strong>' . chat_h((string)($conversation['name'] ?? 'private chat')) . '</strong>'
        . '<button class="chat-copy-link" type="button" data-copy-url="' . chat_h($shareUrl) . '" data-tooltip="copy chat link">' . chat_h($id) . ' (click to copy)</button>'
        . '<span>' . chat_h($claimed ? 'claimed' : 'unclaimed') . ' · created ' . chat_h(date('y-m-d', (int)($conversation['createdAt'] ?? time()))) . '</span></div>'
        . '<div class="chat-card-actions"><a id="two-buttons" href="' . chat_h($sharePath) . '">open</a>'
        . '<form class="chat-delete-form" method="post" action="' . chat_h($sharePath) . '" data-no-spa="1" data-confirm-text="delete"><input type="hidden" name="action" value="delete"><button class="danger-button" type="submit">delete</button></form></div>'
        . '</article>';
}

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
$content = (string)file_get_contents($contentPath);
$createdNotice = '';
if ($deleted) {
    $createdNotice = '<div id="result">conversation ended and deleted.</div><br>';
}
if ($error !== '') {
    $createdNotice = '<div id="error">' . chat_h($error) . '</div><br>';
}

$content = str_replace(
    ['{notice}', '{chat_count}', '{chat_cards}'],
    [
        $createdNotice,
        (string)count($conversations),
        $cards === [] ? '<p>no active conversations. very quiet. suspiciously peaceful.</p>' : implode('', $cards),
    ],
    $content
);
if ($postingRestricted) {
    $content = (string)preg_replace_callback(
        '/<form id="chat-create-form".*?<\/form>/s',
        static fn (array $matches): string => fridge_posting_restriction_notice() . fridge_disable_composer_controls($matches[0]),
        $content,
        1
    );
}

chat_render_page($title, $description, $content);
