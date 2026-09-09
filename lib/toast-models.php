<?php
declare(strict_types=1);

const TOAST_MODEL_SCENARIOS = ['discord_text', 'discord_images', 'website_chat_text', 'website_chat_images', 'feed_drafts', 'feed_replies'];
const TOAST_REASONING_EFFORTS = ['', 'none', 'default', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

function toast_groq_settings(array $groq): array {
    $float = static function ($value, float $default, float $min, float $max): float {
        return is_numeric($value) ? max($min, min($max, (float)$value)) : $default;
    };
    $int = static function ($value, int $default, int $min, int $max): int {
        return is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
    };
    $effort = trim((string)($groq['reasoning_effort'] ?? ''));
    if (!in_array($effort, TOAST_REASONING_EFFORTS, true)) $effort = '';
    return [
        'reasoning_effort' => $effort,
        'temperature' => $float($groq['temperature'] ?? null, 0.8, 0.0, 2.0),
        'top_p' => $float($groq['top_p'] ?? null, 0.95, 0.0, 1.0),
        'max_completion_tokens' => $int($groq['max_completion_tokens'] ?? null, 700, 1, 16384),
        'timeout_seconds' => $int($groq['timeout_seconds'] ?? null, 30, 5, 120),
        'max_history_messages' => $int($groq['max_history_messages'] ?? null, 12, 0, 30),
        'max_vision_images' => $int($groq['max_vision_images'] ?? null, 5, 0, 5),
    ];
}

function toast_validate_groq_settings($settings): array {
    if (!is_array($settings) || array_diff(array_keys($settings), array_keys(toast_groq_settings([])))) throw new InvalidArgumentException('Invalid Groq settings.');
    $effort = $settings['reasoning_effort'] ?? null;
    if (!is_string($effort) || !in_array($effort, TOAST_REASONING_EFFORTS, true)) throw new InvalidArgumentException('Choose a valid reasoning effort.');
    $definitions = [
        'temperature' => [0.0, 2.0], 'top_p' => [0.0, 1.0],
        'max_completion_tokens' => [1, 16384], 'timeout_seconds' => [5, 120],
        'max_history_messages' => [0, 30], 'max_vision_images' => [0, 5],
    ];
    $result = ['reasoning_effort' => $effort];
    foreach ($definitions as $key => [$min, $max]) {
        if (!is_int($settings[$key] ?? null) && !is_float($settings[$key] ?? null)) throw new InvalidArgumentException('Enter a numeric value for every Groq setting.');
        $value = $settings[$key];
        if ($value < $min || $value > $max) throw new InvalidArgumentException('A Groq setting is outside its allowed range.');
        $result[$key] = in_array($key, ['temperature', 'top_p'], true) ? (float)$value : (int)$value;
    }
    return $result;
}

function toast_apply_groq_request_settings(array $payload, array $groq): array {
    $settings = toast_groq_settings($groq);
    foreach (['temperature', 'top_p', 'max_completion_tokens'] as $key) $payload[$key] = $settings[$key];
    if ($settings['reasoning_effort'] !== '') $payload['reasoning_effort'] = $settings['reasoning_effort'];
    return $payload;
}

function toast_strip_reasoning_markup(string $content): string {
    $content = preg_replace('~<think\b[^>]*>.*?</think\s*>~is', '', $content);
    $content = preg_replace('~^\s*<think\b[^>]*>.*$~is', '', (string)$content);
    return trim((string)$content);
}

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
