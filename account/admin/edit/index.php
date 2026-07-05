<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helpers.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

account_admin_require_admin();

$title = 'edit account';
$description = 'configure an existing fridge.dev account.';
$errorMessage = '';
$successMessage = '';
$generatedPassword = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selectedUsername = trim((string)($_GET['username'] ?? $_POST['original_username'] ?? ''));
$accountsData = account_admin_load_accounts();
$accountIndex = null;

foreach ($accountsData['accounts'] as $index => $account) {
    if (isset($account['username']) && (string)$account['username'] === $selectedUsername) {
        $accountIndex = $index;
        break;
    }
}

if ($selectedUsername === '' || $accountIndex === null) {
    http_response_code(404);
    account_admin_render_page(
        $title,
        $description,
        '<h1>account not found</h1><h2>that user either vanished or never existed. tragic.</h2><br><p><a href="/account/admin">back to account list</a></p>'
    );
    exit;
}

$account = $accountsData['accounts'][$accountIndex];
$managedKeys = ['username', 'name', 'password', 'isAdmin', 'mustResetPassword', 'allowedPages'];
$extraData = $account;
foreach ($managedKeys as $managedKey) {
    unset($extraData[$managedKey]);
}
if ($extraData === []) {
    $extraData = new stdClass();
}

$formUsername = (string)($account['username'] ?? '');
$formName = (string)($account['name'] ?? '');
$formIsAdmin = !empty($account['isAdmin']);
$allowedPages = array_values(array_map('strval', (array)($account['allowedPages'] ?? [])));
$formAllowFeed = in_array('feed', $allowedPages, true);
$formAllowJournal = in_array('journal', $allowedPages, true);
$formAllowComments = in_array('comments', $allowedPages, true);
$formAllowChat = in_array('chat', $allowedPages, true);
$extraJson = json_encode($extraData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($extraJson === false) {
    $extraJson = '{}';
}

function account_admin_parse_feed_post_file(string $path): ?array {
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $lines = preg_split("/(\r\n|\n|\r)/", $raw);
    $username = isset($lines[0]) ? ltrim(trim((string)$lines[0]), '@') : '';
    $body = count($lines) > 2 ? implode("\n", array_slice($lines, 2)) : '';
    if ($username === '') {
        return null;
    }

    return [
        'username' => $username,
        'body' => $body,
    ];
}

function account_admin_delete_feed_images_from_content(string $content): void {
    $imagesDir = fridg3_feed_images_dir();
    preg_match_all('/\[img=\/data\/images\/([^\]]+)\]/i', $content, $matches);
    foreach ($matches[1] ?? [] as $imageFile) {
        $imagePath = $imagesDir . DIRECTORY_SEPARATOR . basename((string)$imageFile);
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }
}

function account_admin_delete_feed_post_file(string $postPath): bool {
    $postData = account_admin_parse_feed_post_file($postPath);
    if ($postData === null) {
        return false;
    }

    $postId = pathinfo(basename($postPath), PATHINFO_FILENAME);
    account_admin_delete_feed_images_from_content((string)$postData['body']);
    foreach (fridg3_feed_load_replies($postId) as $reply) {
        account_admin_delete_feed_images_from_content((string)($reply['body'] ?? ''));
    }
    fridg3_feed_delete_post_voice_files($postId, (string)$postData['body']);
    @unlink(fridg3_feed_replies_dir() . DIRECTORY_SEPARATOR . $postId . '.json');
    return @unlink($postPath);
}

function account_admin_delete_user_feed_posts(string $username): array {
    $safeUsername = ltrim(trim($username), '@');
    $postsDir = fridg3_feed_posts_dir();
    $deleted = 0;
    $failed = 0;

    if ($safeUsername === '' || !is_dir($postsDir)) {
        return ['deleted' => 0, 'failed' => 0];
    }

    $files = glob($postsDir . DIRECTORY_SEPARATOR . '*.txt');
    if ($files === false) {
        return ['deleted' => 0, 'failed' => 0];
    }

    foreach ($files as $postPath) {
        $postData = account_admin_parse_feed_post_file($postPath);
        if ($postData === null || (string)$postData['username'] !== $safeUsername) {
            continue;
        }

        if (account_admin_delete_feed_post_file($postPath)) {
            $deleted++;
        } else {
            $failed++;
        }
    }

    return ['deleted' => $deleted, 'failed' => $failed];
}

function account_admin_verify_current_admin_password(string $password, array $accountsData): bool {
    $currentUsername = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
    if ($currentUsername === '') {
        return false;
    }

    foreach ($accountsData['accounts'] as $account) {
        if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
            continue;
        }
        if (empty($account['password'])) {
            return $password === '';
        }

        $storedPassword = (string)$account['password'];
        if (password_get_info($storedPassword)['algo'] !== null) {
            return password_verify($password, $storedPassword);
        }

        return hash_equals($storedPassword, $password);
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        $errorMessage = 'invalid request. please try again.';
    } else {
        $requestedAction = 'save';
        if (isset($_POST['delete_account'])) {
            $requestedAction = 'delete';
        } elseif (isset($_POST['account_action'])) {
            $requestedAction = (string)$_POST['account_action'];
        }

        $needsAdminPassword = in_array($requestedAction, ['delete', 'delete_feed_posts'], true);
        if ($needsAdminPassword && !account_admin_verify_current_admin_password((string)($_POST['admin_password'] ?? ''), $accountsData)) {
            $errorMessage = 'admin password did not match. destructive action cancelled.';
        } elseif ($requestedAction === 'delete') {
            $currentSessionUsername = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
            if ($currentSessionUsername === $selectedUsername) {
                $errorMessage = 'nice try. deleting the account you are currently using is blocked.';
            } else {
                array_splice($accountsData['accounts'], $accountIndex, 1);
                if (!account_admin_save_accounts($accountsData)) {
                    $saveError = account_admin_get_save_error();
                    $errorMessage = 'failed to delete account. '
                        . ($saveError !== '' ? $saveError : 'please try again.');
                } else {
                    header('Location: /account/admin?deleted=' . rawurlencode($selectedUsername));
                    exit;
                }
            }
        } elseif ($requestedAction === 'delete_feed_posts') {
            $deleteResult = account_admin_delete_user_feed_posts($selectedUsername);
            if ($deleteResult['failed'] > 0) {
                $errorMessage = 'deleted ' . $deleteResult['deleted'] . ' feed post(s), but '
                    . $deleteResult['failed'] . ' failed. check file permissions.';
            } elseif ($deleteResult['deleted'] === 0) {
                $successMessage = 'no feed posts found for @' . $selectedUsername . '.';
            } else {
                $successMessage = 'deleted ' . $deleteResult['deleted'] . ' feed post(s) for @' . $selectedUsername . '.';
            }
        } else {
            $formUsername = trim((string)($_POST['username'] ?? ''));
            $formName = trim((string)($_POST['name'] ?? ''));
            $formIsAdmin = isset($_POST['isAdmin']);
            $formAllowFeed = isset($_POST['allowFeed']);
            $formAllowJournal = isset($_POST['allowJournal']);
            $formAllowComments = isset($_POST['allowComments']);
            $formAllowChat = isset($_POST['allowChat']);
            $resetPassword = isset($_POST['resetPassword']);
            $extraJson = trim((string)($_POST['extra_json'] ?? '{}'));
            if ($extraJson === '') {
                $extraJson = '{}';
            }

            if ($formUsername === '' || $formName === '') {
                $errorMessage = 'username and name are required.';
            } elseif (fridg3_toast_is_reserved_username($formUsername)) {
                $errorMessage = 'toast is a reserved hardcoded account.';
            } elseif (!preg_match('/^[a-z0-9_-]{1,50}$/i', $formUsername)) {
                $errorMessage = 'username must be 1-50 characters (letters, numbers, underscores, hyphens).';
            } elseif (strlen($formName) > 100) {
                $errorMessage = 'name is too long (max 100 characters).';
            } else {
                $decodedExtra = json_decode($extraJson, true);
                if (!is_array($decodedExtra)) {
                    $errorMessage = 'other account values must be valid json.';
                } elseif ($decodedExtra !== [] && account_admin_is_list($decodedExtra)) {
                    $errorMessage = 'other account values json has to be an object, not a list.';
                } else {
                    foreach ($managedKeys as $managedKey) {
                        if (array_key_exists($managedKey, $decodedExtra)) {
                            unset($decodedExtra[$managedKey]);
                        }
                    }

                    foreach ($accountsData['accounts'] as $index => $existingAccount) {
                        if ($index === $accountIndex) {
                            continue;
                        }
                        if (isset($existingAccount['username']) && strcasecmp((string)$existingAccount['username'], $formUsername) === 0) {
                            $errorMessage = 'username already exists.';
                            break;
                        }
                    }

                    if ($errorMessage === '') {
                        $newAllowedPages = [];
                        if ($formAllowFeed) {
                            $newAllowedPages[] = 'feed';
                        }
                        if ($formAllowJournal) {
                            $newAllowedPages[] = 'journal';
                        }
                        if ($formAllowComments) {
                            $newAllowedPages[] = 'comments';
                        }
                        if ($formAllowChat) {
                            $newAllowedPages[] = 'chat';
                        }

                        $updatedAccount = $decodedExtra;
                        $updatedAccount['username'] = $formUsername;
                        $updatedAccount['name'] = $formName;
                        $updatedAccount['isAdmin'] = $formIsAdmin;
                        $updatedAccount['mustResetPassword'] = !empty($account['mustResetPassword']);
                        $updatedAccount['allowedPages'] = $newAllowedPages;
                        $updatedAccount['password'] = (string)($account['password'] ?? '');

                        if ($resetPassword) {
                            $generatedPassword = account_admin_generate_password(15);
                            $updatedAccount['password'] = password_hash($generatedPassword, PASSWORD_BCRYPT);
                            $updatedAccount['mustResetPassword'] = true;
                        }

                        $accountsData['accounts'][$accountIndex] = $updatedAccount;

                        if (!account_admin_save_accounts($accountsData)) {
                            $saveError = account_admin_get_save_error();
                            $errorMessage = 'failed to save account changes. '
                                . ($saveError !== '' ? $saveError : 'please try again.');
                            $generatedPassword = '';
                        } else {
                            if (isset($_SESSION['user']['username']) && (string)$_SESSION['user']['username'] === $selectedUsername) {
                                $_SESSION['user']['username'] = htmlspecialchars($formUsername, ENT_QUOTES, 'UTF-8');
                                $_SESSION['user']['name'] = htmlspecialchars($formName, ENT_QUOTES, 'UTF-8');
                                $_SESSION['user']['isAdmin'] = $formIsAdmin;
                                $_SESSION['user']['mustResetPassword'] = !empty($updatedAccount['mustResetPassword']);
                                $_SESSION['user']['allowedPages'] = array_map(static function ($page) {
                                    return htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8');
                                }, $newAllowedPages);
                            }

                            $selectedUsername = $formUsername;
                            $account = $updatedAccount;
                            $successMessage = $generatedPassword === ''
                                ? 'changes applied.'
                                : 'changes applied. new random password: ' . $generatedPassword;
                        }
                    }
                }
            }
        }
    }
}

$managedKeys = ['username', 'name', 'password', 'isAdmin', 'mustResetPassword', 'allowedPages'];
$extraData = $account;
foreach ($managedKeys as $managedKey) {
    unset($extraData[$managedKey]);
}
if ($extraData === []) {
    $extraData = new stdClass();
}
$extraJson = json_encode($extraData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($extraJson === false) {
    $extraJson = '{}';
}
$allowedPages = array_values(array_map('strval', (array)($account['allowedPages'] ?? [])));
$formAllowFeed = in_array('feed', $allowedPages, true);
$formAllowJournal = in_array('journal', $allowedPages, true);
$formAllowComments = in_array('comments', $allowedPages, true);
$formAllowChat = in_array('chat', $allowedPages, true);
$formIsAdmin = !empty($account['isAdmin']);
$formUsername = (string)($account['username'] ?? $formUsername);
$formName = (string)($account['name'] ?? $formName);

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
$content = (string)file_get_contents($contentPath);
$content = str_replace(
    [
        '{error_style}',
        '{error_message}',
        '{error_alert_message}',
        '{success_style}',
        '{success_message}',
        '{account_username}',
        '{original_username}',
        '{self_delete_disabled}',
        '{form_username}',
        '{form_name}',
        '{is_admin_checked}',
        '{allow_feed_checked}',
        '{allow_journal_checked}',
        '{allow_comments_checked}',
        '{allow_chat_checked}',
        '{reset_password_checked}',
        '{extra_json}',
        '{csrf_token}',
        '{account_name}',
        '{account_is_admin}',
        '{account_pages}',
    ],
    [
        $errorMessage === '' ? 'display:none;' : '',
        htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'),
        $successMessage === '' ? 'display:none;' : '',
        htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($selectedUsername, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($selectedUsername, ENT_QUOTES, 'UTF-8'),
        (isset($_SESSION['user']['username']) && (string)$_SESSION['user']['username'] === $selectedUsername) ? 'disabled' : '',
        htmlspecialchars($formUsername, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'),
        $formIsAdmin ? 'checked' : '',
        $formAllowFeed ? 'checked' : '',
        $formAllowJournal ? 'checked' : '',
        $formAllowComments ? 'checked' : '',
        $formAllowChat ? 'checked' : '',
        isset($_POST['resetPassword']) && $successMessage === '' ? 'checked' : '',
        htmlspecialchars($extraJson, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'),
        $formIsAdmin ? '1' : '0',
        htmlspecialchars(implode(',', $allowedPages), ENT_QUOTES, 'UTF-8'),
    ],
    $content
);

account_admin_render_page($title, $description, $content);
