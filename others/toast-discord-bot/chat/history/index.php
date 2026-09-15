<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/lib/session.php';
require_once $root . '/lib/feed.php';
require_once $root . '/lib/render.php';
require_once $root . '/lib/video-embeds.php';
require_once $root . '/lib/toast-chat.php';
fridge_start_session();
fridge_feed_refresh_session_user();

if (!fridge_current_user_is_admin() && !fridge_toast_is_current_user()) {
    header('Location: /error/403', true, 302);
    exit;
}

function toast_history_template(string $content): void {
    $name = get_preferred_template_name(__DIR__);
    $dir = __DIR__; $template = null;
    while (dirname($dir) !== $dir) {
        $candidate = $dir . '/' . $name;
        if (is_file($candidate)) { $template = $candidate; break; }
        $dir = dirname($dir);
    }
    if (!$template) die('page template not found.');
    $html = apply_preferred_theme_stylesheet((string)file_get_contents($template), __DIR__);
    $greeting = isset($_SESSION['user']['name']) ? '<div id="user-greeting">Hello, ' . toast_chat_h((string)$_SESSION['user']['name']) . '!</div>' : '';
    echo str_replace(['{content}', '{title}', '{description}', '{user_greeting}'], [$content, 'Toast website chat history', 'Toast website chat history for admins and Toast.', $greeting], $html);
}

function toast_history_pagination(int $currentPage, int $totalPages, string $searchQuery): string {
    if ($totalPages <= 1) {
        return '';
    }
    $query = $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '';
    $pageUrl = static fn(int $page): string => '/others/toast-discord-bot/chat/history?page=' . $page . $query . '#content-footer';
    $items = $currentPage > 1
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . $pageUrl($currentPage - 1) . '" aria-label="previous page">&lsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&lsaquo;</span>';
    $pages = array_unique(array_filter([1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages], static fn(int $page): bool => $page >= 1 && $page <= $totalPages));
    sort($pages);
    $previous = 0;
    foreach ($pages as $i) {
        if ($previous > 0 && $i - $previous > 1) $items .= '<span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>';
        $isCurrent = $i === $currentPage;
        $class = 'guestbook-page-btn' . ($isCurrent ? ' current' : '');
        $aria = $isCurrent ? ' aria-current="page"' : '';
        if ($isCurrent) {
            $items .= '<span class="' . $class . '"' . $aria . '>' . $i . '</span>';
        } else {
            $items .= '<a class="' . $class . '" href="' . $pageUrl($i) . '" aria-label="page ' . $i . '">' . $i . '</a>';
        }
        $previous = $i;
    }
    $items .= $currentPage < $totalPages
        ? '<a class="guestbook-page-btn pagination-arrow" href="' . $pageUrl($currentPage + 1) . '" aria-label="next page">&rsaquo;</a>'
        : '<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">&rsaquo;</span>';
    return '<nav class="guestbook-pagination content-pagination" aria-label="history pages" data-pagination-route="/others/toast-discord-bot/chat/history" data-pagination-current="' . $currentPage . '" data-pagination-total="' . $totalPages . '" data-pagination-search="' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . '">' . $items . '</nav>';
}

$route = '/others/toast-discord-bot/chat/history';
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
$_SESSION['toast_history_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['toast_history_csrf'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { http_response_code(403); exit('Invalid request token.'); }
    if (($_POST['action'] ?? '') !== 'delete' || !toast_chat_delete((string)($_POST['chat'] ?? ''))) { http_response_code(400); exit('Conversation could not be deleted.'); }
    header('Location: ' . $route . '?' . http_build_query(['q' => $q, 'page' => $page]), true, 303); exit;
}
$selectedId = strtolower(trim((string)($_GET['chat'] ?? '')));
if (($_GET['action'] ?? '') === 'attachment') {
    if (!toast_chat_valid_id($selectedId)) { http_response_code(404); exit; }
    $attachmentId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['file'] ?? '')));
    $chat = toast_chat_read($selectedId); $found = false;
    foreach ((array)($chat['messages'] ?? []) as $message) {
        if (empty($message['deletedAt']) && (string)(($message['attachment'] ?? [])['id'] ?? '') === $attachmentId) { $found = true; break; }
    }
    $attachment = $found ? toast_chat_load_attachment($selectedId, $attachmentId) : null;
    if (!$attachment) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen((string)$attachment['data']));
    header('Content-Disposition: inline; filename="image.jpg"');
    header('X-Content-Type-Options: nosniff');
    echo $attachment['data']; exit;
}

$conversations = [];
foreach (glob(toast_chat_data_dir() . '/{account,ip}-*.json', GLOB_BRACE) ?: [] as $path) {
    $id = basename($path, '.json');
    $chat = toast_chat_read($id);
    if (is_array($chat) && !empty($chat['messages'])) $conversations[] = $chat;
}
usort($conversations, static fn(array $a, array $b): int => (int)($b['updatedAt'] ?? 0) <=> (int)($a['updatedAt'] ?? 0));

$selected = null;
foreach ($conversations as $chat) if ((string)($chat['id'] ?? '') === $selectedId) { $selected = $chat; break; }
$conversations = array_values(array_filter($conversations, static fn(array $chat): bool => $q === '' || stripos((string)($chat['identityLabel'] ?? '') . ' ' . (string)($chat['identityValue'] ?? ''), $q) !== false));
$totalPages = max(1, (int)ceil(count($conversations) / 10));
$page = min($page, $totalPages);
$conversations = array_slice($conversations, ($page - 1) * 10, 10);
$style = '<style>.toast-chat-history-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:18px}.toast-chat-history-list{display:grid;gap:8px;align-content:start}.toast-chat-history-card{display:block;padding:12px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:inherit}.toast-chat-history-card[aria-current="page"]{background:var(--accent);color:var(--accent_text,#fff)}.toast-chat-history-card span{display:block;font-size:.82em;opacity:.8;margin-top:4px}.toast-history-conversation{--chat-bg:var(--bg);--chat-fg:var(--fg);--chat-border:var(--border);--chat-muted:var(--subtle);--chat-own-bg:#245856;--chat-own-fg:#fff;--chat-other-bg:color-mix(in srgb,var(--bg) 82%,var(--fg));--chat-other-fg:var(--fg);position:relative;display:flex;flex-direction:column;min-width:0;box-sizing:border-box;overflow:hidden;height:540px;border:1px solid var(--border);padding:16px;border-radius:4px}.toast-history-conversation .chat-messages-wrap{flex:1;min-height:0;overflow:hidden}.toast-history-conversation .chat-messages{box-sizing:border-box;height:100%;min-height:0!important;max-height:none!important;overflow:auto}.toast-history-conversation .chat-messages>*{flex-shrink:0}.toast-history-conversation .chat-header-row{flex-shrink:0;margin-bottom:16px}.toast-history-conversation h2{margin:0 0 8px}.toast-history-conversation .chat-presence{margin:0}.toast-history-search{display:flex;gap:8px;margin-bottom:18px}.toast-history-search input{flex:1;min-width:0}.toast-history-search input,.toast-history-search button,.toast-history-delete button{font:inherit;padding:8px 12px;color:var(--fg);background:var(--bg);border:1px solid var(--border);border-radius:3px}.toast-history-delete{margin-bottom:12px}.toast-chat-history-empty{padding:24px;border:1px solid var(--border);border-radius:4px}@media(max-width:720px){.toast-chat-history-layout{grid-template-columns:1fr}.toast-history-conversation{height:65vh}}</style>';
$list = '<div class="toast-chat-history-list">';
foreach ($conversations as $chat) {
    $chatId = (string)$chat['id'];
    $label = (string)($chat['identityLabel'] ?? $chatId);
    $messageCount = count((array)($chat['messages'] ?? []));
    $list .= '<a class="toast-chat-history-card" href="/others/toast-discord-bot/chat/history?chat=' . rawurlencode($chatId) . '&amp;q=' . rawurlencode($q) . '&amp;page=' . $page . '"' . ($chatId === $selectedId ? ' aria-current="page"' : '') . '><strong>' . toast_chat_h($label) . '</strong><span>' . $messageCount . ' messages · ' . toast_chat_h(date('j M Y, H:i', (int)($chat['updatedAt'] ?? 0))) . '</span></a>';
}
if ($conversations === []) $list .= $q !== '' ? '<p>No conversations match your search.</p>' : '<p>No website chats have been stored yet.</p>';
$list .= '</div>';
$detail = '<div class="toast-chat-history-empty">Select a conversation to view its history.</div>';
if (is_array($selected)) {
    $base = '/others/toast-discord-bot/chat/history?chat=' . rawurlencode((string)$selected['id']);
    $deleteForm = '<form class="toast-history-delete" method="post" action="' . $route . '/" data-no-spa="1" data-site-confirm data-confirm-title="delete conversation?" data-confirm-detail="This permanently deletes the conversation and its images." data-confirm-text="delete"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="' . toast_chat_h($csrf) . '"><input type="hidden" name="chat" value="' . toast_chat_h($selectedId) . '"><input type="hidden" name="q" value="' . toast_chat_h($q) . '"><input type="hidden" name="page" value="' . $page . '"><button type="submit" class="danger-button">delete conversation</button></form>';
    $detail = $deleteForm . '<section class="toast-history-conversation"><div class="chat-header-row"><div class="chat-header-main"><h2>' . toast_chat_h((string)($selected['identityLabel'] ?? 'conversation')) . '</h2><div class="chat-presence">read-only history</div></div></div><div class="chat-messages-wrap"><div class="chat-messages" id="chat-messages">' . toast_chat_messages_html($selected, true, $base) . '</div></div></section>';
}

$search = '<form class="toast-history-search" method="get" action="' . $route . '"><input type="search" name="q" value="' . toast_chat_h($q) . '" placeholder="Search IP or username" aria-label="Search IP or username"><button type="submit">search</button></form>';
toast_history_template($style . '<h1>Toast website chat history</h1><p><a href="/settings">settings</a></p><div class="toast-chat-history-layout">' . $search . $list . toast_history_pagination($page, $totalPages, $q) . '<div class="toast-chat-history-detail">' . $detail . '</div></div>');
