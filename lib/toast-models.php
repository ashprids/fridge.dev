<?php
declare(strict_types=1);

const TOAST_MODEL_SCENARIOS = ['discord_text', 'discord_images', 'website_chat_text', 'website_chat_images', 'feed_drafts', 'feed_replies'];

function toast_model_policy(): array {
    return json_decode((string)file_get_contents(__DIR__ . '/toast-model-policy.json'), true, 512, JSON_THROW_ON_ERROR);
}
function toast_model_for(array $groq, string $scenario): string {
    $policy = toast_model_policy();
    $kind = str_ends_with($scenario, '_images') ? 'vision' : (str_starts_with($scenario, 'feed_') ? 'feed' : 'text');
    $legacy = $kind === 'vision' ? ($groq['vision_model'] ?? '') : ($kind === 'feed' ? ($groq['website_model'] ?? $groq['feed_model'] ?? '') : ($groq['model'] ?? ''));
    $value = trim((string)($groq['models'][$scenario] ?? $legacy));
    return $value === '' || in_array($value, $policy['retired'], true) ? $policy['defaults'][$kind] : $value;
}
function toast_active_models(string $key): ?array {
    static $cache = [];
    $cacheKey = hash('sha256', $key);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    if ($key === '' || !function_exists('curl_init')) return null;
    $ch = curl_init('https://api.groq.com/openai/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($data['data'] ?? null)) return $cache[$cacheKey] = null;
    $ids = []; $retired = toast_model_policy()['retired'];
    foreach ($data['data'] as $model) {
        $id = $model['id'] ?? null;
        if (is_string($id) && ($model['active'] ?? true) && !in_array($id, $retired, true)) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids)); sort($ids);
    return $cache[$cacheKey] = $ids;
}

function toast_validate_models($models): array {
    if (!is_array($models) || array_diff(array_keys($models), TOAST_MODEL_SCENARIOS)) throw new InvalidArgumentException('Invalid model settings.');
    $result = [];
    foreach (TOAST_MODEL_SCENARIOS as $scenario) {
        $value = $models[$scenario] ?? null;
        if (!is_string($value) || !preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]{0,199}$~D', trim($value))) throw new InvalidArgumentException('Enter a valid model ID for every scenario.');
        if (in_array(trim($value), toast_model_policy()['retired'], true)) throw new InvalidArgumentException('This model has been retired. Choose an active model.');
        $result[$scenario] = trim($value);
    }
    return $result;
}
