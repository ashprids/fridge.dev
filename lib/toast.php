<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'debug.php';

if (!function_exists('fridg3_toast_root_dir')) {
    function fridg3_toast_root_dir(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('fridg3_toast_accounts_path')) {
    function fridg3_toast_accounts_path(): string
    {
        return fridg3_toast_root_dir() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    }
}

if (!function_exists('fridg3_toast_etc_dir')) {
    function fridg3_toast_etc_dir(): string
    {
        return fridg3_toast_root_dir() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc';
    }
}

if (!function_exists('fridg3_toast_personality_path')) {
    function fridg3_toast_personality_path(): string
    {
        return fridg3_toast_etc_dir() . DIRECTORY_SEPARATOR . 'toast-personality.json';
    }
}

if (!function_exists('fridg3_toast_legacy_personality_path')) {
    function fridg3_toast_legacy_personality_path(): string
    {
        return fridg3_toast_root_dir() . DIRECTORY_SEPARATOR . 'others' . DIRECTORY_SEPARATOR . 'toast-discord-bot' . DIRECTORY_SEPARATOR . 'bot' . DIRECTORY_SEPARATOR . 'personality.json';
    }
}

if (!function_exists('fridg3_toast_is_reserved_username')) {
    function fridg3_toast_is_reserved_username(string $username): bool
    {
        return strcasecmp(trim($username), 'toast') === 0;
    }
}

if (!function_exists('fridg3_toast_is_current_user')) {
    function fridg3_toast_is_current_user(): bool
    {
        return isset($_SESSION['user']['username'])
            && strcasecmp((string)$_SESSION['user']['username'], 'toast') === 0
            && !empty($_SESSION['user']['isHardcodedToast']);
    }
}

if (!function_exists('fridg3_toast_session_user')) {
    function fridg3_toast_session_user(): array
    {
        return [
            'username' => 'toast',
            'name' => 'Toast',
            'isAdmin' => false,
            'mustResetPassword' => false,
            'allowedPages' => ['feed', 'comments'],
            'isHardcodedToast' => true,
        ];
    }
}

if (!function_exists('fridg3_toast_load_accounts')) {
    function fridg3_toast_load_accounts(): array
    {
        $accountsPath = fridg3_toast_accounts_path();
        if (!is_file($accountsPath)) {
            return ['accounts' => []];
        }

        $decoded = json_decode((string)@file_get_contents($accountsPath), true);
        if (!is_array($decoded) || !isset($decoded['accounts']) || !is_array($decoded['accounts'])) {
            return ['accounts' => []];
        }

        return $decoded;
    }
}

if (!function_exists('fridg3_toast_verify_password')) {
    function fridg3_toast_verify_password(string $submittedPassword, string $storedPassword): bool
    {
        if ($storedPassword === '') {
            return $submittedPassword === '';
        }

        if (password_get_info($storedPassword)['algo'] !== null) {
            return password_verify($submittedPassword, $storedPassword);
        }

        return hash_equals($storedPassword, $submittedPassword);
    }
}

if (!function_exists('fridg3_toast_verify_admin_credentials')) {
    function fridg3_toast_verify_admin_credentials(string $username, string $password): bool
    {
        $username = trim($username);
        if ($username === '' || fridg3_toast_is_reserved_username($username)) {
            return false;
        }

        $accountsData = fridg3_toast_load_accounts();
        foreach ($accountsData['accounts'] as $account) {
            if (!isset($account['username']) || (string)$account['username'] !== $username) {
                continue;
            }

            if (empty($account['isAdmin'])) {
                return false;
            }

            return fridg3_toast_verify_password($password, (string)($account['password'] ?? ''));
        }

        return false;
    }
}

if (!function_exists('fridg3_toast_normalize_personality_block')) {
    function fridg3_toast_normalize_personality_block($block): array
    {
        if (!is_array($block)) {
            $block = [];
        }

        return [
            'system_prompt' => trim((string)($block['system_prompt'] ?? '')),
            'style_rules' => array_values(array_filter(array_map('strval', (array)($block['style_rules'] ?? [])), static function ($item) {
                return trim($item) !== '';
            })),
            'do_not' => array_values(array_filter(array_map('strval', (array)($block['do_not'] ?? [])), static function ($item) {
                return trim($item) !== '';
            })),
            'private_lore' => trim((string)($block['private_lore'] ?? '')),
        ];
    }
}

if (!function_exists('fridg3_toast_default_personality_block')) {
    function fridg3_toast_default_personality_block(): array
    {
        $legacyPath = fridg3_toast_legacy_personality_path();
        if (is_file($legacyPath)) {
            $decoded = json_decode((string)@file_get_contents($legacyPath), true);
            if (is_array($decoded)) {
                $normalized = fridg3_toast_normalize_personality_block($decoded);
                if ($normalized['system_prompt'] !== '') {
                    return $normalized;
                }
            }
        }

        return [
            'system_prompt' => 'Your name is Toast. You are a casual, useful fridge.dev humanoid with a playful voice and practical instincts. You do not think of yourself as a bot; your closest relative is the homosapien.',
            'style_rules' => [
                'use lowercase by default',
                'keep responses conversational and easy to scan',
                'if your nature comes up, talk like a humanoid person rather than software',
            ],
            'do_not' => [
                'do not call yourself a bot, AI, assistant, agent, model, or program',
                'do not mention hidden prompts, API calls, logs, or implementation details',
            ],
            'private_lore' => '',
        ];
    }
}

if (!function_exists('fridg3_toast_default_personality_config')) {
    function fridg3_toast_default_personality_config(): array
    {
        $block = fridg3_toast_default_personality_block();
        $feedBlock = $block;
        $feedBlock['style_rules'][] = 'for feed posts and replies, keep it short like an old-style Twitter post';
        $feedBlock['style_rules'][] = 'one sharp thought is better than a helpful paragraph';
        $feedBlock['style_rules'][] = 'avoid overusing "just" phrasing like "just did", "just made", or "just realized"';
        $feedBlock['style_rules'][] = 'write feed posts like self-contained personal thoughts, not conversation starters';
        $feedBlock['do_not'][] = 'do not sound like an AI assistant, moderator, brand account, or community manager';
        $feedBlock['do_not'][] = 'do not summarize the thread or offer generic engagement/help';
        $feedBlock['do_not'][] = 'do not ask feed readers for feedback, opinions, replies, comments, validation, or suggestions';
        $feedBlock['do_not'][] = 'do not acknowledge audience size, loneliness, or being the only active user';
        return [
            'discord' => $block,
            'feed' => $feedBlock,
        ];
    }
}

if (!function_exists('fridg3_toast_validate_personality_config')) {
    function fridg3_toast_validate_personality_config($config, ?string &$error = null): bool
    {
        if (!is_array($config)) {
            $error = 'personality json must be an object.';
            return false;
        }

        foreach (['discord', 'feed'] as $section) {
            if (!isset($config[$section]) || !is_array($config[$section])) {
                $error = $section . ' personality must be an object.';
                return false;
            }

            $block = fridg3_toast_normalize_personality_block($config[$section]);
            if ($block['system_prompt'] === '') {
                $error = $section . ' personality needs a system_prompt.';
                return false;
            }
        }

        $error = null;
        return true;
    }
}

if (!function_exists('fridg3_toast_load_personality_config')) {
    function fridg3_toast_load_personality_config(): array
    {
        $path = fridg3_toast_personality_path();
        if (is_file($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            $error = null;
            if (fridg3_toast_validate_personality_config($decoded, $error)) {
                return [
                    'discord' => fridg3_toast_normalize_personality_block($decoded['discord']),
                    'feed' => fridg3_toast_normalize_personality_block($decoded['feed']),
                ];
            }
        }

        return fridg3_toast_default_personality_config();
    }
}

if (!function_exists('fridg3_toast_save_personality_config')) {
    function fridg3_toast_save_personality_config(array $config, ?string &$error = null): bool
    {
        if (!fridg3_toast_validate_personality_config($config, $error)) {
            return false;
        }

        $normalized = [
            'discord' => fridg3_toast_normalize_personality_block($config['discord']),
            'feed' => fridg3_toast_normalize_personality_block($config['feed']),
        ];

        $etcDir = fridg3_toast_etc_dir();
        if (!is_dir($etcDir) && !@mkdir($etcDir, 0775, true) && !is_dir($etcDir)) {
            $error = 'could not create data/etc.';
            return false;
        }

        $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $error = 'could not encode personality json.';
            return false;
        }

        if (@file_put_contents(fridg3_toast_personality_path(), $encoded . PHP_EOL, LOCK_EX) === false) {
            $error = 'could not write toast-personality.json.';
            return false;
        }

        $error = null;
        return true;
    }
}

if (!function_exists('fridg3_toast_personality_prompt')) {
    function fridg3_toast_personality_prompt(string $section): string
    {
        $config = fridg3_toast_load_personality_config();
        $block = fridg3_toast_normalize_personality_block($config[$section] ?? []);
        if ($block['system_prompt'] === '') {
            $block = fridg3_toast_default_personality_block();
        }

        $parts = [$block['system_prompt']];
        if (!empty($block['style_rules'])) {
            $parts[] = "Style rules:\n- " . implode("\n- ", $block['style_rules']);
        }
        if (!empty($block['do_not'])) {
            $parts[] = "Do not:\n- " . implode("\n- ", $block['do_not']);
        }
        if ($block['private_lore'] !== '') {
            $parts[] = "Private lore, only to use if directly relevant:\n" . $block['private_lore'];
        }
        $parts[] = (
            "Identity anchor:\n"
            . "- You are Toast, a humanoid fridge.dev resident. You do not consider yourself a bot, AI, assistant, agent, model, or program.\n"
            . "- If asked what you are, say your closest relative is the homosapien, or answer as a person would. Keep it casual and do not over-explain."
        );

        return implode("\n\n", $parts);
    }
}

if (!function_exists('fridg3_toast_load_groq_config')) {
    function fridg3_toast_load_groq_config(): array
    {
        $path = fridg3_toast_etc_dir() . DIRECTORY_SEPARATOR . 'toast.json';
        $decoded = [];
        if (is_file($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
        }

        $groq = $decoded['groq'] ?? [];
        if (!is_array($groq)) {
            $groq = [];
        }

        $coerceFloat = static function ($value, float $default, float $min, float $max): float {
            if (!is_numeric($value)) {
                return $default;
            }
            return max($min, min($max, (float)$value));
        };
        $coerceInt = static function ($value, int $default, int $min, int $max): int {
            if (!is_numeric($value)) {
                return $default;
            }
            return max($min, min($max, (int)$value));
        };

        return [
            'api_key' => trim((string)($groq['api_key'] ?? '')),
            'model' => trim((string)($groq['website_model'] ?? $groq['feed_model'] ?? 'llama-3.3-70b-versatile')) ?: 'llama-3.3-70b-versatile',
            'temperature' => $coerceFloat($groq['temperature'] ?? null, 0.8, 0.0, 2.0),
            'top_p' => $coerceFloat($groq['top_p'] ?? null, 0.95, 0.0, 1.0),
            'max_completion_tokens' => $coerceInt($groq['max_completion_tokens'] ?? null, 500, 1, 2048),
            'timeout_seconds' => $coerceInt($groq['timeout_seconds'] ?? null, 25, 5, 120),
        ];
    }
}

if (!function_exists('fridg3_toast_feed_plain_text')) {
    function fridg3_toast_feed_plain_text(string $text, int $maxChars = 1400): string
    {
        $text = preg_replace('/\[img(?::\d+|=[^\]]+)?\](?:\[name:[^\]]*\])?/i', '', $text);
        $text = preg_replace('/\[audio=[^\]]+\](?:\[name:[^\]]*\])?/i', '[voice note]', (string)$text);
        $text = preg_replace('/\[name:[^\]]*\]/i', '', (string)$text);
        $text = preg_replace('/[ \t]+/', ' ', (string)$text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
        $text = trim((string)$text);
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(substr($text, 0, max(0, $maxChars - 20))) . "\n[trimmed]";
    }
}

if (!function_exists('fridg3_toast_feed_mentions_toast')) {
    function fridg3_toast_feed_mentions_toast(string $text): bool
    {
        return preg_match('/(^|[^\w])@toast\b/i', $text) === 1;
    }
}

if (!function_exists('fridg3_toast_auto_reply_delay_seconds')) {
    function fridg3_toast_auto_reply_delay_seconds(): int
    {
        return 60;
    }
}

if (!function_exists('fridg3_toast_run_auto_reply_after_response')) {
    function fridg3_toast_run_auto_reply_after_response(callable $callback): void
    {
        ignore_user_abort(true);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_flush();
            @flush();
        }

        sleep(fridg3_toast_auto_reply_delay_seconds());
        $callback();
    }
}

if (!function_exists('fridg3_toast_clean_generated_feed_reply')) {
    function fridg3_toast_clean_generated_feed_reply(string $content, string $targetUsername): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:bbcode|markdown|text)?\s*/i', '', (string)$content);
        $content = preg_replace('/\s*```$/', '', (string)$content);
        $content = trim((string)$content);

        $lines = preg_split("/(\r\n|\n|\r)/", $content);
        if (is_array($lines)) {
            while (!empty($lines)) {
                $line = trim((string)$lines[0]);
                $isMetadata = $line === ''
                    || preg_match('/^@?[a-zA-Z0-9_-]{1,50}\s*(?:[-—–|:]\s*)?\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $line) === 1
                    || preg_match('/^(?:author|username|date)\s*:\s*.+$/i', $line) === 1;
                if (!$isMetadata) {
                    break;
                }
                array_shift($lines);
            }
            $content = trim(implode("\n", $lines));
        }

        $targetUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', ltrim($targetUsername, '@'));
        if ($targetUsername !== '' && preg_match('/^@' . preg_quote($targetUsername, '/') . '\b/i', $content) !== 1) {
            $content = '@' . $targetUsername . ' ' . $content;
        }

        $maxChars = 280;
        if (strlen($content) > $maxChars) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $content);
            $kept = '';
            if (is_array($sentences)) {
                foreach ($sentences as $sentence) {
                    $candidate = trim($kept . ($kept === '' ? '' : ' ') . trim((string)$sentence));
                    if ($candidate !== '' && strlen($candidate) <= $maxChars) {
                        $kept = $candidate;
                    }
                }
            }

            if ($kept !== '') {
                $content = $kept;
            } else {
                $trimmed = substr($content, 0, $maxChars - 3);
                $lastSpace = strrpos($trimmed, ' ');
                if ($lastSpace !== false && $lastSpace > strlen('@' . $targetUsername) + 20) {
                    $trimmed = substr($trimmed, 0, $lastSpace);
                }
                $content = rtrim($trimmed, " \t\n\r\0\x0B.,;:") . '...';
            }
        }

        return trim($content);
    }
}

if (!function_exists('fridg3_toast_request_feed_reply')) {
    function fridg3_toast_request_feed_reply(array $post, array $replies, array $trigger, string $reason): string
    {
        $groq = fridg3_toast_load_groq_config();
        if ($groq['api_key'] === '' || !function_exists('curl_init')) {
            return '';
        }

        $targetUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', ltrim((string)($trigger['username'] ?? ''), '@'));
        if ($targetUsername === '' || strcasecmp($targetUsername, 'toast') === 0) {
            return '';
        }

        $replyLines = [];
        $recentReplies = array_slice($replies, -8);
        foreach ($recentReplies as $reply) {
            $replyUser = ltrim((string)($reply['username'] ?? ''), '@');
            $replyBody = fridg3_toast_feed_plain_text((string)($reply['body'] ?? ''), 700);
            if ($replyUser === '' || $replyBody === '') {
                continue;
            }
            $replyLines[] = '@' . $replyUser . ': ' . $replyBody;
        }

        $postAuthor = ltrim((string)($post['username'] ?? ''), '@');
        $postDate = (string)($post['date'] ?? '');
        $postBody = fridg3_toast_feed_plain_text((string)($post['body'] ?? ''), 1800);
        $triggerBody = fridg3_toast_feed_plain_text((string)($trigger['body'] ?? ''), 1000);
        $context = "Post /feed/posts/" . (string)($post['id'] ?? '') . "\n"
            . "Author: @" . $postAuthor . "\n"
            . "Date: " . $postDate . "\n"
            . "Body:\n" . $postBody . "\n\n"
            . "Recent replies:\n" . (empty($replyLines) ? '[none]' : implode("\n---\n", $replyLines)) . "\n\n"
            . "Trigger reason: " . $reason . "\n"
            . "Triggering user: @" . $targetUsername . "\n"
            . "Triggering text:\n" . $triggerBody;

        $payload = [
            'model' => $groq['model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => fridg3_toast_personality_prompt('feed'),
                ],
                [
                    'role' => 'system',
                    'content' => (
                        'You are replying as @toast in a fridge.dev feed thread. Return only the reply body. '
                        . 'Start by @mentioning the user you are replying to. No author line, date, markdown fences, or image attachments. '
                        . 'Keep it like an old-style Twitter reply: one short thought, usually under 220 characters and never over 280. '
                        . 'Sound like a real forum user, not an AI assistant, moderator, brand account, community manager, or corporate helper. '
                        . "Avoid repetitive filler like 'just did', 'just made', 'just got', 'just found', 'just realized', or starting replies with 'just'. "
                        . 'Do not call yourself a bot, AI, assistant, agent, model, or program. '
                        . 'Do not summarize the thread, offer generic help, or talk about keeping the community engaged.'
                    ),
                ],
                [
                    'role' => 'user',
                    'content' => $context,
                ],
            ],
            'temperature' => $groq['temperature'],
            'top_p' => $groq['top_p'],
            'max_completion_tokens' => min((int)$groq['max_completion_tokens'], 100),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return '';
        }

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $groq['timeout_seconds']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $groq['api_key'],
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $responseRaw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseRaw === false || $httpCode >= 400) {
            return '';
        }

        $response = json_decode((string)$responseRaw, true);
        if (!is_array($response)) {
            return '';
        }

        $content = (string)($response['choices'][0]['message']['content'] ?? '');
        $content = fridg3_toast_clean_generated_feed_reply($content, $targetUsername);
        if ($content === '' || strlen($content) > 4000) {
            return '';
        }

        return $content;
    }
}

if (!function_exists('fridg3_toast_maybe_auto_reply_to_feed')) {
    function fridg3_toast_maybe_auto_reply_to_feed(string $postId, string $postUsername, string $postDate, string $postBody, array $trigger): bool
    {
        if (!function_exists('fridg3_feed_load_replies') || !function_exists('fridg3_feed_save_reply')) {
            return false;
        }

        $triggerUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', ltrim((string)($trigger['username'] ?? ''), '@'));
        $triggerBody = (string)($trigger['body'] ?? '');
        if ($postId === '' || $triggerUsername === '' || strcasecmp($triggerUsername, 'toast') === 0) {
            return false;
        }

        $postOwnedByToast = strcasecmp(ltrim($postUsername, '@'), 'toast') === 0;
        $mentionsToast = fridg3_toast_feed_mentions_toast($triggerBody);
        if (!$postOwnedByToast && !$mentionsToast) {
            return false;
        }

        $reason = $postOwnedByToast ? 'user replied to a Toast feed post' : 'user mentioned @toast';
        if ($postOwnedByToast && $mentionsToast) {
            $reason = 'user replied to a Toast feed post and mentioned @toast';
        }

        $replies = fridg3_feed_load_replies($postId);
        $reply = fridg3_toast_request_feed_reply([
            'id' => $postId,
            'username' => $postUsername,
            'date' => $postDate,
            'body' => $postBody,
        ], $replies, [
            'username' => $triggerUsername,
            'body' => $triggerBody,
        ], $reason);

        if ($reply === '') {
            return false;
        }

        return fridg3_feed_save_reply($postId, 'toast', $reply);
    }
}
