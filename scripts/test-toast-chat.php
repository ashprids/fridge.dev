<?php
declare(strict_types=1);

$temp = sys_get_temp_dir() . '/fridg3-toast-chat-test-' . bin2hex(random_bytes(5));
putenv('FRIDG3_TOAST_CHAT_DATA_DIR=' . $temp);
require dirname(__DIR__) . '/lib/toast-chat.php';

function toastTest(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function toastTestRemove(string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

try {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    $empty = toast_chat_load_or_create(toast_chat_identity(false));
    toast_chat_payload($empty);
    toastTest(!file_exists($temp), 'viewing an empty chat created storage');
    $identity = ['id' => 'ip-' . str_repeat('a', 64), 'type' => 'ip', 'value' => '203.0.113.42', 'label' => '203.0.113.42'];
    $chat = toast_chat_new_conversation($identity);
    $chat['messages'] = [
        ['id' => str_repeat('b', 16), 'sender' => 'visitor', 'body' => 'before "quotes" and 💾', 'createdAt' => time() - 4, 'visibleAt' => time() - 4],
        ['id' => str_repeat('c', 16), 'sender' => 'visitor', 'body' => '/clearmemory', 'memoryBoundary' => true, 'createdAt' => time() - 3, 'visibleAt' => time() - 3],
        ['id' => str_repeat('d', 16), 'sender' => 'visitor', 'body' => "after\nline", 'createdAt' => time() - 2, 'visibleAt' => time() - 2],
        ['id' => str_repeat('e', 16), 'sender' => 'toast', 'body' => 'future', 'createdAt' => time() + 60, 'visibleAt' => time() + 60],
    ];
    $chat['dailyCounts'] = [toast_chat_day_key() => 99];
    toastTest(toast_chat_write($chat), 'conversation write failed');
    $raw = (string)file_get_contents(toast_chat_path($identity['id']));
    toastTest(!str_contains($raw, '203.0.113.42') && !str_contains($raw, 'before'), 'encrypted file leaked plaintext');
    $loaded = toast_chat_read($identity['id']);
    toastTest(is_array($loaded) && $loaded['messages'][0]['body'] === 'before "quotes" and 💾', 'encrypted JSON did not round-trip');
    toastTest(toast_chat_daily_count($loaded) === 99, 'daily user-message count failed');
    $context = toast_chat_context($loaded);
    toastTest(count($context) === 1 && $context[0]['content'] === "after\nline", 'memory boundary or future-message filtering failed');
    $payload = toast_chat_payload($loaded);
    toastTest($payload['count'] === 3 && $payload['sendBlocked'] === false, 'visible-message payload failed');
    toastTest(str_contains($payload['html'], 'after<br>') && !str_contains($payload['html'], '>future<'), 'message HTML filtering failed');
    $loaded['dailyCounts'][toast_chat_day_key()] = 100;
    toastTest(toast_chat_payload($loaded)['sendBlocked'] === true, '100-message daily limit did not block sending');
    toastTest(toast_chat_emoji('👍') === '👍' && toast_chat_emoji('not emoji') === '', 'reaction validation failed');
    $before = $loaded['messages'];
    $loaded['pendingToken'] = 'in-flight'; $loaded['pendingUntil'] = time() + 60;
    toast_chat_clear_for_visitor($loaded);
    toastTest($loaded['messages'] === $before, 'clear removed admin history');
    toastTest(toast_chat_visible_messages($loaded) === [] && toast_chat_context($loaded) === [], 'clear left visitor or AI history');
    toastTest(str_contains(toast_chat_messages_html($loaded, true), 'before'), 'clear hid admin history');
    toastTest(toast_chat_daily_count($loaded) === 100 && !toast_chat_is_pending($loaded) && $loaded['pendingToken'] === '', 'clear reset quota or left pending reply');
    $loaded['messages'][] = ['id'=>str_repeat('f',16),'sender'=>'visitor','body'=>'fresh start','createdAt'=>time(),'visibleAt'=>time()];
    toastTest(count(toast_chat_context($loaded)) === 1 && count(toast_chat_visible_messages($loaded)) === 1, 'new messages after clear were not isolated');
    toastTest(toast_chat_write($loaded) && count(toast_chat_visible_messages(toast_chat_read($identity['id']))) === 1, 'clear boundary did not persist');
    $attachment = toast_chat_encrypt_attachment($identity['id'], 'test image data');
    toastTest(is_array($attachment), 'attachment storage failed');
    toastTest(toast_chat_delete($identity['id']), 'conversation deletion failed');
    toastTest(toast_chat_read($identity['id']) === null && !is_dir(toast_chat_attachment_dir($identity['id'])), 'deleted chat or images remain');
    toast_chat_load_or_create($identity);
    toastTest(!is_file(toast_chat_path($identity['id'])), 'read recreated deleted chat');
    toastTest(!toast_chat_delete('../escape'), 'invalid deletion ID accepted');
    echo "toast chat tests passed\n";
} finally {
    toastTestRemove($temp);
}
