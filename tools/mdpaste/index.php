<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if (!str_contains($contentType, 'application/json')) {
			mdp_json_response(['ok' => false, 'error' => 'send json, bestie.'], 415);
		}

		$raw = file_get_contents('php://input');
		$payload = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($payload)) {
			mdp_json_response(['ok' => false, 'error' => 'that json is cooked.'], 400);
		}

		$markdown = is_string($payload['markdown'] ?? null) ? $payload['markdown'] : '';
		$password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
		$hardBreaks = (bool)($payload['hardBreaks'] ?? false);
		$paste = mdp_create_paste($markdown, $password, $hardBreaks);
		$url = '/tools/mdpaste/s/' . rawurlencode((string)$paste['id']);

		mdp_json_response([
			'ok' => true,
			'id' => $paste['id'],
			'url' => $url,
			'expires_at' => date(DATE_ATOM, (int)$paste['expires_at']),
			'encrypted' => (bool)$paste['encrypted'],
		]);
	} catch (InvalidArgumentException $error) {
		mdp_json_response(['ok' => false, 'error' => $error->getMessage()], 400);
	} catch (Throwable $error) {
		mdp_json_response(['ok' => false, 'error' => 'server tripped over its shoelaces. try again.'], 500);
	}
}

$contentPath = __DIR__ . DIRECTORY_SEPARATOR . 'content.html';

if (!is_file($contentPath) || !is_readable($contentPath)) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'content.html not found. this shouldn\'t happen. please report this to me@fridge.dev.';
	exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($contentPath);
