<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'hard-ban.php';

$clientIp = fridg3_hard_ban_client_ip();
$identifier = (string)($_COOKIE[FRIDG3_HARD_BAN_COOKIE] ?? '');
if (!fridg3_hard_ban_check_client($clientIp, $identifier)) {
    header('Location: /', true, 302);
    exit;
}

http_response_code(403);
header('Cache-Control: no-store, private');

if (fridg3_hard_ban_contains($clientIp)) {
    if (!fridg3_hard_ban_valid_identifier($identifier)) {
        $identifier = bin2hex(random_bytes(32));
    }
    fridg3_hard_ban_register_identifier($clientIp, $identifier);

    $cookieOptions = [
        'expires' => time() + (86400 * 365 * 5),
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    if ($hostName = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')))) {
        if ($hostName === 'fridge.dev' || $hostName === 'm.fridge.dev' || str_ends_with($hostName, '.fridge.dev')) {
            $cookieOptions['domain'] = '.fridge.dev';
        }
    }
    setcookie(FRIDG3_HARD_BAN_COOKIE, $identifier, $cookieOptions);
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host);
$mobileCookie = strtolower(trim((string)($_COOKIE['mobile_friendly_view'] ?? '')));
$useMobile = $host === 'm.fridge.dev' || in_array($mobileCookie, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true);
$templatePath = __DIR__ . DIRECTORY_SEPARATOR . ($useMobile ? 'template_mobile.html' : 'template.html');
$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';

if (!is_file($templatePath) || !is_file($contentPath)) {
    echo 'access denied.';
    exit;
}

$html = (string)file_get_contents($templatePath);
$html = str_replace('{content}', (string)file_get_contents($contentPath), $html);
$html = str_replace('{title}', 'access denied', $html);
$html = str_replace('{description}', 'this client is not permitted to access fridge.dev.', $html);
$html = str_replace('{hard_ban_identifier}', json_encode($identifier, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
echo $html;
