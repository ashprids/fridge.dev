<?php
declare(strict_types=1);

define('FRIDGE_SKIP_ACCESS_LOG', true);
// This script runs as an nginx auth_request subrequest with the visitor's
// REQUEST_URI. It must answer only 204 or 401: any other status (including a
// redirect) makes nginx fail the visitor's request with a 500.
define('FRIDGE_SKIP_CANONICAL_REDIRECT', true);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';

header('Cache-Control: no-store, private');
try {
    if (!fridge_hard_ban_enforcement_enabled()) {
        http_response_code(204);
        exit;
    }
    fridge_start_session(false);
    // The checker only reads the session; release its lock so the page
    // request that follows is not serialized behind this subrequest.
    session_write_close();
    if (!empty($_SESSION['user']['isAdmin']) || !empty($_SESSION['user']['isModerator'])) {
        http_response_code(204);
        exit;
    }
    $identifier = (string)($_COOKIE[FRIDGE_HARD_BAN_COOKIE] ?? '');
    http_response_code(fridge_hard_ban_check_client(fridge_hard_ban_client_ip(), $identifier) ? 401 : 204);
} catch (Throwable $error) {
    // Fail open: a checker fault must not turn every page into a 500.
    error_log('[hard-ban-check] ' . $error->getMessage());
    header_remove('Location');
    http_response_code(204);
}
