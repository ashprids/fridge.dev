<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/lib/session.php';
require_once $root . '/lib/feed.php';
require_once $root . '/lib/render.php';
require_once $root . '/lib/video-embeds.php';
require_once $root . '/lib/toast-chat.php';
require_once $root . '/lib/toast-chat-errors.php';
fridge_start_session();
fridge_feed_refresh_session_user();
fridge_refresh_current_user_posting_restriction();

const TOAST_CHAT_ROUTE = '/others/toast-discord-bot/chat';

function toast_chat_json(array $payload, int $status = 200): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
function toast_chat_find_template(string $name): ?string {
    $dir = __DIR__; while (dirname($dir) !== $dir) { $path = $dir . '/' . $name; if (is_file($path)) return $path; $dir = dirname($dir); } return null;
}
function toast_chat_render(string $content, string $title = 'chat with toast'): void {
    $templateName = get_preferred_template_name(__DIR__);
    $template = toast_chat_find_template($templateName) ?: toast_chat_find_template('template.html');
    if (!$template) die('page template not found. report this issue to ashton@fridge.dev.');
    $html = apply_preferred_theme_stylesheet((string)file_get_contents($template), __DIR__);
    $greeting = '';
    if (isset($_SESSION['user']['name'])) {
        $greeting = '<div id="user-greeting">Hello, ' . toast_chat_h((string)$_SESSION['user']['name']) . '!</div>';
        $html = str_replace('<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>', '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>', $html);
    }
    echo str_replace(['{content}', '{title}', '{description}', '{user_greeting}'], [$content, $title, 'a private website conversation with toast.', $greeting], $html);
}
function toast_chat_csrf(): string {
    if (empty($_SESSION['toast_chat_csrf'])) $_SESSION['toast_chat_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['toast_chat_csrf'];
}
function toast_chat_request_json(): bool {
    return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}
function toast_chat_bot_reply(array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return ['ok' => false, 'error' => 'could not encode the Toast request'];
    $url = 'http://127.0.0.1:8765/website-chat/reply';
    if (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 130, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $json]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
        if ($raw === false) return ['ok' => false, 'diagnostic' => ['code' => $errno === CURLE_OPERATION_TIMEDOUT ? 'bot_timeout' : 'bot_connection']];
    } else {
        $headers = []; $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json, 'timeout' => 130, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx); $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
        if ($raw === false) return ['ok' => false, 'diagnostic' => ['code' => 'bot_connection']];
    }
    $decoded = json_decode((string)$raw, true);
    if ($code >= 400) return ['ok' => false, 'diagnostic' => ['code' => 'bot_http', 'http_status' => $code]];
    if (!is_array($decoded)) return ['ok' => false, 'diagnostic' => ['code' => 'bot_invalid_json']];
    if ($code >= 400) $decoded['ok'] = false;
    return $decoded;
}

if (!toast_chat_is_online()) {
    header('Cache-Control: no-store');
    if (toast_chat_request_json() || isset($_GET['action']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        toast_chat_json(['ok' => false, 'exists' => true, 'offline' => true, 'error' => 'Toast is offline. Chat will be available when Toast is online again.'], 503);
    }
    header('Location: /others/toast-discord-bot?chat_offline=1', true, 302);
    exit;
}

$identity = toast_chat_identity(false);
$id = (string)$identity['id'];
$csrf = toast_chat_csrf();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$postingRestricted = fridge_current_user_posting_restricted();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'clear-chat') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) toast_chat_json(['ok'=>false,'error'=>'invalid request token.'],403);
    $conversation = toast_chat_read($id);
    if ($conversation) {
        $conversation = toast_chat_with_lock($id, static function() use ($id, $identity) {
            $chat = toast_chat_read($id);
            if (!$chat) return toast_chat_new_conversation($identity);
            toast_chat_clear_for_visitor($chat);
            if (!toast_chat_write($chat)) return null;
            return $chat;
        });
        if (!$conversation) toast_chat_json(['ok'=>false,'error'=>'could not clear chat.'],500);
    }
    toast_chat_json(toast_chat_payload($conversation ?: toast_chat_new_conversation($identity)));
}

if ($action === 'attachment') {
    $attachmentId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['file'] ?? '')));
    $conversation = toast_chat_read($id);
    $found = false;
    foreach (toast_chat_visible_messages($conversation) as $message) {
        if ((string)(($message['attachment'] ?? [])['id'] ?? '') === $attachmentId && empty($message['deletedAt'])) { $found = true; break; }
    }
    $attachment = $found ? toast_chat_load_attachment($id, (string)$attachmentId) : null;
    if (!$attachment) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Content-Length: ' . strlen((string)$attachment['data'])); header('Content-Disposition: inline; filename="image.jpg"'); header('X-Content-Type-Options: nosniff');
    echo $attachment['data']; exit;
}

if ($action === 'messages' || $action === 'presence' || $action === 'status') {
    $conversation = toast_chat_load_or_create($identity);
    if (!is_array($conversation)) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'chat storage is unavailable.'], 500);
    if ($action === 'messages') toast_chat_json(toast_chat_payload($conversation));
    if ($action === 'status') toast_chat_json(['ok' => true, 'exists' => true]);
    $away = !empty(toast_chat_status_read()['away']);
    $pending = toast_chat_is_pending($conversation);
    toast_chat_json(['ok' => true, 'exists' => true, 'viewerRole' => 'visitor', 'otherRole' => 'toast', 'otherOnline' => !$away, 'otherAway' => $away, 'otherStatus' => $away ? 'away' : 'online', 'otherTyping' => $pending, 'typingStartsAtMs' => (int)($conversation['typingStartsAtMs'] ?? 0), 'serverTimeMs' => (int)floor(microtime(true) * 1000), 'otherLastSeen' => time(), ...toast_chat_payload($conversation)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action, ['react', 'delete-message', 'hide-message'], true)) {
    if (!hash_equals($csrf, trim((string)($_POST['csrf'] ?? '')))) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'invalid request token.'], 403);
    $messageId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['messageId'] ?? '')));
    if (!toast_chat_read($id)) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'message not found.'], 404);
    $result = toast_chat_with_lock($id, function() use ($identity, $action, $messageId) {
        $chat = toast_chat_load_or_create($identity); $found = false; $deletedAttachmentId = '';
        $visibleIds = array_column(toast_chat_visible_messages($chat), 'id');
        foreach ($chat['messages'] as &$message) {
            if (!in_array($message['id'] ?? '', $visibleIds, true)) continue;
            if ((string)($message['id'] ?? '') !== $messageId || !empty($message['deletedAt'])) continue;
            if ($action === 'delete-message') {
                if (($message['sender'] ?? '') !== 'visitor') return ['error' => 'you can only delete your own messages.', 'status' => 403];
                $deletedAttachmentId = (string)(($message['attachment'] ?? [])['id'] ?? '');
                $message['body'] = ''; $message['deletedAt'] = time(); unset($message['attachment'], $message['reactions']);
            } elseif ($action === 'hide-message') {
                if (($message['sender'] ?? '') === 'visitor') return ['error' => 'only Toast messages can be hidden.', 'status' => 400];
                $message['hiddenForVisitor'] = empty($message['hiddenForVisitor']);
            } else {
                $emoji = toast_chat_emoji((string)($_POST['emoji'] ?? ''));
                if ($emoji === '') return ['error' => 'invalid reaction.', 'status' => 400];
                $message['reactions'] = (array)($message['reactions'] ?? []);
                if (!empty($message['reactions'][$emoji])) unset($message['reactions'][$emoji]); else $message['reactions'][$emoji] = true;
            }
            $found = true; break;
        }
        unset($message);
        if (!$found) return ['error' => 'message not found.', 'status' => 404];
        $chat['updatedAt'] = time();
        if (!toast_chat_write($chat)) return ['error' => 'chat storage is unavailable.', 'status' => 500];
        if ($deletedAttachmentId !== '') @unlink(toast_chat_attachment_path((string)$chat['id'], $deletedAttachmentId));
        return ['chat' => $chat];
    });
    if (!is_array($result) || isset($result['error'])) toast_chat_json(['ok' => false, 'exists' => true, 'error' => (string)($result['error'] ?? 'chat storage is unavailable.')], (int)($result['status'] ?? 500));
    toast_chat_json(toast_chat_payload($result['chat']));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'send') {
    if (!hash_equals($csrf, trim((string)($_POST['csrf'] ?? '')))) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'invalid request token.'], 403);
    if ($postingRestricted) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'your account has been restricted.'], 403);
    $body = toast_chat_clean_text((string)($_POST['message'] ?? ''));
    if (strlen($body) > TOAST_CHAT_MAX_MESSAGE_LENGTH) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'messages must be 4000 characters or less.'], 400);
    $upload = is_array($_FILES['attachment'] ?? null) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['attachment'] : null;
    if ($body === '' && $upload === null) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'write a message or attach an image.'], 400);
    $jpeg = null;
    if ($upload !== null) {
        $jpeg = toast_chat_compress_upload($upload, $imageError);
        if (!is_string($jpeg)) toast_chat_json(['ok' => false, 'exists' => true, 'error' => $imageError ?: 'image upload failed.'], 400);
    }
    $linkMetadata = $body !== '' ? toast_chat_collect_link_metadata($body) : [];
    $identity = toast_chat_identity(); $id = (string)$identity['id'];
    $token = bin2hex(random_bytes(16));
    $accepted = toast_chat_with_lock($id, function() use ($identity, $body, $jpeg, $token, $linkMetadata) {
        $chat = toast_chat_load_or_create($identity);
        if (toast_chat_is_pending($chat)) return ['error' => 'wait for Toast to reply before sending another message.', 'status' => 409];
        $count = toast_chat_daily_count($chat);
        if ($count >= TOAST_CHAT_DAILY_LIMIT) return ['error' => 'you have reached the 100 message daily limit. try again tomorrow.', 'status' => 429, 'quotaResetAt' => toast_chat_next_reset()];
        $message = ['id' => bin2hex(random_bytes(8)), 'sender' => 'visitor', 'body' => $body, 'createdAt' => time(), 'visibleAt' => time()];
        if ($linkMetadata !== []) $message['linkMetadata'] = $linkMetadata;
        $replyTo = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['replyTo'] ?? '')));
        foreach (toast_chat_visible_messages($chat) as $existing) if ((string)($existing['id'] ?? '') === $replyTo && empty($existing['deletedAt'])) { $message['replyTo'] = $replyTo; break; }
        if (is_string($jpeg)) {
            $attachment = toast_chat_encrypt_attachment((string)$chat['id'], $jpeg);
            if (!$attachment) return ['error' => 'image storage is unavailable.', 'status' => 500];
            $message['attachment'] = $attachment;
        }
        $day = toast_chat_day_key(); $chat['dailyCounts'] = [$day => $count + 1];
        if ($body === '/clearmemory' && $jpeg === null) { $message['memoryBoundary'] = true; $message['reactions'] = ['✅' => true]; }
        else { $chat['pendingToken'] = $token; $chat['typingStartsAtMs'] = (int)floor(microtime(true) * 1000) + 1750; $chat['pendingUntil'] = time() + TOAST_CHAT_PENDING_TTL; }
        $chat['messages'][] = $message; $chat['updatedAt'] = time();
        if (!toast_chat_write($chat)) {
            if (isset($message['attachment']['id'])) @unlink(toast_chat_attachment_path((string)$chat['id'], (string)$message['attachment']['id']));
            return ['error' => 'chat storage is unavailable.', 'status' => 500];
        }
        return ['chat' => $chat, 'message' => $message];
    });
    if (!is_array($accepted) || isset($accepted['error'])) {
        $payload = ['ok' => false, 'exists' => true, 'error' => (string)($accepted['error'] ?? 'chat storage is unavailable.')];
        if (isset($accepted['quotaResetAt'])) $payload['quotaResetAt'] = $accepted['quotaResetAt'];
        toast_chat_json($payload, (int)($accepted['status'] ?? 500));
    }
    $conversation = $accepted['chat']; $sent = $accepted['message'];
    if (!empty($sent['memoryBoundary'])) toast_chat_json(toast_chat_payload($conversation));

    $context = toast_chat_context($conversation);
    $botPayload = ['messages' => $context, 'current_message' => $body, 'account_username' => $identity['type'] === 'account' ? $identity['value'] : ''];
    if (is_string($jpeg)) $botPayload['image'] = 'data:image/jpeg;base64,' . base64_encode($jpeg);
    session_write_close();
    $reply = toast_chat_bot_reply($botPayload);
    $dailyQuota = !empty($reply['daily_quota_exceeded']);
    if ($dailyQuota) toast_chat_status_write(true);
    elseif (!empty($reply['provider_responded'])) toast_chat_status_write(false);
    $chunks = array_values(array_filter((array)($reply['chunks'] ?? []), static fn($v): bool => is_string($v) && trim($v) !== ''));
    if ($chunks === []) $chunks = ["yo i'll talk later, sorry dude busy rn"];
    $delays = (array)($reply['delays'] ?? []); $visibleAt = time();
    $chunkMetadata = array_map(static fn(string $chunk): array => toast_chat_collect_link_metadata($chunk), $chunks);
    $final = toast_chat_with_lock($id, function() use ($identity, $token, $chunks, $delays, $chunkMetadata, &$visibleAt) {
        $chat = toast_chat_read((string)$identity['id']);
        if (!is_array($chat)) return toast_chat_new_conversation($identity);
        if (!hash_equals((string)($chat['pendingToken'] ?? ''), $token)) return $chat;
        foreach ($chunks as $index => $chunk) {
            $delay = max(5, min(12, (int)ceil((float)($delays[$index] ?? 5)))); $visibleAt += $delay;
            $message = ['id' => bin2hex(random_bytes(8)), 'sender' => 'toast', 'body' => trim($chunk), 'createdAt' => $visibleAt, 'visibleAt' => $visibleAt];
            if (($chunkMetadata[$index] ?? []) !== []) $message['linkMetadata'] = $chunkMetadata[$index];
            $chat['messages'][] = $message;
        }
        $chat['pendingUntil'] = $visibleAt; $chat['updatedAt'] = time(); toast_chat_write($chat); return $chat;
    });
    if (!is_array($final)) toast_chat_json(['ok' => false, 'exists' => true, 'error' => 'chat storage is unavailable.'], 500);
    $response = toast_chat_payload($final);
    $adminError = toast_chat_admin_error($reply, !empty($_SESSION['user']['isAdmin']));
    if ($adminError !== null) $response['adminError'] = $adminError;
    toast_chat_json($response);
}

$conversation = toast_chat_load_or_create($identity);
if (!is_array($conversation)) { toast_chat_render('<h1>chat unavailable</h1><p>chat storage is unavailable.</p>'); exit; }
$payload = toast_chat_payload($conversation);
$disabled = $postingRestricted || !empty($payload['sendBlocked']);
$content = strtr((string)file_get_contents(__DIR__ . '/content.html'), [
    '{chat_id}' => toast_chat_h($id), '{csrf}' => toast_chat_h($csrf), '{route}' => TOAST_CHAT_ROUTE,
    '{send_blocked}' => !empty($payload['sendBlocked']) ? '1' : '0',
    '{quota_reached}' => (int)$payload['dailyCount'] >= TOAST_CHAT_DAILY_LIMIT ? '1' : '0',
    '{posting_restricted}' => $postingRestricted ? '1' : '0',
    '{posting_notice}' => $postingRestricted ? fridge_posting_restriction_notice() : '',
    '{disabled}' => $disabled ? ' disabled' : '', '{messages}' => toast_chat_messages_html($conversation),
]);
if ($postingRestricted) {
    $content = fridge_disable_composer_controls($content);
    $content = preg_replace('/(<button) disabled([^>]*class="toast-clear-chat[^"]*")/', '$1$2', $content);
}
toast_chat_render($content);
