<?php
declare(strict_types=1);

const TOAST_CHAT_DAILY_LIMIT = 100;
const TOAST_CHAT_MAX_MESSAGE_LENGTH = 4000;
const TOAST_CHAT_MAX_SOURCE_IMAGE_BYTES = 8388608;
const TOAST_CHAT_MAX_IMAGE_BYTES = 500000;
const TOAST_CHAT_MAX_IMAGE_DIMENSION = 1000;
const TOAST_CHAT_PENDING_TTL = 180;
const TOAST_CHAT_TIMEZONE = 'Europe/London';

function toast_chat_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function toast_chat_data_dir(): string {
    $override = getenv('FRIDG3_TOAST_CHAT_DATA_DIR');
    return is_string($override) && $override !== '' ? rtrim($override, '/') : dirname(__DIR__) . '/data/etc/toast-chats';
}
function toast_chat_key_path(): string { return toast_chat_data_dir() . '/.key'; }
function toast_chat_status_path(): string { return toast_chat_data_dir() . '/.status.json'; }

function toast_chat_ensure_dir(string $path): bool {
    return is_dir($path) || @mkdir($path, 0750, true) || is_dir($path);
}

function toast_chat_read_key_file(string $path): ?string {
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return null;
    if (!flock($handle, LOCK_SH)) { fclose($handle); return null; }
    $value = stream_get_contents($handle);
    flock($handle, LOCK_UN); fclose($handle);
    return is_string($value) && strlen($value) >= 32 ? substr($value, 0, 32) : null;
}

function toast_chat_key(): string {
    $env = getenv('FRIDG3_TOAST_CHAT_KEY');
    if (is_string($env) && $env !== '') {
        $decoded = base64_decode($env, true);
        return is_string($decoded) && strlen($decoded) >= 32 ? substr($decoded, 0, 32) : hash('sha256', $env, true);
    }
    toast_chat_ensure_dir(toast_chat_data_dir());
    $path = toast_chat_key_path();
    $stored = toast_chat_read_key_file($path);
    if (is_string($stored)) return $stored;
    $key = random_bytes(32);
    $handle = @fopen($path, 'x');
    if (is_resource($handle)) {
        flock($handle, LOCK_EX);
        $written = fwrite($handle, $key);
        fflush($handle); flock($handle, LOCK_UN); fclose($handle); @chmod($path, 0640);
        if ($written === 32) return $key;
        @unlink($path);
    }
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stored = toast_chat_read_key_file($path);
        if (is_string($stored)) return $stored;
        usleep(20000);
    }
    throw new RuntimeException('Toast chat encryption key is unavailable.');
}

function toast_chat_client_ip(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP)) return $remote;
    if (function_exists('fridg3_feed_client_ip')) {
        $fallback = trim(fridg3_feed_client_ip());
        if (filter_var($fallback, FILTER_VALIDATE_IP)) return $fallback;
    }
    return '0.0.0.0';
}

function toast_chat_identity(bool $createKey = true): array {
    $key = $createKey || getenv('FRIDG3_TOAST_CHAT_KEY') || is_file(toast_chat_key_path()) ? toast_chat_key() : null;

    $username = strtolower(trim((string)($_SESSION['user']['username'] ?? '')));
    if ($username !== '') {
        $raw = 'account:' . $username;
        return ['id' => ($key === null ? 'unsaved' : 'account-' . hash_hmac('sha256', $raw, $key)), 'type' => 'account', 'value' => $username, 'label' => '@' . $username];
    }
    $ip = toast_chat_client_ip();
    $raw = 'ip:' . $ip;
    return ['id' => ($key === null ? 'unsaved' : 'ip-' . hash_hmac('sha256', $raw, $key)), 'type' => 'ip', 'value' => $ip, 'label' => $ip];
}

function toast_chat_valid_id(string $id): bool { return preg_match('/^(?:account|ip)-[a-f0-9]{64}$/', $id) === 1; }
function toast_chat_path(string $id): string { return toast_chat_data_dir() . '/' . $id . '.json'; }
function toast_chat_lock_path(string $id): string { return toast_chat_data_dir() . '/.' . $id . '.lock'; }
function toast_chat_attachment_dir(string $id): string { return toast_chat_data_dir() . '/attachments/' . $id; }
function toast_chat_attachment_path(string $id, string $attachmentId): string { return toast_chat_attachment_dir($id) . '/' . $attachmentId . '.json'; }

function toast_chat_encrypt_value($value): ?array {
    $plain = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($plain === false) return null;
    $nonce = random_bytes(12); $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', toast_chat_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext)) return null;
    return ['version' => 1, 'cipher' => 'aes-256-gcm', 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)];
}

function toast_chat_decrypt_value(array $envelope) {
    if (($envelope['version'] ?? null) !== 1) return null;
    $nonce = base64_decode((string)($envelope['nonce'] ?? ''), true);
    $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
    $cipher = base64_decode((string)($envelope['ciphertext'] ?? ''), true);
    if (!is_string($nonce) || !is_string($tag) || !is_string($cipher)) return null;
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', toast_chat_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($plain)) return null;
    return json_decode($plain, true);
}

function toast_chat_atomic_json(string $path, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || !toast_chat_ensure_dir(dirname($path))) return false;
    $tmp = tempnam(dirname($path), '.toast-chat-');
    if ($tmp === false) return false;
    $ok = @file_put_contents($tmp, $json, LOCK_EX) !== false;
    if ($ok) @chmod($tmp, 0640);
    $ok = $ok && @rename($tmp, $path);
    if (!$ok) @unlink($tmp);
    return $ok;
}

function toast_chat_read(string $id): ?array {
    if (!toast_chat_valid_id($id) || !is_file(toast_chat_path($id))) return null;
    $envelope = json_decode((string)@file_get_contents(toast_chat_path($id)), true);
    if (!is_array($envelope)) return null;
    $value = toast_chat_decrypt_value($envelope);
    return is_array($value) ? $value : null;
}

function toast_chat_write(array $conversation): bool {
    $id = (string)($conversation['id'] ?? '');
    if (!toast_chat_valid_id($id)) return false;
    $envelope = toast_chat_encrypt_value($conversation);
    return is_array($envelope) && toast_chat_atomic_json(toast_chat_path($id), $envelope);
}

function toast_chat_with_lock(string $id, callable $callback) {
    toast_chat_ensure_dir(toast_chat_data_dir());
    $handle = @fopen(toast_chat_data_dir() . '/.conversations.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return null;
    }
    try { return $callback(); }
    finally { flock($handle, LOCK_UN); fclose($handle); }
}

function toast_chat_new_conversation(array $identity): array {
    return ['id' => $identity['id'], 'identityType' => $identity['type'], 'identityValue' => $identity['value'], 'identityLabel' => $identity['label'], 'createdAt' => time(), 'updatedAt' => time(), 'messages' => [], 'dailyCounts' => []];
}

function toast_chat_load_or_create(array $identity): array {
    $conversation = toast_chat_read($identity['id']);
    if (!is_array($conversation)) $conversation = toast_chat_new_conversation($identity);
    $conversation['identityValue'] = $identity['value'];
    $conversation['identityLabel'] = $identity['label'];
    toast_chat_clear_stale_pending($conversation);
    return $conversation;
}

function toast_chat_clear_stale_pending(array &$conversation): void {
    $until = (int)($conversation['pendingUntil'] ?? 0);
    if ($until > 0 && $until <= time()) unset($conversation['pendingToken'], $conversation['pendingUntil'], $conversation['typingStartsAtMs']);
}

function toast_chat_day_key(?int $timestamp = null): string {
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(new DateTimeZone(TOAST_CHAT_TIMEZONE))->format('Y-m-d');
}
function toast_chat_next_reset(): int {
    $now = (new DateTimeImmutable('now', new DateTimeZone(TOAST_CHAT_TIMEZONE)));
    return $now->modify('tomorrow')->setTime(0, 0)->getTimestamp();
}
function toast_chat_daily_count(array $conversation): int { return (int)(($conversation['dailyCounts'] ?? [])[toast_chat_day_key()] ?? 0); }
function toast_chat_is_pending(array $conversation): bool { return (int)($conversation['pendingUntil'] ?? 0) > time(); }

function toast_chat_status_read(): array {
    $data = is_file(toast_chat_status_path()) ? json_decode((string)@file_get_contents(toast_chat_status_path()), true) : [];
    return is_array($data) ? $data : [];
}
function toast_chat_status_write(bool $away): bool {
    return toast_chat_atomic_json(toast_chat_status_path(), ['away' => $away, 'updatedAt' => time()]);
}

function toast_chat_clean_text(string $body): string { return trim(str_replace(["\r\n", "\r"], "\n", $body)); }
function toast_chat_emoji(string $emoji): string {
    $emoji = trim($emoji);
    if ($emoji === '' || strlen($emoji) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $emoji)
        || !preg_match('/(?:\p{Extended_Pictographic}|\p{Regional_Indicator}|[#*0-9]\x{FE0F}?\x{20E3})/u', $emoji)) return '';
    return $emoji;
}
function toast_chat_summary(array $message): string {
    if (!empty($message['deletedAt'])) return 'message deleted';
    $body = trim((string)($message['body'] ?? ''));
    if ($body !== '') return function_exists('mb_substr') ? mb_substr($body, 0, 160) : substr($body, 0, 160);
    return isset($message['attachment']) ? 'image' : 'message';
}
function toast_chat_linkify(string $body): string {
    $escaped = toast_chat_h($body);
    $linked = preg_replace_callback('~https?://[^\s<]+~i', static function(array $m): string {
        $url = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');
        return '<a class="chat-message-link" href="' . toast_chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . toast_chat_h($url) . '</a>';
    }, $escaped);
    return nl2br((string)$linked, false);
}

function toast_chat_extract_urls(string $body): array {
    if (preg_match_all('~https?://[^\s<>"\']+~iu', $body, $matches) !== 1) return [];
    $urls = [];
    foreach ($matches[0] as $candidate) {
        $url = rtrim((string)$candidate, '.,!?;:)\]}');
        if (filter_var($url, FILTER_VALIDATE_URL)) $urls[$url] = true;
        if (count($urls) >= 4) break;
    }
    return array_keys($urls);
}

function toast_chat_url_host_is_public(string $url): bool {
    $host = strtolower(rtrim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record')) {
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
    }
    if ($ips === []) return false;
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

function toast_chat_fetch_link_metadata(string $url): ?array {
    if (!toast_chat_url_host_is_public($url) || !function_exists('curl_init')) return null;
    $body = ''; $contentType = '';
    $ch = curl_init($url);
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
        CURLOPT_USERAGENT => 'fridge.dev Toast chat link preview/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9'], CURLOPT_RETURNTRANSFER => false,
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
    $ok = curl_exec($ch); $curlError = curl_errno($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $stoppedAtLimit = $ok === false && defined('CURLE_WRITE_ERROR') && $curlError === CURLE_WRITE_ERROR && strlen($body) >= 262144;
    if (($ok === false && !$stoppedAtLimit) || $status < 200 || $status >= 300) return null;
    if (stripos($contentType, 'image/') !== false) return ['title' => rawurldecode(basename((string)(parse_url($url, PHP_URL_PATH) ?? ''))) ?: 'linked image', 'description' => '', 'image' => $url];
    if ($contentType !== '' && stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml+xml') === false) return null;
    $title = ''; $description = ''; $image = '';
    if (preg_match_all('/<meta\b[^>]*>/i', $body, $metaTags)) {
        foreach ($metaTags[0] as $tag) {
            $attributes = [];
            if (preg_match_all('/([a-zA-Z:-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER)) foreach ($matches as $match) $attributes[strtolower($match[1])] = $match[3];
            $key = strtolower(trim((string)($attributes['property'] ?? $attributes['name'] ?? ''))); $value = trim((string)($attributes['content'] ?? ''));
            if ($description === '' && in_array($key, ['og:description', 'twitter:description', 'description'], true)) $description = $value;
            if ($title === '' && in_array($key, ['og:title', 'twitter:title'], true)) $title = $value;
            if ($image === '' && in_array($key, ['og:image', 'twitter:image', 'twitter:image:src'], true)) $image = $value;
        }
    }
    if ($title === '' && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $body, $match)) $title = $match[1];
    $clean = static function(string $value, int $limit): string {
        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    };
    $title = $clean($title, 160); $description = $clean($description, 320);
    if (str_starts_with($image, '//')) $image = 'https:' . $image;
    if ($image !== '' && !preg_match('~^https?://~i', $image)) {
        $parts = parse_url($url); $image = (string)($parts['scheme'] ?? 'https') . '://' . (string)($parts['host'] ?? '') . '/' . ltrim($image, '/');
    }
    if ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !toast_chat_url_host_is_public($image))) $image = '';
    return ($title === '' && $description === '' && $image === '') ? null : ['title' => $title, 'description' => $description, 'image' => $image];
}

function toast_chat_collect_link_metadata(string $body): array {
    $metadata = [];
    foreach (toast_chat_extract_urls($body) as $url) {
        $extension = strtolower(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg','jpeg','png','gif','webp','avif','mp4','webm','ogv','mov','m4v','mp3','wav','ogg','oga','flac','m4a'], true)) continue;
        $meta = toast_chat_fetch_link_metadata($url);
        if ($meta !== null) $metadata[$url] = $meta;
    }
    return $metadata;
}

function toast_chat_giphy_embed_url(string $url): ?string {
    $parts = parse_url($url); $host = preg_replace('/^www\./', '', strtolower((string)($parts['host'] ?? ''))); $path = (string)($parts['path'] ?? '');
    if ($host !== 'giphy.com') return null;
    if (preg_match('~/embed/([a-zA-Z0-9]+)~', $path, $match) || preg_match('~/gifs/(?:[^/]*-)?([a-zA-Z0-9]+)$~', $path, $match)) return 'https://giphy.com/embed/' . rawurlencode($match[1]);
    return null;
}

function toast_chat_link_embeds_html(string $body, array $metadata = []): string {
    $html = '';
    foreach (toast_chat_extract_urls($body) as $url) {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? 'link'));
        $path = (string)($parts['path'] ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $video = function_exists('fridg3_external_video_embed_data') ? fridg3_external_video_embed_data($url) : null;
        $giphy = toast_chat_giphy_embed_url($url);
        $meta = is_array($metadata[$url] ?? null) ? $metadata[$url] : [];
        if (is_array($video) && function_exists('fridg3_external_video_embed_html')) {
            $html .= '<div class="chat-link-embed chat-link-video">' . fridg3_external_video_embed_html($video) . '</div>';
        } elseif ($giphy !== null) {
            $html .= '<div class="chat-link-embed chat-link-video chat-link-giphy"><iframe src="' . toast_chat_h($giphy) . '" title="Giphy animation" loading="lazy" allowfullscreen></iframe></div>';
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            $html .= '<a class="chat-link-embed chat-link-image" href="' . toast_chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow"><img src="' . toast_chat_h($url) . '" alt="linked image" loading="lazy"></a>';
        } elseif (in_array($extension, ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)) {
            $html .= '<div class="chat-link-embed chat-link-video chat-link-direct-video"><video controls preload="metadata" playsinline src="' . toast_chat_h($url) . '"></video></div>';
        } elseif (in_array($extension, ['mp3', 'wav', 'ogg', 'oga', 'flac', 'm4a'], true)) {
            $html .= '<div class="chat-link-embed"><audio controls preload="metadata" src="' . toast_chat_h($url) . '"></audio><a href="' . toast_chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . toast_chat_h(basename($path) ?: $host) . '</a></div>';
        } else {
            $label = rawurldecode(basename($path)) ?: $host;
            $title = trim((string)($meta['title'] ?? '')) ?: $host;
            $description = trim((string)($meta['description'] ?? '')) ?: $label;
            $image = trim((string)($meta['image'] ?? ''));
            $html .= '<a class="chat-link-embed chat-link-card" href="' . toast_chat_h($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . ($image !== '' ? '<img src="' . toast_chat_h($image) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '') . '<strong>' . toast_chat_h($title) . '</strong><span>' . toast_chat_h($description) . '</span></a>';
        }
    }
    return $html === '' ? '' : '<div class="chat-link-embeds">' . $html . '</div>';
}

function toast_chat_visible_messages(array $conversation): array {
    $now = time();
    return array_values(array_filter(array_slice((array)($conversation['messages'] ?? []), max(0, (int)($conversation['clearedMessageCount'] ?? 0))), static fn($m): bool => is_array($m) && (int)($m['visibleAt'] ?? 0) <= $now));
}

function toast_chat_attachment_url(string $id, string $attachmentId, string $base = '/others/toast-discord-bot/chat'): string {
    $separator = str_contains($base, '?') ? '&' : '?';
    return $base . $separator . 'action=attachment&file=' . rawurlencode($attachmentId);
}

function toast_chat_messages_html(array $conversation, bool $readOnly = false, string $attachmentBase = '/others/toast-discord-bot/chat'): string {
    $messages = $readOnly
        ? array_values(array_filter((array)($conversation['messages'] ?? []), 'is_array'))
        : toast_chat_visible_messages($conversation);
    if ($messages === []) return '<div class="chat-empty">no messages yet.</div>';
    $byId = [];
    foreach ($messages as $m) if (!empty($m['id'])) $byId[(string)$m['id']] = $m;
    $html = ''; $lastDate = '';
    foreach ($messages as $message) {
        $created = (int)($message['createdAt'] ?? time());
        $dateKey = date('Y-m-d', $created);
        if ($dateKey !== $lastDate) { $html .= '<div class="chat-date-divider"><span>' . toast_chat_h(date('M j, Y', $created)) . '</span></div>'; $lastDate = $dateKey; }
        $sender = (string)($message['sender'] ?? 'toast');
        $own = $sender === 'visitor';
        $deleted = !empty($message['deletedAt']);
        $hidden = !$readOnly && !$deleted && !empty($message['hiddenForVisitor']);
        $body = $deleted ? 'message deleted' : ($hidden ? 'message hidden' : toast_chat_linkify((string)($message['body'] ?? '')));
        $replyHtml = '';
        $replyTo = (string)($message['replyTo'] ?? '');
        if (!$deleted && !$hidden && isset($byId[$replyTo]) && empty($byId[$replyTo]['deletedAt'])) {
            $reply = $byId[$replyTo];
            $replyHtml = '<button class="chat-reply-reference" type="button" data-scroll-message="' . toast_chat_h($replyTo) . '"><strong>' . ((string)($reply['sender'] ?? '') === 'visitor' ? 'you' : 'toast') . '</strong><span>' . toast_chat_h(toast_chat_summary($reply)) . '</span></button>';
        }
        $attachmentHtml = '';
        $attachment = !$deleted && !$hidden && is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
        if ($attachment) {
            $attachmentId = (string)($attachment['id'] ?? '');
            $attachmentHtml = '<div class="chat-attachment chat-attachment-image"><img src="' . toast_chat_h(toast_chat_attachment_url((string)$conversation['id'], $attachmentId, $attachmentBase)) . '" alt="' . toast_chat_h((string)($attachment['name'] ?? 'image.jpg')) . '"></div>';
        }
        $reactionHtml = '';
        foreach ((array)($message['reactions'] ?? []) as $emoji => $active) {
            if (!$active) continue;
            $reactionHtml .= $readOnly
                ? '<span class="chat-reaction reacted">' . toast_chat_h((string)$emoji) . '</span>'
                : '<button class="chat-reaction reacted" type="button" data-message-id="' . toast_chat_h((string)$message['id']) . '" data-emoji="' . toast_chat_h((string)$emoji) . '" aria-label="reaction ' . toast_chat_h((string)$emoji) . '">' . toast_chat_h((string)$emoji) . '</button>';
        }
        if ($reactionHtml !== '') $reactionHtml = '<div class="chat-reactions">' . $reactionHtml . '</div>';
        $classes = ['chat-message', $own ? 'chat-message-own' : 'chat-message-other', 'chat-message-' . $sender];
        if ($attachment) $classes[] = 'chat-message-has-image';
        if ($deleted) $classes[] = 'chat-message-deleted';
        if ($hidden) $classes[] = 'chat-message-hidden';
        $html .= '<article class="' . implode(' ', $classes) . '" data-message-id="' . toast_chat_h((string)$message['id']) . '" data-message-own="' . ($own ? '1' : '0') . '" data-message-deleted="' . ($deleted ? '1' : '0') . '">'
            . '<div class="chat-message-meta"><strong>' . ($own ? 'you' : 'toast') . '</strong><span>' . toast_chat_h(date('H:i', $created)) . '</span></div>'
            . '<div class="chat-message-quote-source" hidden>' . toast_chat_h(toast_chat_summary($message)) . '</div>' . $replyHtml
            . ($body !== '' ? '<div class="chat-message-body">' . $body . '</div>' : '') . $attachmentHtml
            . (!$deleted && !$hidden ? toast_chat_link_embeds_html((string)($message['body'] ?? ''), (array)($message['linkMetadata'] ?? [])) : '')
            . ($hidden ? '' : $reactionHtml) . '</article>';
    }
    return $html;
}

function toast_chat_payload(array $conversation): array {
    toast_chat_clear_stale_pending($conversation);
    $messages = toast_chat_visible_messages($conversation);
    $last = $messages === [] ? [] : $messages[array_key_last($messages)];
    $count = toast_chat_daily_count($conversation);
    $pending = toast_chat_is_pending($conversation);
    return ['ok' => true, 'exists' => true, 'html' => toast_chat_messages_html($conversation), 'count' => count($messages),
        'lastMessageId' => (string)($last['id'] ?? ''), 'lastMessageSender' => (string)($last['sender'] ?? ''),
        'revision' => sha1((string)json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'sendBlocked' => $pending || $count >= TOAST_CHAT_DAILY_LIMIT, 'pending' => $pending,
        'dailyCount' => $count, 'dailyLimit' => TOAST_CHAT_DAILY_LIMIT, 'quotaResetAt' => toast_chat_next_reset()];
}

function toast_chat_encrypt_attachment(string $conversationId, string $jpegData): ?array {
    $attachmentId = bin2hex(random_bytes(16));
    $envelope = toast_chat_encrypt_value(['name' => 'image.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpegData), 'data' => base64_encode($jpegData)]);
    if (!is_array($envelope) || !toast_chat_atomic_json(toast_chat_attachment_path($conversationId, $attachmentId), $envelope)) return null;
    return ['id' => $attachmentId, 'name' => 'image.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpegData)];
}

function toast_chat_load_attachment(string $conversationId, string $attachmentId): ?array {
    if (!toast_chat_valid_id($conversationId) || preg_match('/^[a-f0-9]{32}$/', $attachmentId) !== 1) return null;
    $raw = is_file(toast_chat_attachment_path($conversationId, $attachmentId)) ? @file_get_contents(toast_chat_attachment_path($conversationId, $attachmentId)) : false;
    $envelope = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($envelope)) return null;
    $value = toast_chat_decrypt_value($envelope);
    if (!is_array($value)) return null;
    $data = base64_decode((string)($value['data'] ?? ''), true);
    return is_string($data) ? array_merge($value, ['data' => $data]) : null;
}

function toast_chat_compress_upload(array $upload, ?string &$error = null): ?string {
    $error = null;
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) { $error = 'image upload failed.'; return null; }
    if ((int)($upload['size'] ?? 0) > TOAST_CHAT_MAX_SOURCE_IMAGE_BYTES) { $error = 'image uploads must be 8 MB or less.'; return null; }
    $source = (string)$upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) { $error = 'upload a JPEG, PNG, WebP, or GIF image.'; return null; }
    $info = @getimagesize($source);
    if (!is_array($info) || ($info[0] * $info[1]) > 40000000) { $error = 'the image dimensions are invalid or too large.'; return null; }
    $ffmpeg = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ffmpeg === '') { $error = 'image conversion is unavailable.'; return null; }
    foreach ([1000, 850, 700, 550, 400] as $dimension) {
        foreach ([3, 6, 10, 14, 18, 23, 28, 31] as $quality) {
            $dest = tempnam(sys_get_temp_dir(), 'toast_chat_jpg_');
            if ($dest === false) continue;
            $filter = "scale='min({$dimension},iw)':'min({$dimension},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,format=yuvj420p";
            $cmd = escapeshellarg($ffmpeg) . ' -y -v error -i ' . escapeshellarg($source) . ' -frames:v 1 -vf ' . escapeshellarg($filter) . ' -c:v mjpeg -q:v ' . $quality . ' -f image2 ' . escapeshellarg($dest);
            @exec($cmd, $unused, $status);
            $size = @filesize($dest);
            if ($status === 0 && is_int($size) && $size > 0 && $size < TOAST_CHAT_MAX_IMAGE_BYTES) {
                $jpeg = @file_get_contents($dest); @unlink($dest);
                $outInfo = is_string($jpeg) ? @getimagesizefromstring($jpeg) : false;
                if (is_array($outInfo) && $outInfo[0] <= 1000 && $outInfo[1] <= 1000 && ($outInfo['mime'] ?? '') === 'image/jpeg') return $jpeg;
            }
            @unlink($dest);
        }
    }
    $error = 'the image could not be compressed below 500 KB.';
    return null;
}

function toast_chat_context(array $conversation): array {
    $messages = (array)($conversation['messages'] ?? []);
    $boundary = max(-1, (int)($conversation['clearedMessageCount'] ?? 0) - 1);
    foreach ($messages as $i => $message) if (!empty($message['memoryBoundary'])) $boundary = max($boundary, $i);
    $result = [];
    foreach (array_slice($messages, $boundary + 1) as $message) {
        if (!is_array($message) || !empty($message['deletedAt']) || (int)($message['visibleAt'] ?? 0) > time()) continue;
        $body = trim((string)($message['body'] ?? ''));
        if ($body === '' && isset($message['attachment'])) $body = '[image attached]';
        if ($body === '') continue;
        $result[] = ['role' => (string)($message['sender'] ?? '') === 'toast' ? 'assistant' : 'user', 'content' => $body];
    }
    return array_slice($result, -30);
}

function toast_chat_delete(string $id): bool {
    if (!toast_chat_valid_id($id) || !is_file(toast_chat_path($id))) return false;
    return (bool)toast_chat_with_lock($id, static function() use ($id): bool {
        if (!is_file(toast_chat_path($id))) return false;
        foreach (glob(toast_chat_attachment_dir($id) . '/*.json') ?: [] as $file) {
            if (!unlink($file)) return false;
        }
        if (is_dir(toast_chat_attachment_dir($id)) && !rmdir(toast_chat_attachment_dir($id))) return false;
        if (!unlink(toast_chat_path($id))) return false;
        if (is_file(toast_chat_lock_path($id))) @unlink(toast_chat_lock_path($id));
        return true;
    });
}

function toast_chat_clear_for_visitor(array &$conversation): void {
    $conversation['clearedMessageCount'] = count((array)($conversation['messages'] ?? []));
    $conversation['clearedAt'] = time();
    $conversation['pendingToken'] = '';
    $conversation['pendingUntil'] = 0;
    $conversation['typingStartsAtMs'] = 0;
    $conversation['updatedAt'] = time();
}

function toast_chat_is_online(): bool {
    $config = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/etc/toast.json'), true);
    return strtolower((string)($config['bot']['status'] ?? 'offline')) === 'online';
}
