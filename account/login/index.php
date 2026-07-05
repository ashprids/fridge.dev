<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

$title = 'login';
$description = 'log into your fridge.dev account.';

$login_error = '';
$login_success = false;
$login_maintenance_denied = false;
$maintenance_mode = function_exists('fridg3_session_work_in_progress_enabled')
    && fridg3_session_work_in_progress_enabled();

// If already logged in, redirect to homepage
if (isset($_SESSION['user'])) {
    header('Location: /');
    exit;
}

// Generate CSRF token if not present
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $login_error = 'invalid request. please try again.';
    } else {
        // Input validation and sanitization
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Path for tracking failed login attempts per username
        $dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0777, true);
        }
        $attemptsFile = $dataDir . DIRECTORY_SEPARATOR . 'login_attempts.json';
        $maxAttempts = 5;
        $windowSeconds = 15 * 60; // 15 minutes

        // Helper to load and normalize attempts data
        $attemptsData = [];
        if (file_exists($attemptsFile)) {
            $json = @file_get_contents($attemptsFile);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $attemptsData = $decoded;
            }
        }

        // Validate username format (alphanumeric and basic chars only)
        if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $username)) {
            $login_error = 'invalid username format.';
        } else {
            // Throttle: block if too many failed attempts from this client in the last 15 minutes
            $now = time();
            $clientKey = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (!isset($attemptsData[$clientKey]) || !is_array($attemptsData[$clientKey])) {
                $attemptsData[$clientKey] = [];
            }

            // Keep only attempts within the time window
            $attemptsData[$clientKey] = array_values(array_filter($attemptsData[$clientKey], function($ts) use ($now, $windowSeconds) {
                return is_int($ts) && ($ts >= $now - $windowSeconds);
            }));

            // Persist pruning (with file lock to avoid race conditions)
            @file_put_contents($attemptsFile, json_encode($attemptsData, JSON_UNESCAPED_SLASHES), LOCK_EX);

            if (count($attemptsData[$clientKey]) >= $maxAttempts) {
                $login_error = 'too many attempts. try again later.';
            }
        }

        if ($login_error === '') {
    
            if (fridg3_toast_is_reserved_username($username)) {
                $toastAdminUsername = trim((string)($_POST['toast_admin_username'] ?? ''));
                $toastAdminPassword = (string)($_POST['toast_admin_password'] ?? '');

                if (fridg3_toast_verify_admin_credentials($toastAdminUsername, $toastAdminPassword)) {
                    $toastUser = fridg3_toast_session_user();
                    if ($maintenance_mode && empty($toastUser['isAdmin'])) {
                        $login_maintenance_denied = true;
                        $login_error = 'maintenance mode is admin-only right now.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user'] = $toastUser;
                        fridg3_refresh_is_admin_cookie(false);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $login_success = true;
                        header('Location: /');
                        exit();
                    }
                }
            } else {
                // Load accounts
                $accounts_path = realpath(dirname(__DIR__, 2) . '/data/accounts/accounts.json');
                if (!$accounts_path || !file_exists($accounts_path)) {
                    $login_error = 'account system not available at the moment... try again later?';
                } else {
                    $accounts_data = json_decode(file_get_contents($accounts_path), true);
                    $found = false;
                
                if ($accounts_data && isset($accounts_data['accounts'])) {
                    foreach ($accounts_data['accounts'] as $account) {
                        if ($account['username'] === $username) {
                            $found = true;
                            // Check password (support both hashed and plain for migration)
                            $password_valid = false;
                            if (empty($account['password'])) {
                                // Empty password = no auth required
                                $password_valid = true;
                            } elseif (password_get_info($account['password'])['algo'] !== null) {
                                // Hashed password
                                $password_valid = password_verify($password, $account['password']);
                            } else {
                                // Plain password (insecure, for migration only)
                                $password_valid = ($account['password'] === $password);
                            }
                            
                            if ($password_valid) {
                                if ($maintenance_mode && empty($account['isAdmin'])) {
                                    $login_maintenance_denied = true;
                                    $login_error = 'maintenance mode is admin-only right now.';
                                    break;
                                }

                                // Regenerate session ID to prevent fixation
                                session_regenerate_id(true);
                                
                                // Store sanitized user data
                                $_SESSION['user'] = [
                                    'username' => htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8'),
                                    'name' => htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'),
                                    'isAdmin' => (bool)($account['isAdmin'] ?? false),
                                    'mustResetPassword' => !empty($account['mustResetPassword']),
                                    'emailAddress' => htmlspecialchars((string)($account['emailAddress'] ?? ''), ENT_QUOTES, 'UTF-8'),
                                    'allowedPages' => array_map(function ($page) {
                                        return htmlspecialchars($page, ENT_QUOTES, 'UTF-8');
                                    }, (array)($account['allowedPages'] ?? []))
                                ];

                                // Expose admin flag to client for WIP bypass (non-HttpOnly)
                                fridg3_refresh_is_admin_cookie($_SESSION['user']['isAdmin']);

                                // Rotate CSRF token after a successful login
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                                
                                $login_success = true;
                                header('Location: /');
                                exit();
                            }
                            break;
                        }
                    }
                }
                
                }
            }

            // Generic error message to prevent username enumeration
            if ($login_error === '' && !$login_success) {
                $login_error = 'incorrect username or password';

                // Record failed attempt for this client
                $now = time();
                $clientKey = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                if (!isset($attemptsData[$clientKey]) || !is_array($attemptsData[$clientKey])) {
                    $attemptsData[$clientKey] = [];
                }
                $attemptsData[$clientKey][] = $now;
                @file_put_contents($attemptsFile, json_encode($attemptsData, JSON_UNESCAPED_SLASHES), LOCK_EX);
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

$template_name = $maintenance_mode
    ? 'error/wip/template.html'
    : (function_exists('get_preferred_template_name')
        ? get_preferred_template_name(__DIR__)
        : 'template.html');
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

// Pass login error to JS via data attribute
$error_attr = htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8');
$info_msg = (!empty($_GET['logged_out']) && $_GET['logged_out'] === '1') ? 'you\'ve been logged out.' : '';
$info_attr = htmlspecialchars($info_msg, ENT_QUOTES, 'UTF-8');
$maintenance_denied_attr = $login_maintenance_denied ? '1' : '0';

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

// Inject login error/info into the page
$html = str_replace('<div id="content">', '<div id="content" data-login-error="' . $error_attr . '" data-login-info="' . $info_attr . '" data-login-maintenance-denied="' . $maintenance_denied_attr . '">', $html);

echo $html;
?>
