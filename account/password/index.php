<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

$title = 'change password';
$description = 'change your fridge.dev account password.';

$password_message = '';
$message_type = ''; // 'success' or 'error'

// If not logged in, redirect to login
if (!isset($_SESSION['user'])) {
    header('Location: /account/login');
    exit;
}

// Generate CSRF token if not present
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process password change submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $password_message = 'invalid request. please try again.';
        $message_type = 'error';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate password
        if (strlen($new_password) < 8) {
            $password_message = 'password must be at least 8 characters.';
            $message_type = 'error';
        } else if ($new_password !== $confirm_password) {
            $password_message = 'passwords do not match.';
            $message_type = 'error';
        } else {
            // Hash new password
            $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
            
            // Load accounts from /data/accounts
            $accounts_path = realpath(dirname(__DIR__, 2) . '/data/accounts/accounts.json');
            if (!$accounts_path || !file_exists($accounts_path)) {
                $password_message = 'account system not available. try again later.';
                $message_type = 'error';
            } else {
                $accounts_data = json_decode(file_get_contents($accounts_path), true);
                $updated = false;
                
                if ($accounts_data && isset($accounts_data['accounts'])) {
                    foreach ($accounts_data['accounts'] as &$account) {
                        if ($account['username'] === $_SESSION['user']['username']) {
                            $account['password'] = $new_hash;
                            $account['mustResetPassword'] = false;
                            $updated = true;
                            break;
                        }
                    }
                }
                
                if ($updated && file_put_contents($accounts_path, json_encode($accounts_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                    $_SESSION['user']['mustResetPassword'] = false;
                    $password_message = 'password changed successfully.';
                    $message_type = 'success';
                } else {
                    $password_message = 'failed to update password. try again.';
                    $message_type = 'error';
                }
            }
        }
    }
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

// Start output buffering to safely inject PHP values into HTML
ob_start();
include($content_path);
$content = ob_get_clean();

// Pass message to content
$message_attr = htmlspecialchars($password_message, ENT_QUOTES, 'UTF-8');
$message_class = $message_type === 'success' ? 'success' : 'error';

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

// Inject message into the page
$html = str_replace('<div id="content">', '<div id="content" data-password-message="' . $message_attr . '" data-message-type="' . htmlspecialchars($message_class, ENT_QUOTES, 'UTF-8') . '">', $html);

echo $html;
?>
