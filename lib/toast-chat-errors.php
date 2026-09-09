<?php
function toast_chat_admin_error(array $reply, bool $isAdmin): ?string {
    if (!$isAdmin || !empty($reply['ok'])) return null;
    $d = is_array($reply['diagnostic'] ?? null) ? $reply['diagnostic'] : [];
    $messages = [
        'bot_connection' => 'Cannot connect to the local Toast service on 127.0.0.1:8765. Check that the bot is running and PHP can reach it.',
        'bot_timeout' => 'The local Toast service timed out while generating a reply.',
        'bot_http' => 'The local Toast service rejected the request. For HTTP 404, restart the bot with the current code so /website-chat/reply is registered.',
        'bot_invalid_json' => 'The local Toast service returned invalid JSON.',
        'missing_api_key' => 'Groq API key is missing from the bot configuration.',
        'model_config_failed' => 'The bot could not load its model policy. Check lib/toast-model-policy.json and file permissions.',
        'context_failed' => 'The bot failed while building its personality or conversation context. Check the bot logs.',
        'invalid_request' => 'The bot rejected the website chat payload.',
        'model_catalog_http' => 'Groq rejected the model catalog request. HTTP 401/403 indicates credentials or provider access must be checked.',
        'model_catalog_invalid_json' => 'Groq returned an invalid model catalog.',
        'model_inactive' => 'The selected model is not active for this Groq account. Choose an available model in Toast settings.',
        'model_catalog_timeout' => 'The Groq model catalog request timed out.',
        'model_catalog_connection' => 'The bot could not connect to the Groq model catalog.',
        'completion_http' => 'Groq rejected the chat completion request. Check the HTTP status and provider error code below.',
        'completion_invalid_json' => 'Groq returned invalid JSON for the chat completion.',
        'completion_timeout' => 'The Groq chat completion request timed out.',
        'completion_connection' => 'The bot could not connect to Groq for the chat completion.',
        'completion_token_limit' => 'Groq exhausted max_completion_tokens before returning an answer. Increase the token budget in toast.json; reasoning models use this budget for reasoning too.',
        'completion_empty' => 'Groq returned no answer text.',
    ];
    $text = $messages[$d['code'] ?? ''] ?? 'Toast could not generate a reply. Restart the bot with the current code and check its logs for details.';
    if (is_int($d['http_status'] ?? null) && $d['http_status'] >= 100 && $d['http_status'] <= 599) $text .= ' HTTP ' . $d['http_status'] . '.';
    if (is_string($d['model'] ?? null) && preg_match('~^[a-zA-Z0-9._/-]{1,200}$~D', $d['model'])) $text .= ' Model: ' . $d['model'] . '.';
    if (is_string($d['provider_code'] ?? null) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $d['provider_code'])) $text .= ' Provider code: ' . $d['provider_code'] . '.';
    return $text;
}
