<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
        $username = isset($lines[0]) ? ltrim(trim((string)$lines[0]), '@') : '';
        $date = isset($lines[1]) ? trim((string)$lines[1]) : '';
        $body = count($lines) > 2 ? implode("\n", array_slice($lines, 2)) : '';
        $postId = pathinfo((string)$path, PATHINFO_FILENAME);
        if ($postId === '' || $username === '' || $date === '') {
            continue;
        }
        $posts[$postId] = [
            'id' => $postId,
            'username' => $username,
            'date' => $date,
            'body' => $body,
        ];
    }

    return $posts;
}

function feed_notifications_load_journal_posts(): array {
    $journalDir = fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
    $posts = [];
    $files = glob($journalDir . DIRECTORY_SEPARATOR . '*.txt');
    if ($files === false) {
        return $posts;
    }

    foreach ($files as $path) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 3) {
            continue;
        }
        $postId = pathinfo((string)$path, PATHINFO_FILENAME);
        $date = trim((string)($lines[0] ?? ''));
        $title = trim((string)($lines[1] ?? ''));
        $description = trim((string)($lines[2] ?? ''));
        if ($postId === '' || $date === '' || $title === '') {
            continue;
        }
        $posts[$postId] = [
            'id' => $postId,
            'date' => $date,
            'title' => $title,
            'description' => $description,
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
            'browserNotificationsEnabled' => !empty($account['browserNotificationsEnabled']),
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

function feed_notifications_event(string $key, string $type, string $title, string $body, string $url, string $date): array {
    return [
        'key' => $key,
        'type' => $type,
        'title' => $title,
        'body' => feed_notifications_plain_text($body),
        'url' => $url,
        'date' => $date,
    ];
}

$currentUsername = isset($_SESSION['user']['username']) ? ltrim((string)$_SESSION['user']['username'], '@') : '';
$currentUsernameKey = strtolower($currentUsername);
$guestBrowserId = fridg3_feed_normalize_guest_browser_id((string)($_GET['guestBrowserId'] ?? ''));

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
                '@' . $post['username'] . ' mentioned you in a feed post',
                (string)$post['body'],
                $postUrl,
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

        if ($currentUsernameKey !== '') {
            foreach (feed_notifications_mentions((string)($reply['body'] ?? ''), $accountsIndex) as $target) {
                $targetKey = strtolower((string)$target['username']);
                if ($targetKey !== $currentUsernameKey || $targetKey === $replyAuthorKey || $targetKey === $postAuthorKey) {
                    continue;
                }
                $events[] = feed_notifications_event(
                    'reply:' . $postId . ':' . $replyId . ':' . $currentUsernameKey,
                    'feed',
                    '@' . $replyAuthor . ' mentioned you in a feed reply',
                    (string)($reply['body'] ?? ''),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }

            if ($postAuthorKey === $currentUsernameKey && $replyAuthorKey !== $currentUsernameKey) {
                $events[] = feed_notifications_event(
                    'post-reply:' . $postId . ':' . $replyId,
                    'feed',
                    '@' . $replyAuthor . ' replied to your feed post',
                    (string)($reply['body'] ?? ''),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }
        }

        if ($guestBrowserId !== '') {
            $parentId = (string)($reply['parentId'] ?? '');
            $parentReply = $parentId !== '' && isset($repliesById[$parentId]) ? $repliesById[$parentId] : null;
            $parentGuestBrowserId = is_array($parentReply) ? fridg3_feed_normalize_guest_browser_id((string)($parentReply['guestBrowserId'] ?? '')) : '';
            $replyGuestBrowserId = fridg3_feed_normalize_guest_browser_id((string)($reply['guestBrowserId'] ?? ''));
            if ($parentGuestBrowserId === $guestBrowserId && $replyGuestBrowserId !== $guestBrowserId) {
                $events[] = feed_notifications_event(
                    'guest-comment-reply:' . $postId . ':' . $replyId . ':' . $guestBrowserId,
                    'feed',
                    $replyAuthor . ' replied to your feed comment',
                    (string)($reply['body'] ?? ''),
                    $replyUrl,
                    (string)($reply['date'] ?? '')
                );
            }
        }
    }
}

foreach (feed_notifications_load_journal_posts() as $postId => $post) {
    $events[] = feed_notifications_event(
        'journal:' . $postId,
        'journal',
        'New journal post: ' . (string)$post['title'],
        (string)($post['description'] ?? ''),
        '/journal/posts/' . rawurlencode((string)$postId),
        (string)$post['date']
    );
}

usort($events, static function (array $a, array $b): int {
    return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
});

echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_SLASHES);
exit;
