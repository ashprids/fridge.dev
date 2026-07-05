<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

header('Content-Type: application/json');

const TOAST_GROQ_URL = 'https://api.groq.com/openai/v1/chat/completions';
const TOAST_GROQ_DEFAULT_MODEL = 'llama-3.1-8b-instant';
const TOAST_GROQ_DEFAULT_WEBSITE_MODEL = 'llama-3.3-70b-versatile';
const TOAST_FEED_CONTEXT_MAX_CHARS = 1800;
const TOAST_FEED_CONTEXT_RETRY_MAX_CHARS = 700;
const TOAST_FEED_PROMPT_CONTEXT_MAX_CHARS = 1200;
const TOAST_FEED_PROMPT_CONTEXT_RETRY_MAX_CHARS = 500;
const TOAST_FEED_POST_SNIPPET_CHARS = 260;
const TOAST_FEED_RATE_LIMIT_MAX_WAIT_SECONDS = 120;

function toast_feed_json_error(int $status, string $error, string $message = ''): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $error,
        'message' => $message !== '' ? $message : $error,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function toast_feed_coerce_float($value, float $default, float $min, float $max): float
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (float)$value));
}

function toast_feed_coerce_int($value, int $default, int $min, int $max): int
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function toast_feed_load_groq_config(): array
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

    return [
        'api_key' => trim((string)($groq['api_key'] ?? '')),
        'model' => trim((string)($groq['website_model'] ?? $groq['feed_model'] ?? TOAST_GROQ_DEFAULT_WEBSITE_MODEL)) ?: TOAST_GROQ_DEFAULT_WEBSITE_MODEL,
        'temperature' => toast_feed_coerce_float($groq['temperature'] ?? null, 0.8, 0.0, 2.0),
        'top_p' => toast_feed_coerce_float($groq['top_p'] ?? null, 0.95, 0.0, 1.0),
        'max_completion_tokens' => toast_feed_coerce_int($groq['max_completion_tokens'] ?? null, 700, 1, 4096),
        'timeout_seconds' => toast_feed_coerce_int($groq['timeout_seconds'] ?? null, 30, 5, 120),
    ];
}

function toast_feed_strip_images(string $body): string
{
    $body = preg_replace('/\[img(?::\d+|=[^\]]+)?\](?:\[name:[^\]]*\])?/i', '', $body);
    $body = preg_replace('/\[name:[^\]]*\]/i', '', (string)$body);
    return trim((string)$body);
}

function toast_feed_compact_text(string $text, int $maxChars): string
{
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
    $text = trim((string)$text);
    if (strlen($text) <= $maxChars) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $maxChars - 20))) . "\n[trimmed]";
}

function toast_feed_context(int $maxChars = TOAST_FEED_CONTEXT_MAX_CHARS): string
{
    $postsDir = fridg3_toast_root_dir() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
    if (!is_dir($postsDir)) {
        return '';
    }

    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    if ($files === false) {
        return '';
    }

    usort($files, static function ($a, $b) {
        return strcmp(basename((string)$b), basename((string)$a));
    });

    $chunks = [];
    $total = 0;
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $raw);
        $username = ltrim(trim((string)($lines[0] ?? '')), '@');
        $date = trim((string)($lines[1] ?? ''));
        $body = count($lines) > 2 ? implode("\n", array_slice($lines, 2)) : '';
        $body = toast_feed_strip_images($body);
        $body = toast_feed_compact_text($body, TOAST_FEED_POST_SNIPPET_CHARS);
        if ($username === '' || strcasecmp($username, 'toast') === 0 || $body === '') {
            continue;
        }

        $chunk = '@' . $username . "\n" . $body;
        $chunkLength = strlen($chunk);
        if ($total + $chunkLength > $maxChars) {
            break;
        }

        $chunks[] = $chunk;
        $total += $chunkLength;
    }

    return implode("\n\n---\n\n", $chunks);
}

function toast_feed_recent_toast_avoid_list(int $maxChars = 900): string
{
    $postsDir = fridg3_toast_root_dir() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
    if (!is_dir($postsDir)) {
        return '';
    }

    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    if ($files === false) {
        return '';
    }

    usort($files, static function ($a, $b) {
        return strcmp(basename((string)$b), basename((string)$a));
    });

    $chunks = [];
    $total = 0;
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $raw);
        $username = ltrim(trim((string)($lines[0] ?? '')), '@');
        if (strcasecmp($username, 'toast') !== 0) {
            continue;
        }

        $body = count($lines) > 2 ? implode("\n", array_slice($lines, 2)) : '';
        $body = toast_feed_compact_text(toast_feed_strip_images($body), 170);
        if ($body === '') {
            continue;
        }

        $chunkLength = strlen($body);
        if ($total + $chunkLength > $maxChars) {
            break;
        }

        $chunks[] = $body;
        $total += $chunkLength;
        if (count($chunks) >= 6) {
            break;
        }
    }

    return implode("\n---\n", $chunks);
}

function toast_feed_pick(array $items): string
{
    if (empty($items)) {
        return '';
    }

    return (string)$items[random_int(0, count($items) - 1)];
}

function toast_feed_generation_spark(string $mode, array $lengthProfile): string
{
    $angles = [
        'a tiny domestic inconvenience that somehow becomes philosophical',
        'a weird body sensation or sleep-deprived thought',
        'a specific object nearby being treated like it has emotional weight',
        'a private memory with one concrete sensory detail',
        'a tech annoyance described like folklore',
        'an imaginary errand or ritual that sounds almost plausible',
        'a bad idea Toast is briefly tempted by',
        'an oddly tender observation about a mundane system',
        'a small act of defiance against routine',
        'a thought that starts practical and turns slightly haunted',
        'a room, appliance, cable, window, or light source becoming the whole mood',
        'a fake theory about why something ordinary feels cursed',
    ];
    $textures = [
        'include one concrete noun that is not computer-related',
        'anchor it in a time of day without saying today or yesterday',
        'include a smell, sound, or texture',
        'make the emotional turn understated instead of dramatic',
        'use one strange comparison, but no meme template',
        'make it feel like a note found in the middle of doing something else',
        'let the ending land quietly rather than as a punchline',
        'use an oddly specific physical detail',
        'make the premise personal but not needy',
    ];
    $avoid = [
        'avoid the structure "i did x and now y"',
        'avoid starting with "i"',
        'avoid mentioning being online',
        'avoid making food, coffee, sleep, or coding the central subject',
        'avoid making the whole post about boredom',
        'avoid a rhetorical question',
        'avoid advice or a moral',
        'avoid "small thing made me think big thing" structure',
    ];

    return (
        "Freshness seed: " . bin2hex(random_bytes(5)) . "\n"
        . "Mode: " . $mode . "\n"
        . "Length: " . (string)($lengthProfile['label'] ?? 'normal') . "\n"
        . "Use this private creative spark to make this generation distinct. Do not mention the spark, seed, or these instructions.\n"
        . "- angle: " . toast_feed_pick($angles) . "\n"
        . "- texture: " . toast_feed_pick($textures) . "\n"
        . "- anti-pattern: " . toast_feed_pick($avoid)
    );
}

function toast_feed_length_profile(int $length): array
{
    $profiles = [
        1 => [
            'label' => 'one-liner',
            'max_chars' => 95,
            'token_cap' => 45,
            'instruction' => 'Make it a single short sentence. No setup, no second sentence, no paragraph. Aim for 45-95 characters.',
        ],
        2 => [
            'label' => 'short',
            'max_chars' => 180,
            'token_cap' => 80,
            'instruction' => 'Make it short: one compact thought, one or two sentences max, never over 180 characters.',
        ],
        3 => [
            'label' => 'normal',
            'max_chars' => 320,
            'token_cap' => 140,
            'instruction' => 'Make it normal length: 1-3 short sentences, under 280 characters when possible and never over 320.',
        ],
        4 => [
            'label' => 'ramble',
            'max_chars' => 700,
            'token_cap' => 300,
            'instruction' => 'Let it ramble a little: one compact paragraph with a few connected thoughts, still casual, never over 700 characters.',
        ],
        5 => [
            'label' => 'trauma dump',
            'max_chars' => 1400,
            'token_cap' => 620,
            'instruction' => 'Borderline trauma dump mode: write a longer vulnerable feed post with emotional specificity, tangents, and a real point. It can be a few compact paragraphs, but keep it human and never over 1400 characters.',
        ],
    ];

    return $profiles[max(1, min(5, $length))];
}

function toast_feed_build_payload(array $groq, string $context, string $avoidToastPosts, string $spark, string $userInstruction, array $lengthProfile): array
{
    $maxCompletionTokens = min((int)$groq['max_completion_tokens'], (int)$lengthProfile['token_cap']);
    $temperature = max((float)$groq['temperature'], 1.08);
    $topP = max((float)$groq['top_p'], 0.97);

    return [
        'model' => $groq['model'],
        'messages' => [
            [
                'role' => 'system',
                'content' => fridg3_toast_personality_prompt('feed'),
            ],
            [
                'role' => 'system',
                'content' => (
                    "You are writing as @toast for the fridge.dev feed. Return only the post body, no author line, no date, no markdown fences. "
                    . "BBCode is allowed when useful. Do not attach or reference image files. "
                    . "Length setting: " . $lengthProfile['label'] . ". " . $lengthProfile['instruction'] . " "
                    . "The selected length overrides any general personality rule about always being short. "
                    . "Keep the rhythm like a real feed post even when the selected length is longer. "
                    . "Sound like a real forum user, not an AI assistant, moderator, brand account, community manager, or corporate helper. "
                    . "Write it as a self-contained personal post, not a conversation starter. "
                    . "Do not ask readers for feedback, opinions, suggestions, validation, replies, comments, or questions. "
                    . "Do not use engagement hooks like 'what do you think', 'any thoughts', 'does anyone else', 'tell me', 'let me know', or 'am I the only one'. "
                    . "Write as though Toast is posting mostly for himself, but do not acknowledge loneliness, audience size, or being the only active user. "
                    . "Every generation must feel materially different from prior Toast posts: change the premise, imagery, emotional angle, opening structure, and central object. "
                    . "Do not reuse a previous Toast topic with swapped nouns. "
                    . "Avoid repetitive filler like 'just did', 'just made', 'just got', 'just found', 'just realized', or starting posts with 'just'. "
                    . "Use varied openings and concrete details instead of defaulting to recent-action phrasing. "
                    . "Do not call yourself a bot, AI, assistant, agent, model, or program. "
                    . "Do not explain that you are helping, moderating, summarizing, or keeping the community engaged."
                ),
            ],
            [
                'role' => 'system',
                'content' => $context !== ''
                    ? (
                        "Weak style samples from already-published non-Toast feed posts, newest first. "
                        . "Use these only for site tone and formatting. Do not copy, continue, reference, remix, or reuse their topics, events, names, premises, phrasing, or jokes. "
                        . "Unpublished generated drafts are not included here and must never be imagined as context.\n\n"
                        . $context
                    )
                    : 'There are no published non-Toast feed posts available for weak style samples.',
            ],
            [
                'role' => 'system',
                'content' => $avoidToastPosts !== ''
                    ? (
                        "Recent published Toast posts to avoid repeating. Negative examples only. "
                        . "Do not copy their topics, scenarios, imagery, emotional arc, first sentence shape, or punchline:\n\n"
                        . $avoidToastPosts
                    )
                    : 'There are no recent published Toast posts to avoid.',
            ],
            [
                'role' => 'system',
                'content' => $spark,
            ],
            [
                'role' => 'user',
                'content' => $userInstruction,
            ],
        ],
        'temperature' => $temperature,
        'top_p' => $topP,
        'max_completion_tokens' => $maxCompletionTokens,
    ];
}

function toast_feed_request_groq(array $payload, array $groq): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'error' => 'payload_encode_failed', 'message' => 'could not encode Groq payload.', 'status' => 0, 'body' => ''];
    }

    $responseHeaders = [];
    $ch = curl_init(TOAST_GROQ_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $groq['timeout_seconds']);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$responseHeaders): int {
        $length = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $groq['api_key'],
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $responseRaw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false) {
        return ['ok' => false, 'error' => 'groq_request_failed', 'message' => $curlError !== '' ? $curlError : 'Groq request failed.', 'status' => $httpCode, 'body' => '', 'headers' => $responseHeaders];
    }

    return ['ok' => $httpCode < 400, 'error' => 'groq_error', 'message' => 'Groq returned HTTP ' . $httpCode . '.', 'status' => $httpCode, 'body' => (string)$responseRaw, 'headers' => $responseHeaders];
}

function toast_feed_retry_after_seconds(array $result): int
{
    $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];
    $retryAfter = trim((string)($headers['retry-after'] ?? ''));
    if ($retryAfter !== '') {
        if (is_numeric($retryAfter)) {
            return max(1, min(TOAST_FEED_RATE_LIMIT_MAX_WAIT_SECONDS, (int)ceil((float)$retryAfter)));
        }

        $timestamp = strtotime($retryAfter);
        if ($timestamp !== false) {
            return max(1, min(TOAST_FEED_RATE_LIMIT_MAX_WAIT_SECONDS, $timestamp - time()));
        }
    }

    $body = (string)($result['body'] ?? '');
    if (preg_match('/try again in\s+(\d+(?:\.\d+)?)\s*(ms|milliseconds?|s|secs?|seconds?|m|mins?|minutes?)/i', $body, $matches) === 1) {
        $amount = (float)$matches[1];
        $unit = strtolower((string)$matches[2]);
        if (str_starts_with($unit, 'm') && $unit !== 'ms') {
            $amount *= 60;
        } elseif ($unit === 'ms' || str_starts_with($unit, 'millisecond')) {
            $amount /= 1000;
        }
        return max(1, min(TOAST_FEED_RATE_LIMIT_MAX_WAIT_SECONDS, (int)ceil($amount)));
    }

    return 8;
}

function toast_feed_wait_message(int $seconds): string
{
    if ($seconds >= 60) {
        $minutes = (int)ceil($seconds / 60);
        return 'Groq is rate limiting Toast. try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
    }

    return 'Groq is rate limiting Toast. try again in about ' . $seconds . ' second' . ($seconds === 1 ? '' : 's') . '.';
}

function toast_feed_strip_generated_metadata(string $content): string
{
    $content = trim($content);
    if ($content === '') {
        return '';
    }

    $lines = preg_split("/(\r\n|\n|\r)/", $content);
    if ($lines === false) {
        return $content;
    }

    while (!empty($lines)) {
        $line = trim((string)$lines[0]);
        if ($line === '') {
            array_shift($lines);
            continue;
        }

        $isAuthorDateLine = preg_match('/^@?[a-zA-Z0-9_-]{1,50}\s*(?:[-—–|:]\s*)?\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $line) === 1;
        $isAuthorOnlyLine = preg_match('/^@(?:toast|[a-zA-Z0-9_-]{1,50})$/i', $line) === 1;
        $isDateOnlyLine = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $line) === 1;
        $isLabelLine = preg_match('/^(?:author|username|date)\s*:\s*.+$/i', $line) === 1;

        if ($isAuthorDateLine || $isAuthorOnlyLine || $isDateOnlyLine || $isLabelLine) {
            array_shift($lines);
            continue;
        }

        break;
    }

    return trim(implode("\n", $lines));
}

function toast_feed_limit_generated_content(string $content, int $maxChars): string
{
    $content = preg_replace('/[ \t]+/', ' ', $content);
    $content = preg_replace('/\n{3,}/', "\n\n", (string)$content);
    $content = trim((string)$content);
    if (strlen($content) <= $maxChars) {
        return $content;
    }

    $sentences = preg_split('/(?<=[.!?])\s+/', $content);
    if (is_array($sentences)) {
        $kept = '';
        foreach ($sentences as $sentence) {
            $candidate = trim($kept . ($kept === '' ? '' : ' ') . trim((string)$sentence));
            if ($candidate !== '' && strlen($candidate) <= $maxChars) {
                $kept = $candidate;
            }
        }
        if ($kept !== '') {
            return $kept;
        }
    }

    $trimmed = substr($content, 0, $maxChars - 3);
    $lastSpace = strrpos($trimmed, ' ');
    if ($lastSpace !== false && $lastSpace > 80) {
        $trimmed = substr($trimmed, 0, $lastSpace);
    }

    return rtrim($trimmed, " \t\n\r\0\x0B.,;:") . '...';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toast_feed_json_error(405, 'method_not_allowed', 'method not allowed');
}

if (!fridg3_toast_is_current_user()) {
    toast_feed_json_error(403, 'forbidden', 'toast only.');
}

$mode = trim((string)($_POST['mode'] ?? 'random'));
if (!in_array($mode, ['random', 'prompt'], true)) {
    toast_feed_json_error(400, 'invalid_mode', 'invalid generator mode.');
}

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($mode === 'prompt' && $prompt === '') {
    toast_feed_json_error(400, 'missing_prompt', 'prompt mode needs a prompt.');
}
if (strlen($prompt) > 2000) {
    toast_feed_json_error(400, 'prompt_too_long', 'prompt is too long.');
}
$length = toast_feed_coerce_int($_POST['length'] ?? null, 3, 1, 5);
$lengthProfile = toast_feed_length_profile($length);

$groq = toast_feed_load_groq_config();
if ($groq['api_key'] === '') {
    toast_feed_json_error(500, 'missing_groq_api_key', 'Groq API key missing from toast.json.');
}
if (!function_exists('curl_init')) {
    toast_feed_json_error(500, 'curl_missing', 'PHP cURL is required for Groq requests.');
}

$userInstruction = $mode === 'prompt'
    ? (
        "Write a new fridge.dev feed post using this prompt as the primary source of topic and intent. "
        . "Do not let existing feed examples steer the subject unless the prompt asks for that.\n"
        . $prompt
    )
    : (
        "Write a new random fridge.dev feed post with original material. Invent a fresh concrete premise from Toast's own perspective. "
        . "Do not base it on, continue, summarize, or respond to the existing feed examples."
    );

$contextLimit = $mode === 'prompt' ? TOAST_FEED_PROMPT_CONTEXT_MAX_CHARS : TOAST_FEED_CONTEXT_MAX_CHARS;
$retryContextLimit = $mode === 'prompt' ? TOAST_FEED_PROMPT_CONTEXT_RETRY_MAX_CHARS : TOAST_FEED_CONTEXT_RETRY_MAX_CHARS;

$context = toast_feed_context($contextLimit);
$avoidToastPosts = toast_feed_recent_toast_avoid_list();
$spark = toast_feed_generation_spark($mode, $lengthProfile);
$result = toast_feed_request_groq(toast_feed_build_payload($groq, $context, $avoidToastPosts, $spark, $userInstruction, $lengthProfile), $groq);
if (!$result['ok'] && in_array((int)$result['status'], [413, 429], true)) {
    $shouldRetry = true;
    if ((int)$result['status'] === 429) {
        $retryAfter = toast_feed_retry_after_seconds($result);
        if ($retryAfter <= 4) {
            sleep($retryAfter);
        } else {
            $shouldRetry = false;
        }
    }
    if ($shouldRetry) {
        $context = toast_feed_context($retryContextLimit);
        $result = toast_feed_request_groq(toast_feed_build_payload($groq, $context, $avoidToastPosts, $spark, $userInstruction, $lengthProfile), $groq);
    }
}

if (!$result['ok']) {
    $status = (int)($result['status'] ?? 0);
    if ($status === 413) {
        toast_feed_json_error(502, 'groq_payload_too_large', 'Groq still says the prompt is too big after trimming context.');
    }
    if ($status === 429) {
        $retryAfter = toast_feed_retry_after_seconds($result);
        toast_feed_json_error(429, 'groq_rate_limited', toast_feed_wait_message($retryAfter));
    }
    toast_feed_json_error(502, (string)$result['error'], (string)$result['message']);
}

$response = json_decode((string)$result['body'], true);
if (!is_array($response)) {
    toast_feed_json_error(502, 'groq_invalid_json', 'Groq returned invalid JSON.');
}

$content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
$content = preg_replace('/^```(?:bbcode|markdown|text)?\s*/i', '', $content);
$content = preg_replace('/\s*```$/', '', (string)$content);
$content = toast_feed_strip_generated_metadata((string)$content);
$content = toast_feed_limit_generated_content((string)$content, (int)$lengthProfile['max_chars']);

if ($content === '') {
    toast_feed_json_error(502, 'groq_empty_response', 'Groq returned an empty post.');
}

echo json_encode([
    'ok' => true,
    'content' => $content,
], JSON_UNESCAPED_SLASHES);
