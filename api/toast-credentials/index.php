<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
fridg3_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');
function toast_credentials_response(array $data, int $status = 200): never {
    http_response_code($status); echo json_encode($data); exit;
}
if (!fridg3_toast_is_current_user()) toast_credentials_response(['ok'=>false,'error'=>'Toast access required.'],403);
$_SESSION['toast_credentials_csrf'] ??= bin2hex(random_bytes(32));
$path = dirname(__DIR__, 2) . '/data/etc/toast.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $config = json_decode((string)@file_get_contents($path),true);
    toast_credentials_response(['ok'=>true,'csrf'=>$_SESSION['toast_credentials_csrf'],'configured'=>!empty($config['groq']['api_key'])]);
}
if ($method !== 'POST') toast_credentials_response(['ok'=>false,'error'=>'Method not allowed.'],405);
$input = json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input) || !is_string($input['csrf']??null) || !hash_equals($_SESSION['toast_credentials_csrf'],$input['csrf'])) toast_credentials_response(['ok'=>false,'error'=>'Invalid request token.'],403);
$key = $input['apiKey'] ?? null;
if (!is_string($key) || !preg_match('/^[\x21-\x7e]{1,512}$/D', trim($key))) toast_credentials_response(['ok'=>false,'error'=>'Enter a valid API key.'],400);
$lock = @fopen($path.'.models.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) toast_credentials_response(['ok'=>false,'error'=>'Configuration is unavailable.'],500);
try {
    $config = json_decode((string)@file_get_contents($path),true);
    if (!is_array($config)) throw new RuntimeException();
    $config['groq']['api_key'] = trim($key);
    $temporary = tempnam(dirname($path),'.toast-key-');
    if (!$temporary) throw new RuntimeException();
    try {
        if (!rename($temporary, $temporary . '.json')) throw new RuntimeException();
        $temporary .= '.json';
        if (file_put_contents($temporary,json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false) throw new RuntimeException();
        chmod($temporary,fileperms($path)&0777);
        if (!rename($temporary,$path)) throw new RuntimeException();
    } finally { if (is_file($temporary)) unlink($temporary); }
} catch (Throwable $e) { toast_credentials_response(['ok'=>false,'error'=>'Could not save API key.'],500); }
finally { flock($lock,LOCK_UN); fclose($lock); }
toast_credentials_response(['ok'=>true]);
