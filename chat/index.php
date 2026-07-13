<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

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

function chat_set_participant_cookie(string $id, string $secret): void {
    setcookie(chat_cookie_name($id), $secret, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/chat',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[chat_cookie_name($id)] = $secret;
}

function chat_render_page(string $title, string $description, string $content): void {
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
        die('page template not found. report this issue to me@fridge.dev.');
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
        $senderLabel = chat_message_label($message, $viewerRole, $recipientName);
        $time = date('H:i', $createdAt);
        $body = $isDeleted ? 'message deleted' : nl2br(chat_h((string)($message['body'] ?? '')), false);
        $messageSummary = chat_message_summary($message);
        $replyHtml = '';
        $replyTo = (string)($message['replyTo'] ?? '');
        if ($replyTo !== '' && isset($messagesById[$replyTo])) {
            $replyMessage = $messagesById[$replyTo];
            $replyHtml = '<button class="chat-reply-reference" type="button" data-scroll-message="' . chat_h($replyTo) . '">'
                . '<strong>' . chat_h(chat_message_label($replyMessage, $viewerRole, $recipientName)) . '</strong>'
                . '<span>' . chat_h(chat_message_summary($replyMessage)) . '</span>'
                . '</button>';
        }
        $attachmentHtml = '';
        $hasImageAttachment = false;
        $hasMediaAttachment = false;
        $attachment = !$isDeleted && is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
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

        $html .= '<article class="' . chat_h(implode(' ', $messageClasses)) . '" data-message-id="' . chat_h((string)($message['id'] ?? '')) . '" data-message-own="' . ($isOwn ? '1' : '0') . '" data-message-deleted="' . ($isDeleted ? '1' : '0') . '">'
            . '<div class="chat-message-meta"><strong>' . chat_h($senderLabel) . '</strong><span>' . chat_h($time) . '</span></div>'
            . '<div class="chat-message-quote-source" hidden>' . chat_h($messageSummary) . '</div>'
            . $replyHtml
            . ($body !== '' ? '<div class="chat-message-body">' . $body . '</div>' : '')
            . $attachmentHtml
            . $reactionHtml
            . '</article>';
    }

    return $html;
}

chat_refresh_current_user_permissions();
$conversationId = chat_get_conversation_id_from_request();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$canManage = chat_user_can_manage();
$postingRestricted = fridg3_current_user_posting_restricted();

if ($action === 'status' && $conversationId !== '') {
    chat_json_response(['exists' => is_file(chat_conversation_path($chatDataDir, $conversationId))]);
}

if ($action === 'active-account-chat' && $conversationId === '') {
    $activeConversation = chat_find_account_conversation($chatDataDir, $chatKeyPath, chat_current_username());
    if ($activeConversation === null) {
        chat_json_response(['ok' => true, 'chat' => null]);
    }

    $activeId = (string)($activeConversation['id'] ?? '');
    chat_json_response([
        'ok' => true,
        'chat' => chat_is_valid_conversation_id($activeId) ? [
            'id' => $activeId,
            'name' => (string)($activeConversation['name'] ?? 'private chat'),
            'url' => '/chat/' . $activeId,
        ] : null,
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

        header('Location: /chat?created=' . rawurlencode($id));
        exit;
    }
}

if ($conversationId !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        $conversation = chat_read_conversation($chatDataDir, $chatKeyPath, $conversationId);
        if ($conversation === null) {
            chat_render_error('this conversation has ended', 'the conversation data has already been deleted from the server.', 410);
        }
        if (!chat_user_can_delete_conversation($conversation, $canManage)) {
            chat_render_error('chat access denied', 'only chat managers and the linked recipient account can delete this conversation.', 403);
        }

        chat_delete_conversation($chatDataDir, $conversationId);
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
            $messages[] = $message;
            $conversation['messages'] = $messages;
            chat_write_conversation($chatDataDir, $chatKeyPath, $conversation);
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
    $chatScript = <<<'HTML'
<script>
(function(){
function initChat(){
    var root=document.querySelector(".chat-view");
    if(!root||root.dataset.chatBound==="1")return;
    root.dataset.chatBound="1";
    var id=root.getAttribute("data-chat-id");
    var canManage=root.getAttribute("data-can-manage")==="1";
    var postingRestricted=root.getAttribute("data-posting-restricted")==="1";
    var presenceEl=document.getElementById("chat-presence");
    var typingEl=document.getElementById("chat-typing-indicator");
    var messagesEl=document.getElementById("chat-messages");
    var form=document.querySelector(".chat-send-form");
    var textarea=form?form.querySelector("[name='message']"):null;
    var replyInput=form?form.querySelector("[name='replyTo']"):null;
    var fileInput=form?form.querySelector("[name='attachment']"):null;
    var attachmentKindInput=form?form.querySelector("[name='attachmentKind']"):null;
    var fileIndicator=form?form.querySelector(".chat-file-indicator"):null;
    var sendButton=form?form.querySelector(".chat-send-button"):null;
    var replyPreview=form?form.querySelector(".chat-reply-compose"):null;
    var replyName=replyPreview?replyPreview.querySelector("strong"):null;
    var replyText=replyPreview?replyPreview.querySelector("span"):null;
    var replyCancel=replyPreview?replyPreview.querySelector("button"):null;
    var emojiButton=form?form.querySelector(".chat-emoji-button"):null;
    var attachButton=form?form.querySelector(".chat-attach-button"):null;
    var attachMenu=form?form.querySelector(".chat-attach-menu"):null;
    var voiceRecorderEl=form?form.querySelector(".chat-voice-recorder"):null;
    var menu=document.querySelector(".chat-context-menu");
    var emojiPicker=document.querySelector(".chat-emoji-picker");
    var emojiSearch=emojiPicker?emojiPicker.querySelector(".chat-emoji-search"):null;
    var emojiGrid=emojiPicker?emojiPicker.querySelector(".chat-emoji-grid"):null;
    var pickerMode="insert";
    var pickerMessageId="";
    var viewerRole=root.getAttribute("data-viewer-role")||"";
    var lastMessageId="";
    var lastMessageCount=0;
    var lastRevision="";
    var alertAudio=null;
    var unreadCount=0;
    var originalTitle=document.title;
    var currentlyTyping=false;
    var lastTypingSentAt=0;
    var typingIdleTimer=null;
    var EMOJI_DATA_URL="https://cdn.jsdelivr.net/npm/emojibase-data@16.0.3/en/data.json";
    var quickEmojiOrder=["👍","👎","❤️","😮","😆","🔥","💩"];
    var fallbackEmojiItems=[
        {emoji:"👍",label:"thumbs up",tags:["yes","approve"]},
        {emoji:"👎",label:"thumbs down",tags:["no","disapprove"]},
        {emoji:"❤️",label:"red heart",tags:["love"]},
        {emoji:"😮",label:"face with open mouth",tags:["wow","surprised"]},
        {emoji:"😆",label:"grinning squinting face",tags:["laugh"]},
        {emoji:"🔥",label:"fire",tags:["hot"]},
        {emoji:"💩",label:"pile of poo",tags:["poop"]},
        {emoji:"😀",label:"grinning face",tags:["smile","happy"]},
        {emoji:"😂",label:"face with tears of joy",tags:["laugh","funny"]},
        {emoji:"😭",label:"loudly crying face",tags:["cry","sad"]},
        {emoji:"✨",label:"sparkles",tags:["shine"]},
        {emoji:"🎉",label:"party popper",tags:["party","celebrate"]},
        {emoji:"💀",label:"skull",tags:["dead"]},
        {emoji:"🚀",label:"rocket",tags:["launch"]}
    ];
    var emojiItems=fallbackEmojiItems.slice();
    var emojiFilteredItems=[];
    var emojiRenderedCount=0;
    var emojiBatchSize=96;
    function label(role){return role==="manager"?"fridge":(root.getAttribute("data-recipient-name")||"recipient");}
    function scrollMessages(force){if(!messagesEl)return;var nearBottom=messagesEl.scrollHeight-messagesEl.scrollTop-messagesEl.clientHeight<110;if(force||nearBottom){messagesEl.scrollTop=messagesEl.scrollHeight;}}
    function isChatActive(){return (!document.visibilityState||document.visibilityState==="visible")&&document.hasFocus();}
    function updateUnreadTitle(){document.title=unreadCount>0?"("+unreadCount+") "+originalTitle:originalTitle;}
    function clearUnread(){if(unreadCount<1)return;unreadCount=0;updateUnreadTitle();}
    function playMessageAlert(){if(isChatActive())return;if(!alertAudio){alertAudio=new Audio("/chat/alert.ogg");alertAudio.preload="auto";}try{alertAudio.currentTime=0;var playPromise=alertAudio.play();if(playPromise&&typeof playPromise.catch==="function"){playPromise.catch(function(){});}}catch(error){}}
    function trackIncomingMessages(data,force){if(force||!data||!data.lastMessageId||!lastMessageId)return;if(data.lastMessageId!==lastMessageId&&data.lastMessageSender&&data.lastMessageSender!==viewerRole){if(!isChatActive()){unreadCount+=Math.max(1,Number(data.count||0)-lastMessageCount);updateUnreadTitle();}playMessageAlert();}}
    function renderPresence(data){if(!data||!data.ok)return;var status=data.otherStatus||(data.otherOnline?"online":(data.otherAway?"away":"offline"));if(presenceEl){presenceEl.className="chat-presence chat-presence-"+status;presenceEl.textContent=label(data.otherRole)+" is "+status;}if(typingEl){typingEl.textContent=data.otherTyping?(label(data.otherRole)+" is typing..."):"";typingEl.style.display=data.otherTyping?"block":"none";}}
    function formatMediaTime(seconds){seconds=Number(seconds||0);if(!isFinite(seconds)||seconds<0)seconds=0;var mins=Math.floor(seconds/60);var secs=Math.floor(seconds%60);return mins+":"+(secs<10?"0":"")+secs;}
    function initChatMediaPlayers(){
        if(!messagesEl)return;
        messagesEl.querySelectorAll(".chat-attachment-media").forEach(function(wrap){
            if(wrap.dataset.mediaBound==="1")return;
            var media=wrap.querySelector(".chat-media-element");
            var controls=wrap.querySelector(".chat-media-player");
            if(!media||!controls)return;
            wrap.dataset.mediaBound="1";
            var play=controls.querySelector(".chat-media-play");
            var playIcon=play?play.querySelector("i"):null;
            var seek=controls.querySelector(".chat-media-seek");
            var time=controls.querySelector(".chat-media-time");
            var mute=controls.querySelector(".chat-media-mute");
            var muteIcon=mute?mute.querySelector("i"):null;
            var volume=controls.querySelector(".chat-media-volume");
            var speed=controls.querySelector(".chat-media-speed");
            var speedLabel=speed?speed.querySelector(".chat-media-speed-label"):null;
            var playbackSpeeds=[1,1.5,2];
            function updatePlay(){if(!playIcon)return;playIcon.classList.toggle("fa-play",media.paused);playIcon.classList.toggle("fa-pause",!media.paused);}
            function updateTime(){var duration=isFinite(media.duration)?media.duration:0;if(seek&&!seek.matches(":active")){seek.value=duration>0?String(Math.round((media.currentTime/duration)*1000)):"0";}if(time){time.textContent=formatMediaTime(media.currentTime)+" / "+formatMediaTime(duration);}}
            function updateMute(){if(!muteIcon)return;var muted=media.muted||media.volume===0;muteIcon.classList.toggle("fa-volume-high",!muted);muteIcon.classList.toggle("fa-volume-xmark",muted);if(volume)volume.value=String(media.muted?0:media.volume);}
            function updateSpeed(){if(!speedLabel)return;var rate=playbackSpeeds.indexOf(media.playbackRate)!==-1?media.playbackRate:1;speedLabel.textContent=rate+"x";if(speed)speed.setAttribute("aria-label","playback speed "+rate+"x");}
            if(play){play.addEventListener("click",function(){if(media.paused){messagesEl.querySelectorAll(".chat-media-element").forEach(function(other){if(other!==media)other.pause();});media.play().catch(function(){});}else{media.pause();}});}
            if(seek){seek.addEventListener("input",function(){if(!isFinite(media.duration)||media.duration<=0)return;media.currentTime=(Number(seek.value||0)/1000)*media.duration;updateTime();});}
            if(mute){mute.addEventListener("click",function(){media.muted=!media.muted;updateMute();});}
            if(volume){volume.addEventListener("input",function(){var value=Math.max(0,Math.min(1,Number(volume.value||0)));media.volume=value;media.muted=value===0;updateMute();});}
            if(speed){speed.addEventListener("click",function(){var currentIndex=playbackSpeeds.indexOf(media.playbackRate);var nextIndex=currentIndex===-1?0:(currentIndex+1)%playbackSpeeds.length;media.playbackRate=playbackSpeeds[nextIndex];updateSpeed();});}
            media.addEventListener("loadedmetadata",updateTime);
            media.addEventListener("timeupdate",updateTime);
            media.addEventListener("play",updatePlay);
            media.addEventListener("pause",updatePlay);
            media.addEventListener("ended",function(){updatePlay();updateTime();});
            media.addEventListener("volumechange",updateMute);
            media.addEventListener("ratechange",updateSpeed);
            updatePlay();
            updateTime();
            updateMute();
            updateSpeed();
        });
    }
    function renderMessages(data,force){if(!messagesEl||!data||!data.ok)return;var revision=data.revision||"";if((revision&&revision!==lastRevision)||data.lastMessageId!==lastMessageId||messagesEl.innerHTML===""){trackIncomingMessages(data,force);messagesEl.innerHTML=data.html;initChatMediaPlayers();lastMessageId=data.lastMessageId||"";lastMessageCount=Number(data.count||0);lastRevision=revision;scrollMessages(force);if(isChatActive())clearUnread();}}
    function showRecipientIntro(){if(root.getAttribute("data-show-recipient-intro")!=="1"||typeof window.showSitePopup!=="function")return;root.setAttribute("data-show-recipient-intro","0");var accountLinked=root.getAttribute("data-account-linked-recipient")==="1";window.showSitePopup({title:"private chat secured",html:accountLinked?"<p>this invite is linked to your fridge.dev account, so you can reopen it while logged in without relying on a browser cookie.</p><p>messages and attachments are stored in an encrypted chat file, and ending the chat deletes that file from the server.</p><p>click or tap any message to reply to it or react with an emoji.</p>":"<p>this invite is locked to this browser after you open it. other browsers that try the same link get denied.</p><p>messages and attachments are stored in an encrypted chat file, and ending the chat deletes that file from the server.</p><p>click or tap any message to reply to it or react with an emoji.</p>",okText:"got it"});}
    function syncFileIndicator(){if(!fileIndicator||!fileInput)return;var file=fileInput.files&&fileInput.files[0]?fileInput.files[0]:null;var isVoice=attachmentKindInput&&attachmentKindInput.value==="voice";fileIndicator.textContent=file?((isVoice?"voice note: ":"attached: ")+file.name):"";fileIndicator.style.display=file?"block":"none";}
    function jsonFetch(url,options){return fetch(url,options).then(function(response){return response.json().then(function(data){if(!response.ok){data.ok=false;}return data;});});}
    function presenceBody(typingOverride){var body=new URLSearchParams();var active=isChatActive();var typing=typeof typingOverride==="boolean"?typingOverride:currentlyTyping;body.append("state",active?"online":"away");body.append("typing",(active&&typing)?"1":"0");return body;}
    function ping(){jsonFetch("/chat/"+id+"?action=presence",{method:"POST",body:presenceBody(),cache:"no-store",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}renderPresence(data);}).catch(function(){});}
    function refreshPresence(){jsonFetch("/chat/"+id+"?action=presence",{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}renderPresence(data);}).catch(function(){});}
    function pingAway(){var body=new URLSearchParams();body.append("state","away");if(navigator.sendBeacon){navigator.sendBeacon("/chat/"+id+"?action=presence",body);return;}fetch("/chat/"+id+"?action=presence",{method:"POST",body:body,credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).catch(function(){});}
    function refreshMessages(force){jsonFetch("/chat/"+id+"?action=messages",{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}renderMessages(data,force);}).catch(function(){});}
    function hasFile(){return fileInput&&fileInput.files&&fileInput.files.length>0;}
    function messageSummary(message){var source=message.querySelector(".chat-message-quote-source");var body=source?source.textContent.trim():"";if(!body){body="message";}return body;}
    function messageAuthor(message){var author=message.querySelector(".chat-message-meta strong");return author?author.textContent.trim():"message";}
    function setReply(message){if(!message||!replyInput||!replyPreview)return;replyInput.value=message.getAttribute("data-message-id")||"";if(replyName)replyName.textContent=messageAuthor(message);if(replyText)replyText.textContent=messageSummary(message);replyPreview.style.display="grid";textarea&&textarea.focus();}
    function clearReply(){if(replyInput)replyInput.value="";if(replyPreview)replyPreview.style.display="none";}
    function sendTypingState(active,force){currentlyTyping=!!(active&&isChatActive());var now=Date.now();if(!force&&currentlyTyping&&now-lastTypingSentAt<1400)return;lastTypingSentAt=now;jsonFetch("/chat/"+id+"?action=presence",{method:"POST",body:presenceBody(currentlyTyping),cache:"no-store",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}renderPresence(data);}).catch(function(){});}
    function queueTyping(){if(!textarea)return;var active=textarea.value.trim()!=="";sendTypingState(active,false);if(typingIdleTimer)clearTimeout(typingIdleTimer);typingIdleTimer=setTimeout(function(){sendTypingState(false,true);},2800);}
    function closeMenu(){if(menu)menu.style.display="none";}
    function closeAttachMenu(){if(attachMenu)attachMenu.style.display="none";}
    function closePicker(){if(emojiPicker)emojiPicker.style.display="none";pickerMessageId="";}
    function isMobileChat(){return !!(document.body&&document.body.classList&&document.body.classList.contains("mobile-template"))||window.matchMedia("(max-width: 720px)").matches;}
    function placeBox(box,x,y){if(!box||!root)return;box.style.display="block";var rootRect=root.getBoundingClientRect();var rect=box.getBoundingClientRect();var localX=x-rootRect.left;var localY=y-rootRect.top;var maxLeft=Math.max(8,root.clientWidth-rect.width-8);var maxTop=Math.max(8,root.clientHeight-rect.height-8);box.style.left=Math.max(8,Math.min(localX,maxLeft))+"px";box.style.top=Math.max(8,Math.min(localY,maxTop))+"px";}
    function openMenu(message,x,y){if(!menu||!message)return;menu.dataset.messageId=message.getAttribute("data-message-id")||"";var deleteButton=menu.querySelector('[data-chat-action="delete"]');if(deleteButton){deleteButton.style.display=(canManage||message.getAttribute("data-message-own")==="1")?"block":"none";}placeBox(menu,x,y);}
    function emojiFromHexcode(hexcode){return String(hexcode||"").split("-").map(function(part){var code=parseInt(part,16);return code?String.fromCodePoint(code):"";}).join("");}
    function emojiTagList(value){if(Array.isArray(value))return value.map(String);if(typeof value==="string"&&value)return [value];if(value&&typeof value==="object"){return Object.keys(value).reduce(function(tags,key){var next=value[key];return tags.concat(Array.isArray(next)?next.map(String):[String(next)]);},[]);}return [];}
    function normalizeEmojiItem(item){var emoji=item&&typeof item.emoji==="string"&&item.emoji?item.emoji:emojiFromHexcode(item&&item.hexcode);var label=String(item&&item.label||"emoji");if(!emoji)return null;var tags=emojiTagList(item&&item.tags).concat(emojiTagList(item&&item.shortcodes));return {emoji:emoji,label:label,tags:tags,group:Number(item&&item.group||0),order:Number(item&&item.order||0)};}
    function loadEmojiData(){if(!window.fetch)return;fetch(EMOJI_DATA_URL,{cache:"force-cache"}).then(function(response){if(!response.ok)throw new Error("emoji data failed");return response.json();}).then(function(data){if(!Array.isArray(data))return;var loaded=[];data.forEach(function(item){var normalized=normalizeEmojiItem(item);if(normalized)loaded.push(normalized);if(item&&Array.isArray(item.skins)){item.skins.forEach(function(skin){var skinItem=Object.assign({},item,skin,{label:skin.label||item.label});var normalizedSkin=normalizeEmojiItem(skinItem);if(normalizedSkin)loaded.push(normalizedSkin);});}});if(loaded.length){emojiItems=loaded;if(emojiPicker&&emojiPicker.style.display==="block"){renderEmojiList(emojiSearch?emojiSearch.value:"");}}}).catch(function(){});}
    function firstGrapheme(value){value=String(value||"").trim();if(!value)return "";if(window.Intl&&Intl.Segmenter){var segments=new Intl.Segmenter(undefined,{granularity:"grapheme"}).segment(value);var first=segments[Symbol.iterator]().next();return first.done?"":first.value.segment;}return Array.from(value)[0]||"";}
    function looksEmoji(value){return /[\u203C-\u3299]|\uD83C[\uD000-\uDFFF]|\uD83D[\uD000-\uDFFF]|\uD83E[\uD000-\uDFFF]/u.test(value);}
    function emojiQueryCandidate(query){var emoji=firstGrapheme(query);return emoji&&looksEmoji(emoji)?{emoji:emoji,label:"typed emoji",tags:["custom"],group:-1,order:-1}:null;}
    function appendEmojiChunk(){if(!emojiGrid)return;var end=Math.min(emojiRenderedCount+emojiBatchSize,emojiFilteredItems.length);var fragment=document.createDocumentFragment();for(var i=emojiRenderedCount;i<end;i++){var item=emojiFilteredItems[i];var btn=document.createElement("button");btn.type="button";btn.textContent=item.emoji;btn.title=item.label;btn.setAttribute("data-emoji",item.emoji);fragment.appendChild(btn);}emojiGrid.appendChild(fragment);emojiRenderedCount=end;}
    function renderEmojiList(query){if(!emojiGrid)return;var q=(query||"").trim().toLowerCase();emojiGrid.innerHTML="";emojiGrid.scrollTop=0;emojiRenderedCount=0;var used={};var combined=[];var typed=emojiQueryCandidate(query);if(typed)combined.push(typed);var quick=[];if(!q){quickEmojiOrder.forEach(function(emoji){var item=fallbackEmojiItems.find(function(candidate){return candidate.emoji===emoji;})||emojiItems.find(function(candidate){return candidate.emoji===emoji;});if(item)quick.push(item);});}var matches=emojiItems.filter(function(item){var haystack=(item.emoji+" "+item.label+" "+item.tags.join(" ")).toLowerCase();return !q||haystack.indexOf(q)!==-1;}).sort(function(a,b){var qa=quickEmojiOrder.indexOf(a.emoji);var qb=quickEmojiOrder.indexOf(b.emoji);if(qa!==-1||qb!==-1)return (qa===-1?999:qa)-(qb===-1?999:qb);return (a.order||0)-(b.order||0);});quick.concat(matches).forEach(function(item){if(used[item.emoji])return;used[item.emoji]=true;combined.push(item);});emojiFilteredItems=combined;appendEmojiChunk();}
    function openPicker(mode,messageId,x,y){if(!emojiPicker)return;pickerMode=mode;pickerMessageId=messageId||"";if(emojiSearch)emojiSearch.value="";renderEmojiList("");placeBox(emojiPicker,x,y);if(emojiSearch)emojiSearch.focus();}
    function react(messageId,emoji){var body=new URLSearchParams();body.append("action","react");body.append("messageId",messageId);body.append("emoji",emoji);jsonFetch("/chat/"+id,{method:"POST",body:body,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}if(data.ok){renderMessages(data,false);}}).catch(function(){});}
    function confirmDeleteMessage(){if(typeof window.showSitePopup==="function"){return window.showSitePopup({title:"delete message?",detail:"this will replace the message with a deleted placeholder for everyone in the chat.",okText:"delete",cancelText:"cancel"});}return window.showSitePopup({title:"delete message?",detail:"this will replace the message with a deleted placeholder for everyone in the chat.",okText:"delete",cancelText:"cancel"});}
    function deleteMessage(messageId){if(!messageId)return;confirmDeleteMessage().then(function(confirmed){if(!confirmed)return;var body=new URLSearchParams();body.append("action","delete-message");body.append("messageId",messageId);jsonFetch("/chat/"+id,{method:"POST",body:body,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}if(data.ok){renderMessages(data,false);}else if(data.error){window.showSiteNotice ? window.showSiteNotice("chat error", data.error) : window.showSitePopup({title:"chat error", detail:data.error, okText:"ok"});}}).catch(function(){});});}
    function submitMessage(event){event.preventDefault();if(!form||!textarea||postingRestricted)return;var body=textarea.value.trim();if(body===""&&!hasFile())return;var isVoice=attachmentKindInput&&attachmentKindInput.value==="voice";if(hasFile()&&!isVoice&&fileInput.files[0].size>8388608){window.showSiteNotice ? window.showSiteNotice("file too big", "max size is 8 MB.") : window.showSitePopup({title:"file too big", detail:"max size is 8 MB.", okText:"ok"});return;}if(hasFile()&&isVoice&&fileInput.files[0].size>12000000){window.showSiteNotice ? window.showSiteNotice("voice note too big", "keep voice notes under 2 minutes.") : window.showSitePopup({title:"voice note too big", detail:"keep voice notes under 2 minutes.", okText:"ok"});return;}if(typingIdleTimer)clearTimeout(typingIdleTimer);sendTypingState(false,true);var payload=new FormData(form);textarea.disabled=true;if(fileInput)fileInput.disabled=true;if(sendButton)sendButton.disabled=true;jsonFetch(form.getAttribute("action")||("/chat/"+id),{method:"POST",body:payload,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"}}).then(function(data){if(data.exists===false){window.location.href="/chat/"+id;return;}if(data.ok){textarea.value="";if(fileInput)fileInput.value="";if(attachmentKindInput)attachmentKindInput.value="";clearReply();syncFileIndicator();renderMessages(data,true);}else if(data.error){window.showSiteNotice ? window.showSiteNotice("chat error", data.error) : window.showSitePopup({title:"chat error", detail:data.error, okText:"ok"});}}).catch(function(){form.submit();}).finally(function(){textarea.disabled=false;if(fileInput)fileInput.disabled=false;if(sendButton)sendButton.disabled=false;textarea.focus();});}
    if(form&&textarea){form.addEventListener("submit",submitMessage);textarea.addEventListener("input",queueTyping);textarea.addEventListener("blur",function(){if(typingIdleTimer)clearTimeout(typingIdleTimer);sendTypingState(false,true);});textarea.addEventListener("keydown",function(event){if(event.key==="Enter"&&!event.shiftKey){event.preventDefault();if(form.requestSubmit){form.requestSubmit();}else{submitMessage(event);}}});}
    if(fileInput){fileInput.addEventListener("change",function(){if(attachmentKindInput)attachmentKindInput.value="";syncFileIndicator();});syncFileIndicator();}
    if(attachButton&&attachMenu){attachButton.addEventListener("click",function(event){event.preventDefault();closeMenu();closePicker();attachMenu.style.display=attachMenu.style.display==="block"?"none":"block";});attachMenu.addEventListener("click",function(event){var action=event.target.closest("[data-chat-compose-action]");if(!action)return;event.preventDefault();if(action.getAttribute("data-chat-compose-action")==="upload"){if(attachmentKindInput)attachmentKindInput.value="";if(fileInput){fileInput.value="";syncFileIndicator();fileInput.click();}closeAttachMenu();}else if(action.getAttribute("data-chat-compose-action")==="voice"){if(voiceRecorderEl){voiceRecorderEl.hidden=false;}closeAttachMenu();}});}
    if(voiceRecorderEl&&typeof window.fridg3CreateVoiceRecorder==="function"){window.fridg3CreateVoiceRecorder(voiceRecorderEl,function(file){var dt=new DataTransfer();dt.items.add(file);if(fileInput)fileInput.files=dt.files;if(attachmentKindInput)attachmentKindInput.value="voice";syncFileIndicator();});}
    if(replyCancel){replyCancel.addEventListener("click",clearReply);}
    if(emojiButton){emojiButton.addEventListener("click",function(event){event.preventDefault();closeMenu();closeAttachMenu();var rect=emojiButton.getBoundingClientRect();openPicker("insert","",rect.left,rect.top-330);});}
    if(messagesEl){messagesEl.addEventListener("contextmenu",function(event){var message=event.target.closest(".chat-message[data-message-id]");if(!message)return;event.preventDefault();});messagesEl.addEventListener("click",function(event){var ref=event.target.closest("[data-scroll-message]");if(ref){var target=messagesEl.querySelector('.chat-message[data-message-id="'+ref.getAttribute("data-scroll-message")+'"]');if(target){target.scrollIntoView({block:"center",behavior:"smooth"});target.classList.add("chat-message-highlight");setTimeout(function(){target.classList.remove("chat-message-highlight");},1200);}return;}var reaction=event.target.closest(".chat-reaction[data-message-id][data-emoji]");if(reaction){react(reaction.getAttribute("data-message-id"),reaction.getAttribute("data-emoji"));return;}if(event.target.closest(".chat-attachment-image,.chat-attachment-media,.chat-attachment-file,.chat-attachment-download"))return;var message=event.target.closest(".chat-message[data-message-id]");if(message&&message.getAttribute("data-message-deleted")!=="1"){event.preventDefault();closePicker();var rect=message.getBoundingClientRect();openMenu(message,rect.left+12,rect.bottom+6);}});}
    if(menu){menu.addEventListener("click",function(event){var action=event.target.closest("[data-chat-action]");if(!action)return;event.stopPropagation();var message=messagesEl?messagesEl.querySelector('.chat-message[data-message-id="'+menu.dataset.messageId+'"]'):null;if(action.getAttribute("data-chat-action")==="reply"){setReply(message);closeMenu();}else if(action.getAttribute("data-chat-action")==="delete"){deleteMessage(menu.dataset.messageId);closeMenu();}else{var rect=menu.getBoundingClientRect();openPicker("react",menu.dataset.messageId,rect.left,rect.bottom+6);closeMenu();}});}
    if(emojiSearch){emojiSearch.addEventListener("input",function(){renderEmojiList(emojiSearch.value);});}
    if(emojiGrid){emojiGrid.addEventListener("scroll",function(){if(emojiRenderedCount<emojiFilteredItems.length&&emojiGrid.scrollTop+emojiGrid.clientHeight>=emojiGrid.scrollHeight-80){appendEmojiChunk();}});emojiGrid.addEventListener("click",function(event){var btn=event.target.closest("[data-emoji]");if(!btn)return;var emoji=btn.getAttribute("data-emoji");if(pickerMode==="react"&&pickerMessageId){react(pickerMessageId,emoji);}else if(textarea){var start=textarea.selectionStart||textarea.value.length;var end=textarea.selectionEnd||start;textarea.value=textarea.value.slice(0,start)+emoji+textarea.value.slice(end);textarea.focus();textarea.setSelectionRange(start+emoji.length,start+emoji.length);}closePicker();});}
    document.addEventListener("click",function(event){if(menu&&menu.style.display==="block"&&!event.target.closest(".chat-context-menu")&&!event.target.closest(".chat-message"))closeMenu();if(attachMenu&&attachMenu.style.display==="block"&&!event.target.closest(".chat-attach-menu")&&!event.target.closest(".chat-attach-button"))closeAttachMenu();if(emojiPicker&&emojiPicker.style.display==="block"&&!event.target.closest(".chat-emoji-picker")&&!event.target.closest(".chat-emoji-button"))closePicker();});
    document.addEventListener("keydown",function(event){if(event.key==="Escape"){closeMenu();closeAttachMenu();closePicker();}});
    setInterval(function(){jsonFetch("/chat/"+id+"?action=status",{cache:"no-store"}).then(function(data){if(!data.exists){window.location.href="/chat/"+id;}}).catch(function(){});},5000);
    document.addEventListener("visibilitychange",function(){if(!isChatActive())sendTypingState(false,true);ping();if(isChatActive()){clearUnread();refreshMessages(false);}});
    window.addEventListener("focus",function(){clearUnread();ping();refreshMessages(false);});
    window.addEventListener("blur",function(){sendTypingState(false,true);ping();});
    window.addEventListener("pagehide",function(){sendTypingState(false,true);pingAway();});
    loadEmojiData();initChatMediaPlayers();scrollMessages(true);ping();refreshMessages(true);showRecipientIntro();setInterval(ping,5000);setInterval(refreshPresence,1000);setInterval(function(){refreshMessages(false);},2000);
}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",initChat);}else{initChat();}
}());
</script>
HTML;
    $canDeleteConversation = chat_user_can_delete_conversation($conversation, $canManage);
    $isAccountLinkedRecipient = !$canManage && chat_is_account_participant($conversation);
    $content = '<section class="chat-view" data-chat-id="' . chat_h($conversationId) . '" data-can-manage="' . ($canManage ? '1' : '0') . '" data-posting-restricted="' . ($postingRestricted ? '1' : '0') . '" data-viewer-role="' . chat_h($viewerRole) . '" data-recipient-name="' . chat_h($recipientName) . '" data-show-recipient-intro="' . ($showRecipientIntro ? '1' : '0') . '" data-account-linked-recipient="' . ($isAccountLinkedRecipient ? '1' : '0') . '">'
        . '<div class="chat-header-row"><div><h1>private chat</h1><h2>recipient: ' . chat_h($recipientName) . '</h2><div class="chat-presence" id="chat-presence" aria-live="polite">checking if the other user is online...</div></div>'
        . ($canDeleteConversation ? '<form class="chat-delete-form" method="post" action="/chat/' . chat_h($conversationId) . '" data-no-spa="1" data-confirm-text="end chat"><input type="hidden" name="action" value="delete"><button class="danger-button chat-delete-button" type="submit">end chat</button></form>' : '')
        . '</div>'
        . '<div class="chat-messages-wrap"><div class="chat-messages" id="chat-messages" aria-live="polite">' . chat_message_html($conversation, $viewerRole) . '</div><div class="chat-typing-indicator" id="chat-typing-indicator" aria-live="polite"></div></div>'
        . '<form class="chat-send-form" method="post" action="/chat/' . chat_h($conversationId) . '" enctype="multipart/form-data" data-no-spa="1">'
        . '<input type="hidden" name="action" value="send">'
        . '<input type="hidden" name="replyTo" value="">'
        . '<div class="chat-reply-compose" aria-live="polite"><div><strong></strong><span></span></div><button type="button" aria-label="cancel reply">x</button></div>'
        . '<input type="hidden" name="attachmentKind" value="">'
        . '<input class="chat-attachment-input" name="attachment" type="file" accept="image/*,audio/*,video/*,.pdf,.txt,.md,.zip,.7z,.rar,.mp3,.wav,.ogg,.oga,.flac,.mp4,.webm,.ogv,.mov,.m4v,.mkv,.avi,.json,.csv" hidden>'
        . '<button class="chat-attach-button" type="button" data-tooltip="add file or voice note">+</button>'
        . '<div class="chat-attach-menu"><button type="button" data-chat-compose-action="upload"><i class="fa-solid fa-paperclip"></i><span>upload file</span></button><button type="button" data-chat-compose-action="voice"><i class="fa-solid fa-microphone"></i><span>record voice note</span></button></div>'
        . '<div class="chat-voice-recorder" hidden></div>'
        . '<textarea name="message" rows="2" maxlength="4000" placeholder="message"></textarea>'
        . '<button class="chat-emoji-button" type="button" data-tooltip="emoji">☺</button>'
        . '<button class="chat-send-button" type="submit">send</button>'
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
            static fn (array $matches): string => fridg3_posting_restriction_notice() . fridg3_disable_composer_controls($matches[0]),
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
if ($createdId !== '') {
    $url = 'https://fridge.dev/chat/' . $createdId;
    $createdNotice = '<div id="result">chat created. share this one-time link:<br><button class="chat-copy-link chat-created-link" type="button" data-copy-url="' . chat_h($url) . '" data-tooltip="copy chat link">' . chat_h($url) . '</button></div><br>';
}
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
        static fn (array $matches): string => fridg3_posting_restriction_notice() . fridg3_disable_composer_controls($matches[0]),
        $content,
        1
    );
}

chat_render_page($title, $description, $content);
