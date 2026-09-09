<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/feed.php';
require_once dirname(__DIR__, 2) . '/lib/toast-models.php';
fridg3_start_session();
fridg3_feed_refresh_session_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function toast_models_response(array $data, int $status = 200): never {
    http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}
if (empty($_SESSION['user']['isAdmin']) && !fridg3_toast_is_current_user()) toast_models_response(['error' => 'Admin access required.'], 403);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) toast_models_response(['error' => 'Method not allowed.'], 405);
$_SESSION['toast_models_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['toast_models_csrf'];
$path = dirname(__DIR__, 2) . '/data/etc/toast.json';
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input) || !is_string($input['csrf'] ?? null) || !hash_equals($csrf, $input['csrf'])) toast_models_response(['error' => 'Invalid request token.'], 403);
    try { $models = toast_validate_models($input['models'] ?? null); $settings = toast_validate_groq_settings($input['settings'] ?? null); }
    catch (InvalidArgumentException $e) { toast_models_response(['error' => $e->getMessage()], 400); }
    $current = json_decode((string)@file_get_contents($path), true);
    $active = toast_active_models((string)($current['groq']['api_key'] ?? ''));
    if ($active === null) toast_models_response(['error' => 'Could not verify active models with Groq. Try again shortly.'], 503);
    if (array_diff(array_values($models), $active)) toast_models_response(['error' => 'A selected model is no longer active. Refresh the model list.'], 400);
    $lock = @fopen($path . '.models.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) toast_models_response(['error' => 'Configuration is unavailable.'], 500);
    try {
        $config = json_decode((string)@file_get_contents($path), true);
        if (!is_array($config)) throw new RuntimeException('Configuration is missing or invalid.');
        $config['groq'] = is_array($config['groq'] ?? null) ? $config['groq'] : [];
        $config['groq']['models'] = $models;
        foreach ($settings as $key => $value) {
            if ($key === 'reasoning_effort' && $value === '') unset($config['groq'][$key]);
            else $config['groq'][$key] = $value;
        }
        $temporary = tempnam(dirname($path), '.toast-models-');
        if (!$temporary) throw new RuntimeException('Could not save model settings.');
        try {
            if (!rename($temporary, $temporary . '.json')) throw new RuntimeException();
        $temporary .= '.json';
        if (file_put_contents($temporary, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Could not save model settings.');
            chmod($temporary, fileperms($path) & 0777);
            if (!rename($temporary, $path)) throw new RuntimeException('Could not save model settings.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    } catch (Throwable $e) { toast_models_response(['error' => 'Could not save model settings.'], 500); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    toast_models_response(['ok' => true, 'models' => $models, 'settings' => $settings]);
}
$config = json_decode((string)@file_get_contents($path), true);
$groq = is_array($config['groq'] ?? null) ? $config['groq'] : [];
$models = [];
foreach (TOAST_MODEL_SCENARIOS as $scenario) $models[$scenario] = toast_model_for($groq, $scenario);
session_write_close();
$available = toast_active_models((string)($groq['api_key'] ?? ''));
$listError = $available === null ? 'Model list unavailable. Saving requires verification with Groq.' : '';
$available ??= [];
toast_models_response(['ok' => true, 'models' => $models, 'settings' => toast_groq_settings($groq), 'available' => $available, 'csrf' => $csrf, 'listError' => $listError]);
