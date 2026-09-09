<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/feed.php';
fridg3_start_session();
fridg3_feed_refresh_session_user();
header('Content-Type: application/json');
header('Cache-Control: no-store');
function toast_radio_response(array $data, int $status = 200): never {
    http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}
if (empty($_SESSION['user']['isAdmin']) && !fridg3_toast_is_current_user()) toast_radio_response(['ok'=>false, 'error'=>'Admin or Toast access required.'],403);
$_SESSION['toast_radio_csrf'] ??= bin2hex(random_bytes(32));
$path = dirname(__DIR__) . '/data/etc/toast.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && !$statusOnly) {
    $config = json_decode((string)@file_get_contents($path), true);
    if (!is_array($config)) toast_radio_response(['ok'=>false,'error'=>'Configuration is unavailable.'],500);
    toast_radio_response(['ok'=>true,'csrf'=>$_SESSION['toast_radio_csrf'],'stream'=>['name'=>$config['stream']['name']??'', 'url'=>$config['stream']['url']??''], 'status'=>$config['bot']['status']??'offline']);
}
if ($method !== 'POST') toast_radio_response(['ok'=>false,'error'=>'Method not allowed.'],405);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !is_string($input['csrf']??null) || !hash_equals($_SESSION['toast_radio_csrf'],$input['csrf'])) toast_radio_response(['ok'=>false,'error'=>'Invalid request token.'],403);
$status = $input['status'] ?? null;
if (is_bool($status)) $status = $status ? 'online' : 'offline';
if (($statusOnly || $status !== null) && !in_array($status,['online','offline'],true)) toast_radio_response(['ok'=>false,'error'=>'Invalid status.'],400);
if (!$statusOnly) {
    $url = is_string($input['url']??null) ? trim($input['url']) : '';
    $name = is_string($input['name']??null) ? trim($input['name']) : '';
    if ($name === '' || strlen($name)>200 || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) toast_radio_response(['ok'=>false,'error'=>'Enter a stream name and a valid HTTP(S) URL.'],400);
}
$lock = @fopen($path . '.models.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) toast_radio_response(['ok'=>false,'error'=>'Configuration is unavailable.'],500);
try {
    $config = json_decode((string)file_get_contents($path),true);
    if (!is_array($config)) throw new RuntimeException();
    if (!$statusOnly) { $config['stream']['url']=$url; $config['stream']['name']=$name; }
    if ($status !== null) $config['bot']['status']=$status;
    $tmp = tempnam(dirname($path),'.toast-radio-');
    if (!$tmp) throw new RuntimeException();
    try {
        if (!rename($tmp, $tmp . '.json')) throw new RuntimeException();
        $tmp .= '.json';
        if (file_put_contents($tmp,json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false) throw new RuntimeException();
        chmod($tmp,fileperms($path)&0777);
        if (!rename($tmp,$path)) throw new RuntimeException();
    } finally { if (is_file($tmp)) unlink($tmp); }
    if (!$statusOnly && file_put_contents(dirname($path).'/.stream-update-signal',(string)time())===false) throw new RuntimeException();
} catch (Throwable $e) { toast_radio_response(['ok'=>false,'error'=>'Could not save or reload the radio configuration.'],500); }
finally { flock($lock,LOCK_UN); fclose($lock); }
toast_radio_response(['ok'=>true]);
