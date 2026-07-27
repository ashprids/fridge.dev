<?php
declare(strict_types=1);

define('FRIDG3_SKIP_ACCESS_LOG', true);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';

header('Cache-Control: no-store, private');
if (!fridg3_hard_ban_enforcement_enabled()) {
    http_response_code(204);
    exit;
}
fridg3_start_session(false);
if (isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true) {
    http_response_code(204);
    exit;
}
$identifier = (string)($_COOKIE[FRIDG3_HARD_BAN_COOKIE] ?? '');
http_response_code(fridg3_hard_ban_check_client(fridg3_hard_ban_client_ip(), $identifier) ? 401 : 204);
