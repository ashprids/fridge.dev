<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'contact';
$description = 'send a message to fridge.dev.';
$rootDir = dirname(__DIR__);
$contactDataDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'contact';
$rateLimitPath = $contactDataDir . DIRECTORY_SEPARATOR . 'rate_limits.json';
const CONTACT_REPLY_EMAIL = 'me@fridge.dev';
const CONTACT_NOTIFY_CHANNEL_ID = '1503931489560301609';

function contact_find_template_file(string $filename): ?string {
    $dir = __DIR__;
    $prevDir = '';

    while ($dir !== $prevDir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prevDir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

function contact_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function contact_ensure_data_dir(string $contactDataDir): bool {
    return is_dir($contactDataDir) || @mkdir($contactDataDir, 0750, true);
}

function contact_submission_path(string $contactDataDir, string $id): string {
    return $contactDataDir . DIRECTORY_SEPARATOR . $id . '.json';
}

function contact_generate_submission_id(string $contactDataDir): string {
    do {
        $id = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    } while (is_file(contact_submission_path($contactDataDir, $id)));

    return $id;
}

function contact_client_ip(): string {
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function contact_rate_key(): string {
    return hash('sha256', contact_client_ip());
}

function contact_read_json_file(string $path, array $fallback = []): array {
    if (!is_file($path)) {
        return $fallback;
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function contact_write_json_file(string $path, array $data): bool {
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
        return false;
    }

    $tempPath = tempnam($directory, 'contact_');
    if ($tempPath === false) {
        return @file_put_contents($path, $encoded, LOCK_EX) !== false;
    }

    $ok = @file_put_contents($tempPath, $encoded, LOCK_EX) !== false && @rename($tempPath, $path);
    if (!$ok) {
        @unlink($tempPath);
    }

    return $ok;
}

function contact_create_csrf_token(): string {
    if (empty($_SESSION['contact_csrf']) || !is_string($_SESSION['contact_csrf'])) {
        $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['contact_csrf'];
}

function contact_create_challenge(): array {
    $a = random_int(1, 12);
    $b = random_int(1, 12);
    $operators = ['+', '-', '*'];
    $operator = $operators[array_rand($operators)];
    if ($operator === '-' && $b > $a) {
        [$a, $b] = [$b, $a];
    }

    $answer = match ($operator) {
        '+' => $a + $b,
        '-' => $a - $b,
        default => $a * $b,
    };

    $_SESSION['contact_challenge_answer'] = $answer;
    return ['question' => 'what is ' . $a . ' ' . $operator . ' ' . $b . '?', 'answer' => $answer];
}

function contact_refresh_current_user_permissions(): void {
    if (!isset($_SESSION['user']['username'])) {
        return;
    }

    $accountsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $accountsData = contact_read_json_file($accountsPath, ['accounts' => []]);
    foreach ((array)($accountsData['accounts'] ?? []) as $account) {
        if (!is_array($account) || (string)($account['username'] ?? '') !== (string)$_SESSION['user']['username']) {
            continue;
        }
        $_SESSION['user']['name'] = (string)($account['name'] ?? '');
        $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
        $_SESSION['user']['allowedPages'] = array_map('strval', (array)($account['allowedPages'] ?? []));
        return;
    }
}

function contact_user_is_admin(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['isAdmin']);
}

function contact_render_page(string $title, string $description, string $content): void {
    $renderHelperPath = contact_find_template_file('lib/render.php');
    if ($renderHelperPath) {
        require_once $renderHelperPath;
    }

    $templateName = function_exists('get_preferred_template_name')
        ? get_preferred_template_name(__DIR__)
        : 'template.html';
    $templatePath = contact_find_template_file($templateName);
    if (!$templatePath && $templateName !== 'template.html') {
        $templatePath = contact_find_template_file('template.html');
    }
    if (!$templatePath) {
        die('page template not found. report this issue to me@fridge.dev.');
    }

    $html = (string)file_get_contents($templatePath);
    if (function_exists('apply_preferred_theme_stylesheet')) {
        $html = apply_preferred_theme_stylesheet($html, __DIR__);
    }

    $userGreeting = '';
    if (isset($_SESSION['user']['name'])) {
        $userGreeting = '<div id="user-greeting">Hello, ' . contact_h((string)$_SESSION['user']['name']) . '!</div>';
        $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
        $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
        $html = str_replace($accountBtn, $logoutBtn, $html);
    }

    $html = str_replace('{content}', $content, $html);
    $html = str_replace('{title}', $title, $html);
    $html = str_replace('{description}', $description, $html);
    $html = str_replace('{user_greeting}', $userGreeting, $html);
    echo $html;
}

function contact_check_rate_limit(string $rateLimitPath): ?string {
    $now = time();
    $key = contact_rate_key();
    $limits = contact_read_json_file($rateLimitPath);
    $windowStart = $now - 3600;

    foreach ($limits as $storedKey => $timestamps) {
        if (!is_array($timestamps)) {
            unset($limits[$storedKey]);
            continue;
        }
        $limits[$storedKey] = array_values(array_filter(array_map('intval', $timestamps), static fn(int $ts): bool => $ts >= $windowStart));
        if ($limits[$storedKey] === []) {
            unset($limits[$storedKey]);
        }
    }

    $attempts = $limits[$key] ?? [];
    if (count($attempts) >= 5) {
        contact_write_json_file($rateLimitPath, $limits);
        return 'too many messages from this connection. please wait a bit before trying again.';
    }

    $attempts[] = $now;
    $limits[$key] = $attempts;
    contact_write_json_file($rateLimitPath, $limits);
    return null;
}

function contact_count_links(string $message): int {
    preg_match_all('/https?:\/\/|www\.|[a-z0-9.-]+\.[a-z]{2,}/i', $message, $matches);
    return count($matches[0] ?? []);
}

function contact_load_submissions(string $contactDataDir): array {
    if (!is_dir($contactDataDir)) {
        return [];
    }

    $submissions = [];
    foreach (glob($contactDataDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (basename($file) === 'rate_limits.json') {
            continue;
        }
        $submission = contact_read_json_file($file);
        if ($submission !== [] && isset($submission['id'])) {
            $submissions[] = $submission;
        }
    }

    usort($submissions, static fn(array $a, array $b): int => (int)($b['createdAt'] ?? 0) <=> (int)($a['createdAt'] ?? 0));
    return $submissions;
}

function contact_notify_toast(array $submission): ?string {
    $preview = trim(preg_replace('/\s+/', ' ', (string)($submission['message'] ?? '')));
    if (strlen($preview) > 700) {
        $preview = substr($preview, 0, 697) . '...';
    }

    $payload = [
        'channel_id' => CONTACT_NOTIFY_CHANNEL_ID,
        'id' => (string)($submission['id'] ?? ''),
        'name' => (string)($submission['name'] ?? ''),
        'email' => (string)($submission['email'] ?? ''),
        'message_preview' => $preview,
        'created_at' => (int)($submission['createdAt'] ?? time()),
        'dashboard_url' => 'https://fridge.dev/contact?dashboard=1',
    ];

    $responseRaw = null;
    $error = null;
    if (function_exists('curl_init')) {
        $ch = curl_init('http://127.0.0.1:8765/contact/notify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $responseRaw = curl_exec($ch);
        if ($responseRaw === false) {
            $error = curl_error($ch);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($error === null && $httpCode >= 400) {
            $decoded = json_decode((string)$responseRaw, true);
            $error = is_array($decoded) && !empty($decoded['error']) ? (string)$decoded['error'] : 'toast returned http ' . $httpCode;
        }
    } else {
        $http_response_header = [];
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'timeout' => 4,
            ],
        ]);
        $responseRaw = @file_get_contents('http://127.0.0.1:8765/contact/notify', false, $context);
        if ($responseRaw === false) {
            $statusLine = $http_response_header[0] ?? '';
            $httpCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;
            $decoded = json_decode((string)$responseRaw, true);
            $error = is_array($decoded) && !empty($decoded['error']) ? (string)$decoded['error'] : ($httpCode >= 400 ? 'toast returned http ' . $httpCode : 'could not contact toast');
        }
    }

    $decoded = json_decode((string)$responseRaw, true);
    if ($error === null && (!is_array($decoded) || empty($decoded['ok']))) {
        $error = is_array($decoded) && !empty($decoded['error']) ? (string)$decoded['error'] : 'unknown toast response';
    }

    return $error;
}

contact_refresh_current_user_permissions();
$csrfToken = contact_create_csrf_token();

if (isset($_GET['dashboard'])) {
    if (!contact_user_is_admin()) {
        http_response_code(403);
        contact_render_page('contact dashboard', 'admin-only contact submissions.', '<h1>contact dashboard</h1><h2>admin access required.</h2><br><p><a href="/contact">back to contact</a></p>');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
        $postedToken = (string)($_POST['csrf'] ?? '');
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? ''));
        if (!hash_equals($csrfToken, $postedToken)) {
            header('Location: /contact?dashboard=1&error=' . rawurlencode('invalid request token.'));
            exit;
        }
        if ($id !== '') {
            @unlink(contact_submission_path($contactDataDir, $id));
        }
        header('Location: /contact?dashboard=1&deleted=1');
        exit;
    }

    $notice = isset($_GET['deleted']) ? '<div id="result">submission deleted.</div><br>' : '';
    if (isset($_GET['error'])) {
        $notice = '<div id="error">' . contact_h((string)$_GET['error']) . '</div><br>';
    }

    $cards = [];
    foreach (contact_load_submissions($contactDataDir) as $submission) {
        $id = (string)($submission['id'] ?? '');
        $name = trim((string)($submission['name'] ?? ''));
        $email = trim((string)($submission['email'] ?? ''));
        $message = (string)($submission['message'] ?? '');
        $notifyError = trim((string)($submission['notifyError'] ?? ''));
        $cards[] = '<article class="chat-admin-card contact-admin-card">'
            . '<div class="contact-admin-copy">'
            . '<div class="contact-admin-header">'
            . '<strong class="contact-admin-title">' . contact_h($name !== '' ? $name : 'unknown sender') . '</strong>'
            . '<a class="contact-admin-email" href="mailto:' . contact_h($email) . '">' . contact_h($email !== '' ? $email : 'no email') . '</a>'
            . '</div>'
            . '<div class="contact-admin-meta">'
            . '<span>' . contact_h(date('Y-m-d H:i', (int)($submission['createdAt'] ?? time()))) . '</span>'
            . '</div>'
            . ($notifyError !== '' ? '<div class="contact-admin-alert">discord notify failed: ' . contact_h($notifyError) . '</div>' : '')
            . '<div class="contact-admin-message">' . nl2br(contact_h($message), false) . '</div>'
            . '</div>'
            . '<form class="contact-admin-actions" method="post" action="/contact?dashboard=1" data-no-spa="1" data-site-confirm="1" data-confirm-title="delete contact submission?" data-confirm-detail="this cannot be undone." data-confirm-text="delete" data-cancel-text="cancel">'
            . '<input type="hidden" name="csrf" value="' . contact_h($csrfToken) . '">'
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="id" value="' . contact_h($id) . '">'
            . '<button class="danger-button" type="submit">delete</button>'
            . '</form>'
            . '</article>';
    }

    $content = '<h1>contact dashboard</h1><h2>admin-only contact submissions.</h2><br>'
        . $notice
        . ($cards === [] ? '<p>no contact submissions... yet.</p>' : '<div class="account-admin-list">' . implode('', $cards) . '</div>')
        . '<br><p><a href="/contact">back to contact form</a></p>';
    contact_render_page('contact dashboard', 'admin-only contact submissions.', $content);
    exit;
}

$errors = [];
$values = [
    'name' => '',
    'email' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name'] = trim((string)($_POST['name'] ?? ''));
    $values['email'] = trim((string)($_POST['email'] ?? ''));
    $values['message'] = trim((string)($_POST['message'] ?? ''));
    $postedToken = (string)($_POST['csrf'] ?? '');
    $startedAt = (int)($_POST['started_at'] ?? 0);
    $challengeAnswer = trim((string)($_POST['security_answer'] ?? ''));

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'invalid request token. refresh and try again.';
    }
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = 'spam check failed.';
    }
    if ($startedAt < 1 || time() - $startedAt < 4) {
        $errors[] = 'that was suspiciously fast. wait a second and try again.';
    }
    if ($challengeAnswer === '' || !isset($_SESSION['contact_challenge_answer']) || (int)$challengeAnswer !== (int)$_SESSION['contact_challenge_answer']) {
        $errors[] = 'security answer was wrong.';
    }
    if ($values['name'] === '' || strlen($values['name']) > 120) {
        $errors[] = 'name is required and must be 120 characters or less.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 254) {
        $errors[] = 'enter a valid email address.';
    }
    if ($values['message'] === '' || strlen($values['message']) > 5000) {
        $errors[] = 'message is required and must be 5000 characters or less.';
    }
    if (contact_count_links($values['message']) > 3) {
        $errors[] = 'too many links. keep it human.';
    }
    if ($errors === []) {
        $rateError = contact_check_rate_limit($rateLimitPath);
        if ($rateError !== null) {
            $errors[] = $rateError;
        }
    }

    if ($errors === []) {
        if (!contact_ensure_data_dir($contactDataDir)) {
            $errors[] = 'contact storage is unavailable. please try again later.';
        } else {
            $id = contact_generate_submission_id($contactDataDir);
            $submission = [
                'id' => $id,
                'createdAt' => time(),
                'ipHash' => contact_rate_key(),
                'userAgent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'name' => $values['name'],
                'email' => $values['email'],
                'message' => $values['message'],
                'notifyChannelId' => CONTACT_NOTIFY_CHANNEL_ID,
                'notifyError' => '',
            ];

            if (!contact_write_json_file(contact_submission_path($contactDataDir, $id), $submission)) {
                $errors[] = 'failed to save contact submission. please try again later.';
            } else {
                $notifyError = contact_notify_toast($submission);
                if ($notifyError !== null) {
                    $submission['notifyError'] = $notifyError;
                    contact_write_json_file(contact_submission_path($contactDataDir, $id), $submission);
                    error_log('contact submission ' . $id . ' toast notify failed: ' . $notifyError);
                }
                unset($_SESSION['contact_challenge_answer']);
                header('Location: /contact?sent=1');
                exit;
            }
        }
    }
}

$challenge = contact_create_challenge();
$adminButton = contact_user_is_admin()
    ? '<a id="two-buttons" href="/contact?dashboard=1">open contact dashboard</a>'
    : '';
$notice = '';
if (isset($_GET['sent'])) {
    $notice = '<div id="result">message sent. i\'ll reply from ' . contact_h(CONTACT_REPLY_EMAIL) . '.</div><br>';
}
if ($errors !== []) {
    $notice = '<div id="error">' . contact_h(implode(' ', $errors)) . '</div><br>';
}

$contentPath = contact_find_template_file('content.html');
if (!$contentPath) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = (string)file_get_contents($contentPath);
$content = str_replace(
    [
        '{reply_email}',
        '{admin_button}',
        '{notice}',
        '{csrf}',
        '{started_at}',
        '{name}',
        '{email}',
        '{message}',
        '{security_question}',
    ],
    [
        contact_h(CONTACT_REPLY_EMAIL),
        $adminButton,
        $notice,
        contact_h($csrfToken),
        (string)time(),
        contact_h($values['name']),
        contact_h($values['email']),
        contact_h($values['message']),
        contact_h($challenge['question']),
    ],
    $content
);

contact_render_page($title, $description, $content);
