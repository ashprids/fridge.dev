<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';

const MDP_MAX_BYTES = 524288;
const MDP_TTL_SECONDS = 2592000;
const MDP_KDF_ITERATIONS = 210000;

function mdp_find_up(string $filename): ?string
{
	$dir = __DIR__;
	$previous = '';

	while ($dir !== $previous) {
		$path = $dir . DIRECTORY_SEPARATOR . $filename;
		if (file_exists($path)) {
			return $path;
		}
		$previous = $dir;
		$dir = dirname($dir);
	}

	return null;
}

function mdp_data_dir(): string
{
	$dataRoot = mdp_find_up('data');
	if ($dataRoot === null) {
		$dataRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
	}

	$dir = $dataRoot . DIRECTORY_SEPARATOR . 'mdpaste';
	if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
		throw new RuntimeException('could not create paste storage directory.');
	}

	return $dir;
}

function mdp_cleanup_expired(): void
{
	$dir = mdp_data_dir();
	foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
		$raw = file_get_contents($path);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data) || (int)($data['expires_at'] ?? 0) < time()) {
			@unlink($path);
		}
	}
}

function mdp_generate_id(): string
{
	return bin2hex(random_bytes(8));
}

function mdp_paste_path(string $id): string
{
	if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
		throw new InvalidArgumentException('invalid paste id.');
	}

	return mdp_data_dir() . DIRECTORY_SEPARATOR . $id . '.json';
}

function mdp_normalize_id(string $id): string
{
	$id = strtolower(trim($id));
	return preg_match('/^[a-f0-9]{16}$/', $id) ? $id : '';
}

function mdp_share_id_from_request(): string
{
	if (is_string($_GET['id'] ?? null)) {
		return mdp_normalize_id($_GET['id']);
	}

	$pathInfo = $_SERVER['PATH_INFO'] ?? '';
	if (is_string($pathInfo) && preg_match('#^/([a-fA-F0-9]{16})/?$#', $pathInfo, $match)) {
		return strtolower($match[1]);
	}

	$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
	if (is_string($path) && preg_match('#/tools/mdpaste/s/([a-fA-F0-9]{16})/?$#', $path, $match)) {
		return strtolower($match[1]);
	}

	return '';
}

function mdp_create_paste(string $markdown, string $password, bool $hardBreaks = false): array
{
	$markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
	if (trim($markdown) === '') {
		throw new InvalidArgumentException('paste something first. blank notes are just expensive air.');
	}
	if (strlen($markdown) > MDP_MAX_BYTES) {
		throw new InvalidArgumentException('that note is too chunky. keep it under 512 KiB.');
	}

	mdp_cleanup_expired();

	do {
		$id = mdp_generate_id();
		$path = mdp_paste_path($id);
	} while (file_exists($path));

	$now = time();
	$record = [
		'id' => $id,
		'version' => 1,
		'created_at' => $now,
		'expires_at' => $now + MDP_TTL_SECONDS,
		'encrypted' => $password !== '',
		'hard_breaks' => $hardBreaks,
	];

	if ($password !== '') {
		$salt = random_bytes(16);
		$nonce = random_bytes(12);
		$key = hash_pbkdf2('sha256', $password, $salt, MDP_KDF_ITERATIONS, 32, true);
		$tag = '';
		$ciphertext = openssl_encrypt($markdown, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
		if ($ciphertext === false) {
			throw new RuntimeException('encryption failed.');
		}

		$record['crypto'] = [
			'cipher' => 'aes-256-gcm',
			'kdf' => 'pbkdf2-sha256',
			'iterations' => MDP_KDF_ITERATIONS,
			'salt' => base64_encode($salt),
			'nonce' => base64_encode($nonce),
			'tag' => base64_encode($tag),
			'ciphertext' => base64_encode($ciphertext),
		];
	} else {
		$record['markdown'] = $markdown;
	}

	$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if (!is_string($json)) {
		throw new RuntimeException('could not encode paste.');
	}
	if (file_put_contents($path, $json, LOCK_EX) === false) {
		throw new RuntimeException('could not save paste.');
	}
	chmod($path, 0640);

	return $record;
}

function mdp_load_paste(string $id): ?array
{
	mdp_cleanup_expired();

	$path = mdp_paste_path($id);
	if (!is_file($path) || !is_readable($path)) {
		return null;
	}

	$raw = file_get_contents($path);
	$data = is_string($raw) ? json_decode($raw, true) : null;
	if (!is_array($data)) {
		return null;
	}
	if ((int)($data['expires_at'] ?? 0) < time()) {
		@unlink($path);
		return null;
	}

	return $data;
}

function mdp_decrypt_paste(array $paste, string $password): ?string
{
	if (empty($paste['encrypted'])) {
		return is_string($paste['markdown'] ?? null) ? $paste['markdown'] : '';
	}

	$crypto = is_array($paste['crypto'] ?? null) ? $paste['crypto'] : [];
	$salt = base64_decode((string)($crypto['salt'] ?? ''), true);
	$nonce = base64_decode((string)($crypto['nonce'] ?? ''), true);
	$tag = base64_decode((string)($crypto['tag'] ?? ''), true);
	$ciphertext = base64_decode((string)($crypto['ciphertext'] ?? ''), true);
	if (!is_string($salt) || !is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
		return null;
	}

	$iterations = (int)($crypto['iterations'] ?? MDP_KDF_ITERATIONS);
	$key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
	$markdown = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

	return is_string($markdown) ? $markdown : null;
}

function mdp_h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mdp_safe_url(string $url): ?string
{
	$url = trim($url);
	if ($url === '') {
		return null;
	}
	if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/data/images/')) {
		return $url;
	}

	return null;
}

function mdp_safe_obsidian_image_url(string $target): ?string
{
	$target = trim($target);
	$target = preg_replace('/\|.*$/', '', $target) ?? $target;
	$target = trim($target);
	$safe = mdp_safe_url($target);
	if ($safe !== null) {
		return $safe;
	}
	if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]+\.(png|jpe?g|gif|webp|svg)$/i', $target)) {
		return '/data/images/' . str_replace('%2F', '/', rawurlencode($target));
	}

	return null;
}

function mdp_inline(string $text): string
{
	$out = mdp_h($text);

	$out = preg_replace_callback('/!\[\[([^\]]+)\]\]/', static function (array $match): string {
		$url = mdp_safe_obsidian_image_url(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $match[0];
		}
		return '<img src="' . mdp_h($url) . '" alt="">';
	}, $out) ?? $out;

	$out = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $match): string {
		$url = mdp_safe_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $match[0];
		}
		return '<img src="' . mdp_h($url) . '" alt="' . mdp_h(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')) . '">';
	}, $out) ?? $out;

	$out = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $match): string {
		$url = mdp_safe_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $match[0];
		}
		return '<a href="' . mdp_h($url) . '" target="_blank" rel="noreferrer">' . $match[1] . '</a>';
	}, $out) ?? $out;

	$patterns = [
		'/`([^`]+)`/' => '<code>$1</code>',
		'/\*\*([^*]+)\*\*/' => '<strong>$1</strong>',
		'/\*([^*]+)\*/' => '<em>$1</em>',
		'/~~([^~]+)~~/' => '<del>$1</del>',
	];

	return preg_replace(array_keys($patterns), array_values($patterns), $out) ?? $out;
}

function mdp_render_markdown(string $markdown, bool $hardBreaks = false): string
{
	$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
	$html = [];
	$paragraph = [];
	$code = [];
	$inCode = false;
	$inUl = false;
	$inOl = false;
	$table = [];

	$flushParagraph = static function () use (&$paragraph, &$html, $hardBreaks): void {
		if ($paragraph === []) {
			return;
		}
		$separator = $hardBreaks ? "\n" : ' ';
		$html[] = '<p>' . str_replace("\n", '<br>', mdp_inline(implode($separator, $paragraph))) . '</p>';
		$paragraph = [];
	};
	$closeLists = static function () use (&$inUl, &$inOl, &$html): void {
		if ($inUl) {
			$html[] = '</ul>';
			$inUl = false;
		}
		if ($inOl) {
			$html[] = '</ol>';
			$inOl = false;
		}
	};
	$flushTable = static function () use (&$table, &$html): void {
		if (count($table) < 2) {
			foreach ($table as $line) {
				$html[] = '<p>' . mdp_inline($line) . '</p>';
			}
			$table = [];
			return;
		}
		$header = array_map('trim', explode('|', trim($table[0], '|')));
		$align = trim($table[1]);
		if (!preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/', $align)) {
			foreach ($table as $line) {
				$html[] = '<p>' . mdp_inline($line) . '</p>';
			}
			$table = [];
			return;
		}
		$html[] = '<table><thead><tr>';
		foreach ($header as $cell) {
			$html[] = '<th>' . mdp_inline($cell) . '</th>';
		}
		$html[] = '</tr></thead><tbody>';
		foreach (array_slice($table, 2) as $row) {
			$cells = array_map('trim', explode('|', trim($row, '|')));
			$html[] = '<tr>';
			foreach ($cells as $cell) {
				$html[] = '<td>' . mdp_inline($cell) . '</td>';
			}
			$html[] = '</tr>';
		}
		$html[] = '</tbody></table>';
		$table = [];
	};

	foreach ($lines as $line) {
		$trimmed = trim($line);

		if (str_starts_with($trimmed, '```')) {
			$flushParagraph();
			$closeLists();
			$flushTable();
			if ($inCode) {
				$html[] = '<pre><code>' . mdp_h(implode("\n", $code)) . '</code></pre>';
				$code = [];
				$inCode = false;
			} else {
				$inCode = true;
			}
			continue;
		}

		if ($inCode) {
			$code[] = $line;
			continue;
		}

		if ($trimmed === '') {
			$flushParagraph();
			$closeLists();
			$flushTable();
			continue;
		}

		if (str_contains($trimmed, '|')) {
			$flushParagraph();
			$closeLists();
			$table[] = $trimmed;
			continue;
		}
		$flushTable();

		if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $match)) {
			$flushParagraph();
			$closeLists();
			$level = strlen($match[1]);
			$html[] = '<h' . $level . '>' . mdp_inline($match[2]) . '</h' . $level . '>';
			continue;
		}

		if ($trimmed === '---' || $trimmed === '***') {
			$flushParagraph();
			$closeLists();
			$html[] = '<hr>';
			continue;
		}

		if (preg_match('/^>\s?(.*)$/', $trimmed, $match)) {
			$flushParagraph();
			$closeLists();
			$html[] = '<blockquote><p>' . mdp_inline($match[1]) . '</p></blockquote>';
			continue;
		}

		if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $match)) {
			$flushParagraph();
			if ($inOl) {
				$html[] = '</ol>';
				$inOl = false;
			}
			if (!$inUl) {
				$html[] = '<ul>';
				$inUl = true;
			}
			$item = $match[1];
			$checkbox = '';
			if (preg_match('/^\[(x| )\]\s+(.*)$/i', $item, $task)) {
				$checked = strtolower($task[1]) === 'x' ? ' checked' : '';
				$checkbox = '<input type="checkbox" disabled' . $checked . '> ';
				$item = $task[2];
			}
			$html[] = '<li>' . $checkbox . mdp_inline($item) . '</li>';
			continue;
		}

		if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $match)) {
			$flushParagraph();
			if ($inUl) {
				$html[] = '</ul>';
				$inUl = false;
			}
			if (!$inOl) {
				$html[] = '<ol>';
				$inOl = true;
			}
			$html[] = '<li>' . mdp_inline($match[1]) . '</li>';
			continue;
		}

		$paragraph[] = $trimmed;
	}

	$flushParagraph();
	$closeLists();
	$flushTable();
	if ($inCode) {
		$html[] = '<pre><code>' . mdp_h(implode("\n", $code)) . '</code></pre>';
	}

	return $html === [] ? '<p>nothing here.</p>' : implode("\n", $html);
}

function mdp_json_response(array $payload, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit;
}
