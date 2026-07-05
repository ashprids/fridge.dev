<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}

$title = 'link discord';
$description = 'link your discord account to fridge.dev.';
$errorMessage = '';
$successMessage = '';
$currentUsername = (string)$_SESSION['user']['username'];
$formDiscordUserId = '';
$linkedDiscordUserId = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function find_template_file($filename) {
    $dir = __DIR__;
    $prev_dir = '';

    while ($dir !== $prev_dir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prev_dir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

function link_discord_accounts_path(): string {
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
}

function link_discord_load_accounts(): array {
    $accountsPath = link_discord_accounts_path();
    if (!is_file($accountsPath)) {
        return ['accounts' => []];
    }

    $decoded = json_decode((string)@file_get_contents($accountsPath), true);
    if (!is_array($decoded) || !isset($decoded['accounts']) || !is_array($decoded['accounts'])) {
        return ['accounts' => []];
    }

    return $decoded;
}

function link_discord_save_accounts(array $accountsData): bool {
    $encoded = json_encode($accountsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return @file_put_contents(link_discord_accounts_path(), $encoded, LOCK_EX) !== false;
}

$accountsData = link_discord_load_accounts();
$accountIndex = null;
foreach ($accountsData['accounts'] as $index => $account) {
    if (isset($account['username']) && (string)$account['username'] === $currentUsername) {
        $accountIndex = $index;
        $linkedDiscordUserId = trim((string)($account['discordUserId'] ?? ''));
        break;
    }
}

if ($accountIndex === null) {
    http_response_code(404);
    die('account not found.');
}

if ($linkedDiscordUserId !== '') {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $formDiscordUserId = trim((string)($_POST['discordUserId'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        $errorMessage = 'invalid request. please try again.';
    } elseif (!preg_match('/^\d{17,20}$/', $formDiscordUserId)) {
        $errorMessage = 'discord user id must be 17-20 digits.';
    } else {
        foreach ($accountsData['accounts'] as $index => $account) {
            if ($index === $accountIndex) {
                continue;
            }
            if (isset($account['discordUserId']) && trim((string)$account['discordUserId']) === $formDiscordUserId) {
                $errorMessage = 'that discord user id is already linked to another account.';
                break;
            }
        }

        if ($errorMessage === '') {
            $botResponseRaw = null;
            $botError = null;

            if (function_exists('curl_init')) {
                $ch = curl_init('http://127.0.0.1:8765/link-discord');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'discord_user_id' => $formDiscordUserId,
                    'site_username' => $currentUsername,
                ], JSON_UNESCAPED_SLASHES));
                $botResponseRaw = curl_exec($ch);
                if ($botResponseRaw === false) {
                    $botError = curl_error($ch);
                }
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($botError === null && $httpCode >= 400) {
                    $botError = 'bot returned http ' . $httpCode;
                }
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\n",
                        'content' => json_encode([
                            'discord_user_id' => $formDiscordUserId,
                            'site_username' => $currentUsername,
                        ], JSON_UNESCAPED_SLASHES),
                        'timeout' => 10,
                    ],
                ]);
                $botResponseRaw = @file_get_contents('http://127.0.0.1:8765/link-discord', false, $context);
                if ($botResponseRaw === false) {
                    $botError = 'could not contact discord bot';
                }
            }

            if ($botError !== null) {
                $errorMessage = 'discord bot check failed: ' . $botError;
            } else {
                $botResponse = json_decode((string)$botResponseRaw, true);
                if (!is_array($botResponse) || empty($botResponse['ok'])) {
                    $errorMessage = isset($botResponse['error']) ? (string)$botResponse['error'] : 'discord bot check failed.';
                } else {
                    $accountsData['accounts'][$accountIndex]['discordUserId'] = $formDiscordUserId;
                    if (!link_discord_save_accounts($accountsData)) {
                        $errorMessage = 'failed to save linked discord id.';
                    } else {
                        $linkedDiscordUserId = $formDiscordUserId;
                        $successMessage = 'discord account linked and registered role assigned.';
                        $formDiscordUserId = '';
                    }
                }
            }
        }
    }
}

$render_helper_path = find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$template_path = find_template_file($template_name);
if (!$template_path && $template_name !== 'template.html') {
    $template_path = find_template_file('template.html');
}
if (!$template_path) {
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}
$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);
$content = str_replace(
    [
        '{error_style}',
        '{error_message}',
        '{success_style}',
        '{success_message}',
        '{discord_user_id}',
        '{csrf_token}',
    ],
    [
        $errorMessage === '' ? 'display:none;' : '',
        htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'),
        $successMessage === '' ? 'display:none;' : '',
        htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($formDiscordUserId, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'),
    ],
    $content
);

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

$user_greeting = '';
if (isset($_SESSION['user']) && isset($_SESSION['user']['name'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountBtn, $logoutBtn, $html);
}

$html = str_replace('{user_greeting}', $user_greeting, $html);
echo $html;
?>
