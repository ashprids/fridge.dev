<?php
declare(strict_types=1);

define('FRIDGE_SKIP_ACCESS_LOG', true);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';

header('Cache-Control: no-store, private');
if (!fridge_hard_ban_enforcement_enabled()) {
    http_response_code(204);
    exit;
}
fridge_start_session(false);
if (!empty($_SESSION['user']['isAdmin']) || !empty($_SESSION['user']['isModerator'])) {
    http_response_code(204);
    exit;
}
$identifier = (string)($_COOKIE[FRIDGE_HARD_BAN_COOKIE] ?? '');
http_response_code(fridge_hard_ban_check_client(fridge_hard_ban_client_ip(), $identifier) ? 401 : 204);
