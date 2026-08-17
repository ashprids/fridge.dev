<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'targeted-notifications.php';

function feed_notifications_iso_date(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $date = new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
            return $date->format(DATE_ATOM);
        }
        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    } catch (Throwable $error) {
        return $value;
    }
}

header('Content-Type: application/json');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function feed_notifications_plain_text(string $text, int $maxLength = 220): string {
    $cleaned = preg_replace('/\[audio=([^\]]+)\](?:\[name:([^\]]+)\])?/i', '[voice note]', $text);
    $cleaned = is_string($cleaned) ? $cleaned : $text;
    $cleaned = preg_replace('/\[img=([^\]\s]+)\](?:\[name:[^\]]*\])?/i', '[image]', $cleaned);
    $cleaned = is_string($cleaned) ? $cleaned : $text;
    $cleaned = preg_replace('/\[url=([^\]]+)\](.*?)\[\/url\]/is', '$2', $cleaned);
    $cleaned = is_string($cleaned) ? $cleaned : $text;
    $cleaned = preg_replace('/\[([a-z][a-z0-9_-]*)(?:=[^\]]*)?\](.*?)\[\/\1\]/is', '$2', $cleaned);
    $cleaned = is_string($cleaned) ? $cleaned : $text;
    $cleaned = preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:=[^\]]*)?\]/i', '', $cleaned);
    $cleaned = is_string($cleaned) ? $cleaned : $text;
    $cleaned = trim(preg_replace('/\s+/', ' ', html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8')) ?? '');
    if ($cleaned === '') {
        return '[no text]';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($cleaned) > $maxLength ? rtrim(mb_substr($cleaned, 0, $maxLength - 3)) . '...' : $cleaned;
    }
    return strlen($cleaned) > $maxLength ? rtrim(substr($cleaned, 0, $maxLength - 3)) . '...' : $cleaned;
}

function feed_notifications_load_posts(): array {
    $posts = [];
    $files = glob(fridg3_feed_posts_dir() . DIRECTORY_SEPARATOR . '*.txt');
    if ($files === false) {
        return $posts;
    }

    foreach ($files as $path) {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $lines = preg_split("/(\r\n|\n|\r)/", $raw);
        $isV2 = isset($lines[0]) && trim((string)$lines[0]) === 'v2';
        $headerOffset = $isV2 ? 1 : 0;
        $username = isset($lines[$headerOffset]) ? ltrim(trim((string)$lines[$headerOffset]), '@') : '';
        $date = isset($lines[$headerOffset + 1]) ? trim((string)$lines[$headerOffset + 1]) : '';
        $body = count($lines) > $headerOffset + 2 ? implode("\n", array_slice($lines, $headerOffset + 2)) : '';
        $postId = pathinfo((string)$path, PATHINFO_FILENAME);
        if ($postId === '' || $username === '' || $date === '') {
            continue;
        }
        $posts[$postId] = [
            'id' => $postId,
            'username' => $username,
            'date' => $date,
            'body' => $body,
            'format' => $isV2 ? 'v2' : 'legacy',
        ];
    }

    return $posts;
}

function feed_notifications_accounts_index(): array {
    $index = [];
    foreach (fridg3_feed_load_accounts()['accounts'] as $account) {
        $username = strtolower(trim((string)($account['username'] ?? '')));
        if ($username === '') {
            continue;
        }
        $index[$username] = [
            'username' => (string)$account['username'],
        ];
    }
    return $index;
}

function feed_notifications_mentions(string $body, array $accountsIndex): array {
    $mentions = [];
    $seen = [];
    if (preg_match_all('/@([a-zA-Z0-9_-]{1,32})/', $body, $matches) !== 1) {
        return $mentions;
    }
    foreach ($matches[1] as $rawUsername) {
        $key = strtolower((string)$rawUsername);
        if (isset($seen[$key]) || !isset($accountsIndex[$key])) {
            continue;
        }
        $seen[$key] = true;
        $mentions[] = $accountsIndex[$key];
    }
    return $mentions;
}

function feed_notifications_event(string $key, string $type, string $actor, bool $actorIsGuest, string $action, string $body, string $format, string $url, string $date): array {
    $actorLabel = $actorIsGuest ? $actor : '@' . ltrim($actor, '@');
    $bodyHtml = fridg3_feed_render_post_body($body, $format === 'v2' ? 'v2' : 'legacy');
    $plainBodySource = preg_replace('/<[^>]+>/', ' ', $bodyHtml);
    return [
        'key' => $key,
        'type' => $type,
        'title' => $actorLabel . ' ' . $action,
        'actor' => $actor,
        'actorIsGuest' => $actorIsGuest,
        'action' => $action,
        'body' => feed_notifications_plain_text(is_string($plainBodySource) ? $plainBodySource : strip_tags($bodyHtml)),
        'bodyHtml' => $bodyHtml,
        'url' => $url,
        'date' => feed_notifications_iso_date($date),
    ];
}

function feed_notifications_inbox_state_path(): string {
    return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'notification-inbox-state.json';
}

function feed_notifications_inbox_identity(string $usernameKey, string $guestBrowserId, string $clientIp = ''): string {
    if ($usernameKey !== '') return 'account:' . $usernameKey;
    if ($guestBrowserId !== '') return 'guest:' . $guestBrowserId;
    if (filter_var($clientIp, FILTER_VALIDATE_IP)) return 'ip:' . $clientIp;
    return '';
}

function feed_notifications_load_inbox_state(): array {
    $path = feed_notifications_inbox_state_path();
    if (!is_file($path)) return ['identities' => []];
    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded)) return ['identities' => []];
    if (!isset($decoded['identities']) || !is_array($decoded['identities'])) $decoded['identities'] = [];
    return $decoded;
}

function feed_notifications_save_inbox_state(array $state): bool {
    $path = feed_notifications_inbox_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $saved = $encoded !== false && @file_put_contents($path, $encoded, LOCK_EX) !== false;
    if ($saved) fridg3_notification_revision_touch();
    return $saved;
}

function feed_notifications_inbox_result(string $identity, array $events): array {
    $state = feed_notifications_load_inbox_state();
    if (!isset($state['identities'][$identity]) || !is_array($state['identities'][$identity])) {
        $initialKeys = [];
        foreach ($events as $event) {
            if ((string)($event['type'] ?? '') === 'targeted') continue;
            $key = trim((string)($event['key'] ?? ''));
            if ($key !== '') $initialKeys[] = $key;
        }
        $state['identities'][$identity] = [
            'readKeys' => array_slice(array_values(array_unique($initialKeys)), -4000),
            'dismissedKeys' => [],
            'updatedAt' => gmdate('c'),
        ];
        feed_notifications_save_inbox_state($state);
    }
    $identityState = is_array($state['identities'][$identity] ?? null) ? $state['identities'][$identity] : [];
    $readKeys = array_fill_keys(array_values(array_filter(array_map('strval', (array)($identityState['readKeys'] ?? [])))), true);
    $dismissedKeys = array_fill_keys(array_values(array_filter(array_map('strval', (array)($identityState['dismissedKeys'] ?? [])))), true);
    $result = [];
    foreach (array_reverse($events) as $event) {
        $key = (string)($event['key'] ?? '');
        if ($key === '' || isset($dismissedKeys[$key])) continue;
        $event['unread'] = !isset($readKeys[$key]);
        $result[] = $event;
        if (count($result) >= 200) break;
    }
    return $result;
}

function feed_notifications_mark_inbox_read(string $identity, array $keys): bool {
    $state = feed_notifications_load_inbox_state();
    $identityState = is_array($state['identities'][$identity] ?? null) ? $state['identities'][$identity] : [];
    $read = array_fill_keys(array_values(array_filter(array_map('strval', (array)($identityState['readKeys'] ?? [])))), true);
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key !== '' && strlen($key) <= 300) $read[$key] = true;
    }
    $state['identities'][$identity] = [
        'readKeys' => array_slice(array_keys($read), -4000),
        'dismissedKeys' => array_slice(array_values(array_filter(array_map('strval', (array)($identityState['dismissedKeys'] ?? [])))), -4000),
        'updatedAt' => gmdate('c'),
    ];
    return feed_notifications_save_inbox_state($state);
}

function feed_notifications_dismiss_inbox(string $identity, array $keys): bool {
    $state = feed_notifications_load_inbox_state();
    $identityState = is_array($state['identities'][$identity] ?? null) ? $state['identities'][$identity] : [];
    $dismissed = array_fill_keys(array_values(array_filter(array_map('strval', (array)($identityState['dismissedKeys'] ?? [])))), true);
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key !== '' && strlen($key) <= 300) $dismissed[$key] = true;
    }
    $state['identities'][$identity] = [
        'readKeys' => array_slice(array_values(array_filter(array_map('strval', (array)($identityState['readKeys'] ?? [])))), -4000),
        'dismissedKeys' => array_slice(array_keys($dismissed), -4000),
        'updatedAt' => gmdate('c'),
    ];
    return feed_notifications_save_inbox_state($state);
}

$currentUsername = isset($_SESSION['user']['username']) ? ltrim((string)$_SESSION['user']['username'], '@') : '';
$currentUsernameKey = strtolower($currentUsername);
$guestBrowserId = fridg3_feed_normalize_guest_browser_id((string)($_GET['guestBrowserId'] ?? $_POST['guestBrowserId'] ?? ''));
$currentClientIp = fridg3_feed_client_ip();

$posts = feed_notifications_load_posts();
$accountsIndex = feed_notifications_accounts_index();
$events = [];

foreach ($posts as $postId => $post) {
    $postUrl = '/feed/posts/' . rawurlencode((string)$postId);
    $postAuthorKey = strtolower((string)$post['username']);

    if ($currentUsernameKey !== '') {
        foreach (feed_notifications_mentions((string)$post['body'], $accountsIndex) as $target) {
            $targetKey = strtolower((string)$target['username']);
            if ($targetKey !== $currentUsernameKey || $targetKey === $postAuthorKey) {
                continue;
            }
            $events[] = feed_notifications_event(
                'post:' . $postId . ':' . $currentUsernameKey,
                'feed',
                (string)$post['username'],
                false,
                'mentioned you in a feed post',
                (string)$post['body'],
                (string)$post['format'],
                $postUrl . '#post',
                (string)$post['date']
            );
        }
    }

    $replies = fridg3_feed_load_replies((string)$postId);
    $repliesById = [];
    foreach ($replies as $reply) {
        $replyId = (string)($reply['id'] ?? '');
        if ($replyId !== '') {
            $repliesById[$replyId] = $reply;
        }
    }

    foreach ($replies as $reply) {
        $replyId = (string)($reply['id'] ?? '');
        if ($replyId === '') {
            continue;
        }
        $replyUrl = $postUrl . '#reply-' . rawurlencode($replyId);
        $replyAuthor = (string)($reply['username'] ?? '');
        $replyAuthorKey = strtolower($replyAuthor);
        $parentId = (string)($reply['parentId'] ?? '');
        $parentReply = $parentId !== '' && isset($repliesById[$parentId]) ? $repliesById[$parentId] : null;
        $parentAuthorKey = is_array($parentReply) ? strtolower((string)($parentReply['username'] ?? '')) : '';

        if ($currentUsernameKey !== '') {
            foreach (feed_notifications_mentions((string)($reply['body'] ?? ''), $accountsIndex) as $target) {
                $targetKey = strtolower((string)$target['username']);
                if ($targetKey !== $currentUsernameKey || $targetKey === $replyAuthorKey || $targetKey === $postAuthorKey) {
                    continue;
                }
                $events[] = feed_notifications_event(
                    'reply:' . $postId . ':' . $replyId . ':' . $currentUsernameKey,
                    'feed',
                    $replyAuthor,
                    !empty($reply['isGuest']),
                    'mentioned you in a feed reply',
                    (string)($reply['body'] ?? ''),
                    (string)($reply['format'] ?? 'legacy'),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }

            if ($parentAuthorKey === $currentUsernameKey && $replyAuthorKey !== $currentUsernameKey) {
                $events[] = feed_notifications_event(
                    'comment-reply:' . $postId . ':' . $replyId . ':' . $currentUsernameKey,
                    'feed',
                    $replyAuthor,
                    !empty($reply['isGuest']),
                    'replied to your feed comment',
                    (string)($reply['body'] ?? ''),
                    (string)($reply['format'] ?? 'legacy'),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            } elseif ($postAuthorKey === $currentUsernameKey && $replyAuthorKey !== $currentUsernameKey) {
                $events[] = feed_notifications_event(
                    'post-reply:' . $postId . ':' . $replyId,
                    'feed',
                    $replyAuthor,
                    !empty($reply['isGuest']),
                    'replied to your feed post',
                    (string)($reply['body'] ?? ''),
                    (string)($reply['format'] ?? 'legacy'),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }
        }

        if ($guestBrowserId !== '') {
            $parentGuestBrowserId = is_array($parentReply) ? fridg3_feed_normalize_guest_browser_id((string)($parentReply['guestBrowserId'] ?? '')) : '';
            $replyGuestBrowserId = fridg3_feed_normalize_guest_browser_id((string)($reply['guestBrowserId'] ?? ''));
            if ($parentGuestBrowserId === $guestBrowserId && $replyGuestBrowserId !== $guestBrowserId) {
                $events[] = feed_notifications_event(
                    'guest-comment-reply:' . $postId . ':' . $replyId . ':' . $guestBrowserId,
                    'feed',
                    $replyAuthor,
                    !empty($reply['isGuest']),
                    'replied to your feed comment',
                    (string)($reply['body'] ?? ''),
                    (string)($reply['format'] ?? 'legacy'),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }
        }
    }
}

usort($events, static function (array $a, array $b): int {
    return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
});

if ($currentUsernameKey === '') {
    $events = array_values(array_filter($events, static fn(array $event): bool => str_starts_with((string)($event['key'] ?? ''), 'guest-comment-reply:')));
}

foreach (fridg3_targeted_notifications_load() as $targeted) {
    $targetType = (string)($targeted['targetType'] ?? '');
    $target = strtolower((string)($targeted['target'] ?? ''));
    if (!(($targetType === 'user' && $currentUsernameKey !== '' && $target === $currentUsernameKey)
        || ($targetType === 'ip' && $target === strtolower($currentClientIp))
        || ($targetType === 'audience' && $target === 'users' && $currentUsernameKey !== '')
        || ($targetType === 'audience' && $target === 'guests' && $currentUsernameKey === ''))) continue;
    $message = (string)($targeted['message'] ?? '');
    $messageHtml = fridg3_feed_render_post_body($message, 'v2');
    $events[] = [
        'key' => 'targeted:' . (string)($targeted['id'] ?? ''), 'type' => 'targeted',
        'title' => (string)($targeted['title'] ?? 'notification'), 'actor' => '', 'actorIsGuest' => false, 'action' => '',
        'body' => feed_notifications_plain_text(strip_tags($messageHtml)), 'bodyHtml' => $messageHtml,
        'url' => (string)($targeted['url'] ?? '/notifications'), 'date' => feed_notifications_iso_date((string)($targeted['date'] ?? '')),
    ];
}

$ipRestriction = fridg3_feed_banned_ip_record($currentClientIp);
if ($ipRestriction !== null) {
    $reason = trim((string)($ipRestriction['reason'] ?? ''));
    $notificationId = trim((string)($ipRestriction['notificationId'] ?? ''));
    if ($notificationId === '') {
        $notificationId = hash('sha256', $currentClientIp . "\0" . (string)($ipRestriction['bannedAt'] ?? 'legacy'));
    }
    $message = ($reason !== '' ? 'Reason: ' . $reason . "\n\n" : '')
        . 'Contact me at ashton@fridge.dev if you think this was in error.';
    $messageHtml = $reason !== ''
        ? '<strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '<br><br>'
            . 'Contact me at ashton@fridge.dev if you think this was in error.'
        : 'Contact me at ashton@fridge.dev if you think this was in error.';
    $events[] = [
        'key' => 'ip-restriction:' . $notificationId,
        'type' => 'targeted',
        'title' => 'Your IP address has been restricted from uploading content to the website',
        'actor' => '',
        'actorIsGuest' => false,
        'action' => '',
        'body' => $message,
        'bodyHtml' => $messageHtml,
        'url' => 'mailto:ashton@fridge.dev',
        'date' => feed_notifications_iso_date((string)($ipRestriction['bannedAt'] ?? '')),
    ];
}

$inboxRequested = (string)($_GET['view'] ?? $_POST['view'] ?? '') === 'inbox';
if ($inboxRequested) {
    $identity = feed_notifications_inbox_identity($currentUsernameKey, $guestBrowserId, $currentClientIp);
    if ($identity === '') {
        echo json_encode(['ok' => true, 'events' => [], 'unreadCount' => 0, 'loggedIn' => false]);
        exit;
    }
    if ($currentUsernameKey !== '' && empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($requestMethod === 'POST') {
        if ($currentUsernameKey !== '') {
            $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
            $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
            if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
                exit;
            }
        } elseif (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_request']);
            exit;
        }
        $markAll = isset($_POST['markAll']) && in_array(strtolower((string)$_POST['markAll']), ['1', 'true', 'yes'], true);
        $dismissAll = isset($_POST['dismissAll']) && in_array(strtolower((string)$_POST['dismissAll']), ['1', 'true', 'yes'], true);
        $dismiss = isset($_POST['dismiss']) && in_array(strtolower((string)$_POST['dismiss']), ['1', 'true', 'yes'], true);
        $keys = ($markAll || $dismissAll) ? array_column($events, 'key') : ($_POST['keys'] ?? []);
        if (is_string($keys)) {
            $decodedKeys = json_decode($keys, true);
            $keys = is_array($decodedKeys) ? $decodedKeys : [];
        }
        $saved = is_array($keys) && (($dismiss || $dismissAll)
            ? feed_notifications_dismiss_inbox($identity, $keys)
            : feed_notifications_mark_inbox_read($identity, $keys));
        if (!$saved) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'state_write_failed']);
            exit;
        }
    }

    $allInboxEvents = feed_notifications_inbox_result($identity, $events);
    $unreadCount = count(array_filter($allInboxEvents, static fn(array $event): bool => !empty($event['unread'])));
    $perPage = 10;
    $totalEvents = count($allInboxEvents);
    $totalPages = max(1, (int)ceil($totalEvents / $perPage));
    $requestedPage = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
    $currentPage = min($requestedPage, $totalPages);
    $inboxEvents = array_slice($allInboxEvents, ($currentPage - 1) * $perPage, $perPage);
    echo json_encode([
        'ok' => true,
        'events' => $inboxEvents,
        'unreadCount' => $unreadCount,
        'loggedIn' => $currentUsernameKey !== '',
        'csrfToken' => $currentUsernameKey !== '' ? (string)($_SESSION['csrf_token'] ?? '') : '',
        'pagination' => [
            'page' => $currentPage,
            'perPage' => $perPage,
            'totalEvents' => $totalEvents,
            'totalPages' => $totalPages,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'inbox_view_required']);
exit;
