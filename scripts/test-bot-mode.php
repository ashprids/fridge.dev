<?php
require dirname(__DIR__) . '/lib/bot-mode.php';
function botCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['/', '/index.php', '/feed/posts/index.php?id=12', '/settings/', '/account/logout', '/others', '/others/toast-discord-bot', '/others/toast-discord-bot/messages/', '/others/toast-discord-bot/chat/history/index.php', '/api/debug-process-logs', '/api/settings/', '/api/toast-models', '/api/stream-proxy/?u=radio'] as $path) {
    botCheck(fridge_bot_mode_path_allowed($path), 'blocked required path: ' . $path);
}
foreach (['/contact', '/chat', '/notifications', '/bookmarks', '/others/fridge-builds-websites', '/others/toast-discord-bot/chat/', '/others/toast-discord-bot/chat/index.php?action=send', '/others/toast-discord-bot/chat/history/extra', '/feed/../contact', '/feed/%2e%2e/contact', '/feed-other', '/api/bookmark', '/api/gallery/delete'] as $path) {
    botCheck(!fridge_bot_mode_path_allowed($path), 'allowed restricted path: ' . $path);
}
foreach ([[], ['username'=>'toast'], ['username'=>'visitor','isHardcodedToast'=>true]] as $user) {
    $_SESSION['user'] = $user;
    botCheck(!fridge_toast_is_current_user(), 'incorrect identity enabled bot mode');
}
$_SESSION['user'] = fridge_toast_session_user();
foreach (['template.html', 'template_mobile.html', 'themes/lib/nord/theme.html', 'themes/lib/gruvbox/theme.html'] as $file) {
    $html = fridge_bot_mode_template(file_get_contents(dirname(__DIR__) . '/' . $file));
    botCheck(str_contains($html, 'id="bot-mode-banner"'), 'missing banner: ' . $file);
    botCheck((bool)preg_match('/<body[^>]*class="bot-mode(?: |")/', $html), 'missing mode class');
    botCheck((bool)preg_match('~<a[^>]*href="/contact/"[^>]*aria-disabled="true"~', $html), 'contact is not disabled');
    botCheck(!(bool)preg_match('~<a[^>]*href="/feed/"[^>]*aria-disabled~', $html), 'feed is disabled');
}
echo "Bot mode identity, path policy, and desktop/mobile shell checks passed.\n";
