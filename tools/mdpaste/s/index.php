<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib.php';

$id = mdp_share_id_from_request();
$paste = null;
$markdown = null;
$error = '';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$alreadyCleanUrl = is_string($requestPath) && preg_match('#^/tools/mdpaste/s/[a-fA-F0-9]{16}/?$#', $requestPath);
if ($id !== '' && isset($_GET['id']) && !$alreadyCleanUrl && $_SERVER['REQUEST_METHOD'] === 'GET') {
	header('Location: /tools/mdpaste/s/' . rawurlencode($id), true, 301);
	exit;
}

try {
	$paste = $id !== '' ? mdp_load_paste($id) : null;
} catch (Throwable $exception) {
	$paste = null;
}

if ($paste !== null && empty($paste['encrypted'])) {
	$markdown = mdp_decrypt_paste($paste, '');
}

if ($paste !== null && !empty($paste['encrypted']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
	$markdown = mdp_decrypt_paste($paste, $password);
	if ($markdown === null) {
		$error = 'wrong password. tragic, but recoverable.';
	}
}

$title = 'mdpaste | fridge.dev';
$description = 'a markdown file has been shared with you! view it here.';
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= mdp_h($title) ?></title>
	<meta name="description" content="<?= mdp_h($description) ?>">
	<meta name="robots" content="noindex,nofollow">
	<link rel="stylesheet" href="/tools/mdpaste/style.css">
	<link rel="icon" type="image/png" href="/resources/favicon-96x96.png" sizes="96x96">
	<link rel="shortcut icon" href="/resources/favicon-96x96.png">
	<link rel="apple-touch-icon" sizes="180x180" href="/resources/apple-touch-icon.png">
	<meta name="apple-mobile-web-app-title" content="fridge.dev">
	<link rel="manifest" href="/resources/site.webmanifest">
</head>
<body>
	<main class="page">
		<?php if ($paste === null): ?>
			<section class="unlock-panel">
				<a class="home-link" href="/tools/mdpaste/">mdpaste</a>
				<h1>not found</h1>
				<p>that paste is missing, expired, or never existed. brutal.</p>
			</section>
		<?php elseif ($markdown === null): ?>
			<section class="unlock-panel" aria-labelledby="unlock-title">
				<a class="home-link" href="/tools/mdpaste/">mdpaste</a>
				<h1 id="unlock-title">locked paste</h1>
				<p>this paste is encrypted. password goes in, markdown comes out.</p>
				<?php if ($error !== ''): ?>
					<p class="error-text"><?= mdp_h($error) ?></p>
				<?php endif; ?>
				<form class="unlock-form" method="post">
					<label class="field-label" for="password">password</label>
					<input class="password-input" id="password" name="password" type="password" autocomplete="current-password" autofocus required>
					<button class="btn" type="submit">unlock</button>
				</form>
			</section>
		<?php else: ?>
			<section class="view-panel" aria-labelledby="paste-title">
				<div class="panel-header">
					<h1 id="paste-title">mdpaste</h1>
					<a class="btn btn-secondary" href="/tools/mdpaste/">new paste</a>
				</div>
				<div class="view-meta">
					<span>created <?= mdp_h(date('Y-m-d H:i', (int)$paste['created_at'])) ?></span>
					<span>expires <?= mdp_h(date('Y-m-d H:i', (int)$paste['expires_at'])) ?></span>
					<?php if (!empty($paste['encrypted'])): ?>
						<span>encrypted</span>
					<?php endif; ?>
				</div>
				<article class="markdown-body">
					<?= mdp_render_markdown($markdown, !empty($paste['hard_breaks'])) ?>
				</article>
			</section>
		<?php endif; ?>
	</main>
</body>
</html>
