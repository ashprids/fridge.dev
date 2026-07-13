<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

$renderHelperPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'render.php';
if (is_file($renderHelperPath)) {
    require_once $renderHelperPath;
}

header('Content-Type: application/json');

// Helper to load accounts data
function load_accounts_data($accountsPath) {
    if (!is_file($accountsPath)) {
        return null;
    }
    $raw = @file_get_contents($accountsPath);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
        return null;
    }
    return $data;
}

function save_accounts_data($accountsPath, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return @file_put_contents($accountsPath, $json) !== false;
}

function is_truthy_setting($value) {
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return false;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true);
}

// Allow GET to fetch current user settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
        echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
        exit;
    }
    $username = (string)$_SESSION['user']['username'];
    $isToast = fridg3_toast_is_current_user();
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = $isToast ? ['accounts' => []] : load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }
    $result = [
        'ok' => true,
        'settings' => [
            'theme' => 'default',
            'glowIntensity' => null,
            'colors' => null,
            'onekoEnabled' => null,
            'reduceMotion' => null,
            'browserNotificationsEnabled' => null,
            'journalBrowserNotificationsEnabled' => null,
            'titleAnimation' => 'wobble',
            'titleAnimationAlways' => false,
            'titleAnimationDesync' => true,
        ],
    ];
    if ($isToast) {
        $personality = fridg3_toast_load_personality_config();
        $result['settings']['toastPersonalityJson'] = json_encode($personality, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    foreach ($data['accounts'] as $account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            if (isset($account['glowIntensity'])) {
                $result['settings']['glowIntensity'] = $account['glowIntensity'];
            }
            if (isset($account['theme'])) {
                $result['settings']['theme'] = function_exists('fridg3_normalize_theme_id')
                    ? fridg3_normalize_theme_id($account['theme'])
                    : 'default';
            }
            if (isset($account['colors']) && is_array($account['colors'])) {
                $result['settings']['colors'] = $account['colors'];
            }
            if (array_key_exists('onekoEnabled', $account)) {
                $result['settings']['onekoEnabled'] = is_truthy_setting($account['onekoEnabled']);
            }
            if (array_key_exists('reduceMotion', $account)) {
                $result['settings']['reduceMotion'] = is_truthy_setting($account['reduceMotion']);
            }
            if (array_key_exists('browserNotificationsEnabled', $account)) {
                $result['settings']['browserNotificationsEnabled'] = is_truthy_setting($account['browserNotificationsEnabled']);
            }
            if (array_key_exists('journalBrowserNotificationsEnabled', $account)) {
                $result['settings']['journalBrowserNotificationsEnabled'] = is_truthy_setting($account['journalBrowserNotificationsEnabled']);
            }
            if (isset($account['titleAnimation'])) {
                $animation = strtolower(trim((string)$account['titleAnimation']));
                if ($animation === 'orbit' || $animation === 'domino' || $animation === 'lava-lamp') {
                    $animation = 'bubble';
                }
                $animationMigrations = [
                    'tidal-wave' => 'slot-machine',
                    'accordion' => 'slot-machine',
                    'typewriter' => 'slot-machine',
                    'helicopter' => 'moonwalk',
                    'haunted' => 'moonwalk',
                    'juggle' => 'moonwalk',
                ];
                $animation = $animationMigrations[$animation] ?? $animation;
                if (in_array($animation, ['wobble', 'bounce', 'pinball', 'rubberhose', 'bubble', 'slot-machine', 'moonwalk', 'heartbeat'], true)) {
                    $result['settings']['titleAnimation'] = $animation;
                }
            }
            if (array_key_exists('titleAnimationAlways', $account)) {
                $result['settings']['titleAnimationAlways'] = is_truthy_setting($account['titleAnimationAlways']);
            }
            if (array_key_exists('titleAnimationDesync', $account)) {
                $result['settings']['titleAnimationDesync'] = is_truthy_setting($account['titleAnimationDesync']);
            }
            break;
        }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    // Not logged in; nothing to persist server-side
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$username = (string)$_SESSION['user']['username'];
$isAdmin = isset($_SESSION['user']['isAdmin']) && $_SESSION['user']['isAdmin'] === true;
$isToast = fridg3_toast_is_current_user();

$intensityProvided = array_key_exists('glowIntensity', $_POST);
$intensity = $intensityProvided ? strtolower(trim((string)$_POST['glowIntensity'])) : null;
if ($intensity === 'low' || $intensity === 'high') {
    $intensity = 'medium';
}

$themeProvided = array_key_exists('theme', $_POST);
$theme = $themeProvided ? (string)$_POST['theme'] : null;

$maintenanceProvided = array_key_exists('maintenanceMode', $_POST);
$maintenanceRaw = $maintenanceProvided ? (string)$_POST['maintenanceMode'] : null;

$reduceMotionProvided = array_key_exists('reduceMotion', $_POST);
$reduceMotionRaw = $reduceMotionProvided ? (string)$_POST['reduceMotion'] : null;

$onekoProvided = array_key_exists('onekoEnabled', $_POST);
$onekoRaw = $onekoProvided ? (string)$_POST['onekoEnabled'] : null;

$browserNotificationsProvided = array_key_exists('browserNotificationsEnabled', $_POST);
$browserNotificationsRaw = $browserNotificationsProvided ? (string)$_POST['browserNotificationsEnabled'] : null;

$journalBrowserNotificationsProvided = array_key_exists('journalBrowserNotificationsEnabled', $_POST);
$journalBrowserNotificationsRaw = $journalBrowserNotificationsProvided ? (string)$_POST['journalBrowserNotificationsEnabled'] : null;

$titleAnimationProvided = array_key_exists('titleAnimation', $_POST);
$titleAnimationRaw = $titleAnimationProvided ? strtolower(trim((string)$_POST['titleAnimation'])) : null;

$titleAnimationAlwaysProvided = array_key_exists('titleAnimationAlways', $_POST);
$titleAnimationAlwaysRaw = $titleAnimationAlwaysProvided ? (string)$_POST['titleAnimationAlways'] : null;

$titleAnimationDesyncProvided = array_key_exists('titleAnimationDesync', $_POST);
$titleAnimationDesyncRaw = $titleAnimationDesyncProvided ? (string)$_POST['titleAnimationDesync'] : null;

$toastPersonalityProvided = array_key_exists('toastPersonalityJson', $_POST);
$toastPersonalityRaw = $toastPersonalityProvided ? (string)$_POST['toastPersonalityJson'] : null;

$allowedIntensity = ['none', 'medium'];
$availableThemes = function_exists('fridg3_list_themes') ? fridg3_list_themes(dirname(__DIR__, 2)) : [];
$allowedThemes = array_merge(['default'], array_keys($availableThemes));
$colorFields = ['bg', 'fg', 'border', 'subtle', 'links'];
$themeColorFields = [
    'classic' => $colorFields,
    'ambercrt' => ['links'],
];

$errors = [];
$didWork = false;

if ($toastPersonalityProvided) {
    if (!$isToast) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $decoded = json_decode((string)$toastPersonalityRaw, true);
    if (!is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_toast_personality_json']);
        exit;
    }

    $personalityError = null;
    if (!fridg3_toast_save_personality_config($decoded, $personalityError)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_toast_personality',
            'message' => $personalityError ?: 'invalid personality json',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $didWork = true;
}

if ($onekoProvided) {
    $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
    $falsy  = ['0', 'false', 'no', 'n', 'off', 'disabled'];
    $lower = strtolower(trim((string)$onekoRaw));
    if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_oneko_value']);
        exit;
    }

    $onekoEnabled = in_array($lower, $truthy, true);
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            $account['onekoEnabled'] = $onekoEnabled;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

if ($browserNotificationsProvided) {
    $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
    $falsy  = ['0', 'false', 'no', 'n', 'off', 'disabled'];
    $lower = strtolower(trim((string)$browserNotificationsRaw));
    if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_browser_notifications_value']);
        exit;
    }

    $browserNotificationsEnabled = in_array($lower, $truthy, true);
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            $account['browserNotificationsEnabled'] = $browserNotificationsEnabled;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

if ($journalBrowserNotificationsProvided) {
    $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
    $falsy  = ['0', 'false', 'no', 'n', 'off', 'disabled'];
    $lower = strtolower(trim((string)$journalBrowserNotificationsRaw));
    if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_journal_browser_notifications_value']);
        exit;
    }

    $journalBrowserNotificationsEnabled = in_array($lower, $truthy, true);
    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            $account['journalBrowserNotificationsEnabled'] = $journalBrowserNotificationsEnabled;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

if ($reduceMotionProvided) {
    $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
    $falsy  = ['0', 'false', 'no', 'n', 'off', 'disabled'];
    $accessibilityValues = [];
    $accessibilityInputs = [
        'reduceMotion' => [$reduceMotionProvided, $reduceMotionRaw, 'invalid_reduce_motion_value'],
    ];

    foreach ($accessibilityInputs as $key => [$provided, $raw, $error]) {
        if (!$provided) continue;
        $lower = strtolower(trim((string)$raw));
        if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $error]);
            exit;
        }
        $accessibilityValues[$key] = in_array($lower, $truthy, true);
    }

    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            foreach ($accessibilityValues as $key => $value) {
                $account[$key] = $value;
            }
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

if ($titleAnimationProvided || $titleAnimationAlwaysProvided || $titleAnimationDesyncProvided) {
    $allowedTitleAnimations = ['wobble', 'bounce', 'pinball', 'rubberhose', 'bubble', 'slot-machine', 'moonwalk', 'heartbeat'];
    if ($titleAnimationProvided && !in_array($titleAnimationRaw, $allowedTitleAnimations, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_title_animation']);
        exit;
    }

    $titleAnimationAlways = null;
    if ($titleAnimationAlwaysProvided) {
        $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
        $falsy = ['0', 'false', 'no', 'n', 'off', 'disabled'];
        $lower = strtolower(trim((string)$titleAnimationAlwaysRaw));
        if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_title_animation_always_value']);
            exit;
        }
        $titleAnimationAlways = in_array($lower, $truthy, true);
    }

    $titleAnimationDesync = null;
    if ($titleAnimationDesyncProvided) {
        $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
        $falsy = ['0', 'false', 'no', 'n', 'off', 'disabled'];
        $lower = strtolower(trim((string)$titleAnimationDesyncRaw));
        if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_title_animation_desync_value']);
            exit;
        }
        $titleAnimationDesync = in_array($lower, $truthy, true);
    }

    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            if ($titleAnimationProvided) $account['titleAnimation'] = $titleAnimationRaw;
            if ($titleAnimationAlwaysProvided) $account['titleAnimationAlways'] = $titleAnimationAlways;
            if ($titleAnimationDesyncProvided) $account['titleAnimationDesync'] = $titleAnimationDesync;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

// Handle theme update (per-user)
if ($themeProvided) {
    $theme = function_exists('fridg3_normalize_theme_id') ? fridg3_normalize_theme_id($theme) : (string)$theme;
    if (!in_array($theme, $allowedThemes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_theme']);
        exit;
    }

    if (function_exists('fridg3_get_theme_cookie_options')) {
        setcookie('theme_pref', $theme, fridg3_get_theme_cookie_options());
        $_COOKIE['theme_pref'] = $theme;
    }

    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            $account['theme'] = $theme;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

// Handle glow intensity update (per-user)
if ($intensityProvided) {
    if (!in_array($intensity, $allowedIntensity, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_intensity']);
        exit;
    }

    $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    $data = load_accounts_data($accountsPath);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
        exit;
    }

    $updated = false;
    foreach ($data['accounts'] as &$account) {
        if (isset($account['username']) && (string)$account['username'] === $username) {
            $account['glowIntensity'] = $intensity;
            $updated = true;
            break;
        }
    }
    unset($account);

    if ($updated) {
        if (!save_accounts_data($accountsPath, $data)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'write_failed']);
            exit;
        }
        $didWork = true;
    }
}

// Handle color scheme update (per-user)
// Expect hex strings (#RRGGBB) for provided fields
if (!empty($_POST['colors']) && is_array($_POST['colors'])) {
    $colors = $_POST['colors'];
} else {
    // Accept flat fields colorBg, colorFg, etc.
    $colors = [];
    foreach ($colorFields as $field) {
        $key = 'color' . ucfirst($field);
        if (isset($_POST[$key])) {
            $colors[$field] = (string)$_POST[$key];
        }
    }
}

if (!empty($colors)) {
    $colorTheme = $themeProvided ? $theme : null;
    if ($colorTheme === null && isset($_COOKIE['theme_pref'])) {
        $colorTheme = function_exists('fridg3_normalize_theme_id')
            ? fridg3_normalize_theme_id($_COOKIE['theme_pref'])
            : (string)$_COOKIE['theme_pref'];
    }
    if ($colorTheme === null) {
        $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
        $data = load_accounts_data($accountsPath);
        if ($data !== null) {
            foreach ($data['accounts'] as $account) {
                if (isset($account['username']) && (string)$account['username'] === $username && isset($account['theme'])) {
                    $colorTheme = function_exists('fridg3_normalize_theme_id')
                        ? fridg3_normalize_theme_id($account['theme'])
                        : (string)$account['theme'];
                    break;
                }
            }
        }
    }
}

if (!empty($colors) && isset($themeColorFields[$colorTheme])) {
    $allowedColorFields = $themeColorFields[$colorTheme];
    $validColors = [];
    foreach ($colors as $k => $v) {
        if (!in_array($k, $allowedColorFields, true)) continue;
        $hex = trim((string)$v);
        if (!preg_match('/^#([0-9a-fA-F]{6})$/', $hex)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_color_' . $k]);
            exit;
        }
        $validColors[$k] = strtoupper($hex);
    }

    if (!empty($validColors)) {
        $accountsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
        $data = load_accounts_data($accountsPath);
        if ($data === null) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'accounts_invalid']);
            exit;
        }
        $updated = false;
        foreach ($data['accounts'] as &$account) {
            if (isset($account['username']) && (string)$account['username'] === $username) {
                $account['colors'] = array_merge($account['colors'] ?? [], $validColors);
                $updated = true;
                break;
            }
        }
        unset($account);

        if ($updated) {
            if (!save_accounts_data($accountsPath, $data)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'write_failed']);
                exit;
            }
            $didWork = true;
        }
    }
}

// Handle maintenance mode toggle (admin only)
if ($maintenanceProvided) {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $truthy = ['1', 'true', 'yes', 'y', 'on', 'enabled'];
    $falsy  = ['0', 'false', 'no', 'n', 'off', 'disabled'];
    $lower = strtolower(trim((string)$maintenanceRaw));
    if (!in_array($lower, $truthy, true) && !in_array($lower, $falsy, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_maintenance_value']);
        exit;
    }
    $enabled = in_array($lower, $truthy, true);

    $etcDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc';
    if (!is_dir($etcDir)) {
        @mkdir($etcDir, 0775, true);
    }
    $wipPath = $etcDir . DIRECTORY_SEPARATOR . 'wip';
    if (@file_put_contents($wipPath, $enabled ? 'true' : 'false') === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'wip_write_failed']);
        exit;
    }
    $didWork = true;
}

if ($didWork || $intensityProvided || $themeProvided || $maintenanceProvided || $reduceMotionProvided || $onekoProvided || $browserNotificationsProvided || $journalBrowserNotificationsProvided || $toastPersonalityProvided) {
    echo json_encode(['ok' => true]);
    exit;
}

// Nothing to do
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'no_updates']);
exit;
