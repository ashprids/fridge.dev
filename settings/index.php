<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

$title = 'settings';
$description = 'customize your preferences.';
$devBootstrapMessage = '';
$devBootstrapError = '';


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

$render_helper_path = find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

function settings_accounts_path(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
}

function settings_load_accounts_data(string $accountsPath): array {
    if (!is_file($accountsPath)) {
        return ['accounts' => []];
    }

    $accountsData = json_decode((string)@file_get_contents($accountsPath), true);
    if (!is_array($accountsData)) {
        return ['accounts' => []];
    }
    if (!isset($accountsData['accounts']) || !is_array($accountsData['accounts'])) {
        $accountsData['accounts'] = [];
    }

    return $accountsData;
}

function settings_has_admin_account(array $accountsData): bool {
    foreach ((array)($accountsData['accounts'] ?? []) as $account) {
        if (!empty($account['isAdmin'])) {
            return true;
        }
    }

    return false;
}

$accountsPath = settings_accounts_path();
$isLocalDevServer = function_exists('fridg3_is_local_dev_server') && fridg3_is_local_dev_server();
$accountsDataForBootstrap = settings_load_accounts_data($accountsPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_dev_admin'])) {
    if (!$isLocalDevServer) {
        $devBootstrapError = 'dev admin bootstrap is only available on local servers.';
    } elseif (settings_has_admin_account($accountsDataForBootstrap)) {
        $devBootstrapError = 'an admin account already exists.';
    } else {
        $adminAccount = [
            'username' => 'admin',
            'name' => 'Administrator',
            'password' => password_hash('', PASSWORD_BCRYPT),
            'isAdmin' => true,
            'mustResetPassword' => false,
            'allowedPages' => [],
        ];

        $updatedExistingAdminUsername = false;
        foreach ($accountsDataForBootstrap['accounts'] as $index => $account) {
            if (isset($account['username']) && strcasecmp((string)$account['username'], 'admin') === 0) {
                $accountsDataForBootstrap['accounts'][$index] = array_merge((array)$account, $adminAccount);
                $updatedExistingAdminUsername = true;
                break;
            }
        }
        if (!$updatedExistingAdminUsername) {
            $accountsDataForBootstrap['accounts'][] = $adminAccount;
        }

        $accountsDir = dirname($accountsPath);
        if (!is_dir($accountsDir) && !@mkdir($accountsDir, 0777, true)) {
            $devBootstrapError = 'could not create accounts directory.';
        } else {
            $encoded = json_encode($accountsDataForBootstrap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || @file_put_contents($accountsPath, $encoded, LOCK_EX) === false) {
                $devBootstrapError = 'could not write accounts.json.';
            } else {
                $devBootstrapMessage = 'created local admin account. username: admin / password: blank.';
            }
        }
    }

    $accountsDataForBootstrap = settings_load_accounts_data($accountsPath);
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

// Generate user greeting if logged in
$user_greeting = '';
if (isset($_SESSION['user'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    
    // Swap Account button to Logout
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
    $template = str_replace($accountBtn, $logoutBtn, $template);
}

// Replace user greeting placeholder
$template = str_replace('{user_greeting}', $user_greeting, $template);

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);

$isLoggedIn = isset($_SESSION['user']) && isset($_SESSION['user']['username']);
$isAdmin = isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;
$isToast = $isLoggedIn && fridg3_toast_is_current_user();
$hasLinkedDiscord = false;

if ($isLoggedIn) {
    if (is_file($accountsPath)) {
        $accountsData = json_decode((string)@file_get_contents($accountsPath), true);
        if (isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
            $currentUsername = (string)$_SESSION['user']['username'];
            foreach ($accountsData['accounts'] as $account) {
                if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
                    continue;
                }
                $hasLinkedDiscord = trim((string)($account['discordUserId'] ?? '')) !== '';
                break;
            }
        }
    }
}

if (!$isLoggedIn) {
    // Hide user-only controls when not logged in
    $content = str_replace('<span id="user-settings">', '<span id="user-settings" style="display:none">', $content);
}
if ($isToast) {
    $content = str_replace('<span id="user-settings">', '<span id="user-settings" style="display:none">', $content);
    $content = str_replace('<span id="appearance-settings">', '<span id="appearance-settings" style="display:none">', $content);
    $content = str_replace('<span id="toast-settings">', '<span id="toast-settings" data-toast-session="1">', $content);
}
if (!$isAdmin) {
    // Keep markup to avoid layout shifts; hide by default
    $content = str_replace('<span id="admin-settings">', '<span id="admin-settings" style="display:none">', $content);
}
if (!$isToast) {
    $content = str_replace('<span id="toast-settings">', '<span id="toast-settings" style="display:none">', $content);
}
if ($hasLinkedDiscord) {
    $activeDiscordButton = '<button id="form-button" type="button" data-tooltip="save your discord user ID to your account for notifications" onclick="window.location=\'/account/link-discord\'">link discord account</button>';
    $disabledDiscordButton = '<button id="form-button" type="button" class="form-button-disabled" data-tooltip="your discord account is already linked" disabled aria-disabled="true">link discord account</button>';
    $content = str_replace($activeDiscordButton, $disabledDiscordButton, $content);
}
$hasAdminAccountForBootstrap = settings_has_admin_account($accountsDataForBootstrap);
$devAdminBootstrap = '';
// This block uses the same developer-mode check that powers the dev-mode banner.
if ($isLocalDevServer) {
    $noticeHtml = '';
    if ($devBootstrapError !== '') {
        $noticeHtml = '<div id="error">' . htmlspecialchars($devBootstrapError, ENT_QUOTES, 'UTF-8') . '</div><br>';
    } elseif ($devBootstrapMessage !== '') {
        $noticeHtml = '<div id="result">' . htmlspecialchars($devBootstrapMessage, ENT_QUOTES, 'UTF-8') . '</div><br>';
    }

    $devAdminBootstrap = $noticeHtml
        . '<div class="dev-admin-bootstrap">'
        . '<h3>dev bootstrap</h3>'
        . '<h4>local data</h4>'
        . '<button id="form-button" type="button" data-action="dev-data-bootstrap" data-tooltip="deletes local /data, then downloads and installs the latest developer data zip from Google Drive">download latest dev data</button>';
    if (!$hasAdminAccountForBootstrap) {
        $devAdminBootstrap .= '<br><br>'
            . '<h4>no admin accounts exist</h4>'
            . '<form method="post" data-no-spa="1">'
            . '<button id="form-button" type="submit" name="create_dev_admin" value="1">create blank admin account</button>'
            . '</form>';
    }
    $devAdminBootstrap .= '</div><br><hr><br>';
} elseif ($devBootstrapError !== '') {
    $devAdminBootstrap = '<div id="error">' . htmlspecialchars($devBootstrapError, ENT_QUOTES, 'UTF-8') . '</div><br>';
} elseif ($devBootstrapMessage !== '') {
    $devAdminBootstrap = '<div id="result">' . htmlspecialchars($devBootstrapMessage, ENT_QUOTES, 'UTF-8') . '</div><br>';
}
$content = str_replace('{dev_admin_bootstrap}', $devAdminBootstrap, $content);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
