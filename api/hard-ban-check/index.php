<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';

header('Cache-Control: no-store, private');
$identifier = (string)($_COOKIE[FRIDG3_HARD_BAN_COOKIE] ?? '');
http_response_code(fridg3_hard_ban_check_client(fridg3_hard_ban_client_ip(), $identifier) ? 401 : 204);
