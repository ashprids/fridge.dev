<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';

account_admin_require_admin();

$title = 'manage accounts';
$description = 'manage and configure all made accounts.';
$deletedUsername = trim((string)($_GET['deleted'] ?? ''));

$accountsData = account_admin_load_accounts();
$cards = [];

foreach ($accountsData['accounts'] as $account) {
    $username = isset($account['username']) ? (string)$account['username'] : 'unknown';
    if (fridg3_toast_is_reserved_username($username)) {
        continue;
    }

    $name = isset($account['name']) ? (string)$account['name'] : '';
    $isAdmin = !empty($account['isAdmin']);
    $allowedPages = array_values(array_map('strval', (array)($account['allowedPages'] ?? [])));
    $tags = [];

    if ($isAdmin) {
        $tags[] = '<span class="account-admin-badge">admin</span>';
    }
    if (trim((string)($account['emailAddress'] ?? '')) !== '') {
        $tags[] = '<span class="account-page-badge">email</span>';
    }
    foreach ($allowedPages as $page) {
        $tags[] = '<span class="account-page-badge">' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $cards[] = '<a class="account-admin-card" href="/account/admin/edit?username='
        . rawurlencode($username)
        . '"><strong>@'
        . htmlspecialchars($username, ENT_QUOTES, 'UTF-8')
        . '</strong><span>'
        . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        . '</span><span class="account-admin-meta">'
        . (empty($tags) ? 'no extra perms set' : implode('', $tags))
        . '</span></a>';
}

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';
$content = (string)file_get_contents($contentPath);
if ($deletedUsername !== '') {
    $content = '<div id="result">deleted @' . htmlspecialchars($deletedUsername, ENT_QUOTES, 'UTF-8') . '.</div><br>' . $content;
}
$content = str_replace(
    ['{account_count}', '{account_cards}'],
    [
        (string)count($cards),
        empty($cards) ? '<p>no accounts yet. kinda peaceful in here.</p>' : implode('', $cards),
    ],
    $content
);

account_admin_render_page($title, $description, $content);
