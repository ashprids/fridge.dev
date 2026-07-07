<?php
// Immediately log out when visiting this page
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

// Clear all session data
$_SESSION = [];

// Delete the session cookie if set
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => !empty($params['secure']),
        'httponly' => !empty($params['httponly']),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);

    if (!empty($params['domain'])) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}

// Clear admin flag cookie
$expiredAdminCookie = function_exists('fridg3_session_cookie_options')
    ? fridg3_session_cookie_options(time() - 3600, false)
    : [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => false,
        'httponly' => false,
        'samesite' => 'Lax'
    ];
setcookie('is_admin', '', $expiredAdminCookie);
setcookie('is_admin', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => !empty($expiredAdminCookie['secure']),
    'httponly' => false,
    'samesite' => 'Lax'
]);

// Destroy the session
session_destroy();

// Redirect to login page with flash message
header('Location: /account/login?logged_out=1');
exit;
?>
