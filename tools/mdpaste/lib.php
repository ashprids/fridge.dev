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
	if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/') || str_starts_with($url, '#') || preg_match('#^(?:(?:\./|\.\./)?[A-Za-z0-9][A-Za-z0-9._/-]*)$#', $url)) {
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
	$tokens = [];
	$protect = static function (string $html) use (&$tokens): string {
		$key = '@@MDP' . count($tokens) . '@@';
		$tokens[$key] = $html;
		return $key;
	};
	$text = preg_replace_callback('/<((?:https?:\/\/)?(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}(?:\/[^\s>]*)?)>/', static function (array $match) use ($protect): string {
		$url = preg_match('#^https?://#i', $match[1]) ? $match[1] : 'https://' . $match[1];
		return $protect('<a href="' . mdp_h($url) . '" target="_blank" rel="noopener noreferrer">' . mdp_h($match[1]) . '</a>');
	}, $text) ?? $text;
	$text = preg_replace_callback('/<([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})>/i', static fn(array $match): string => $protect('<a href="mailto:' . mdp_h($match[1]) . '">' . mdp_h($match[1]) . '</a>'), $text) ?? $text;
	$text = preg_replace_callback('/\bmailto:([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})\b/i', static fn(array $match): string => $protect('<a href="mailto:' . mdp_h($match[1]) . '">' . mdp_h($match[1]) . '</a>'), $text) ?? $text;
	$text = preg_replace_callback('/<\/?([a-z][a-z0-9]*)\b([^>]*)>/i', static function (array $match) use ($protect): string {
		$tag = strtolower($match[1]);
		$allowed = ['u', 'mark', 'del', 'ins', 'sub', 'sup', 'small', 'kbd', 'abbr', 'a', 'img', 'br', 'details', 'summary', 'ruby', 'rp', 'rt', 'span', 'div', 'p', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'samp', 'var'];
		if (!in_array($tag, $allowed, true)) {
			return $protect(mdp_h($match[0]));
		}
		if (str_starts_with($match[0], '</')) {
			return $protect('</' . $tag . '>');
		}
		$attrs = '';
		preg_match_all('/\b([a-z][a-z0-9-]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $match[2], $attributes, PREG_SET_ORDER);
		$allowedAttrs = ['title', 'id', 'class', 'lang', 'dir', 'align', 'width', 'height', 'open', 'href', 'src', 'alt', 'style'];
		foreach ($attributes as $attribute) {
			$name = strtolower($attribute[1]);
			if (!in_array($name, $allowedAttrs, true)) continue;
			$value = trim($attribute[2], "\"'");
			if (in_array($name, ['href', 'src'], true)) {
				$safeUrl = mdp_safe_url(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
				if ($safeUrl === null) continue;
				$value = $safeUrl;
			}
			if ($name === 'dir' && !in_array(strtolower($value), ['ltr', 'rtl', 'auto'], true)) continue;
			if (in_array($name, ['width', 'height'], true) && !preg_match('/^\d{1,4}%?$/', $value)) continue;
			if ($name === 'style') {
				$safeDeclarations = [];
				foreach (explode(';', $value) as $declaration) {
					if (!str_contains($declaration, ':')) continue;
					[$property, $styleValue] = array_map('trim', explode(':', $declaration, 2));
					if (!in_array(strtolower($property), ['color', 'background-color', 'text-align', 'width', 'height', 'max-width'], true)) continue;
					if (!preg_match('/^[#(),.%\sa-zA-Z0-9-]+$/', $styleValue)) continue;
					$safeDeclarations[] = strtolower($property) . ': ' . $styleValue;
				}
				if ($safeDeclarations === []) continue;
				$value = implode('; ', $safeDeclarations);
			}
			if ($tag === 'abbr' && $name === 'title') $name = 'data-tooltip';
			$attrs .= ' ' . $name . '="' . mdp_h($value) . '"';
		}
		if ($tag === 'details' && preg_match('/\bopen\b/i', $match[2])) $attrs .= ' open';
		if ($tag === 'a' && preg_match('/\shref="https?:\/\//i', $attrs)) $attrs .= ' target="_blank" rel="noopener noreferrer"';
		return $protect('<' . $tag . $attrs . ($tag === 'br' || $tag === 'img' ? '>' : '>'));
	}, $text) ?? $text;
	$text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
	$text = preg_replace_callback('/\\\\([\\`*_{}\[\]()#+\-.!>|])/', static fn(array $match): string => $protect(mdp_h($match[1])), $text) ?? $text;
	$out = mdp_h($text);
	$out = strtr($out, ['&amp;amp;' => '&amp;', '&amp;lt;' => '&lt;', '&amp;gt;' => '&gt;', '&amp;copy;' => '&copy;', '&amp;nbsp;' => '&nbsp;']);
	$out = preg_replace_callback('/(`{1,})(.+?)\1/', static function (array $match) use ($protect): string {
		$value = $match[2];
		if (strlen($match[1]) > 1 && str_starts_with($value, ' ') && str_ends_with($value, ' ')) $value = substr($value, 1, -1);
		return $protect('<code>' . mdp_h(html_entity_decode($value, ENT_QUOTES, 'UTF-8')) . '</code>');
	}, $out) ?? $out;
	$out = preg_replace_callback('/(?<!\S)!fa[ \t]+(solid|regular|brands)[ \t]+([a-z0-9][a-z0-9-]*)\b/i', static function (array $match) use ($protect): string {
		return $protect('<i class="fa-' . strtolower($match[1]) . ' fa-' . strtolower($match[2]) . '"></i>');
	}, $out) ?? $out;
	$out = preg_replace_callback('/(?<!\S)!frdg\b/i', static fn(): string => $protect('<img class="markdown-frdg-icon no-image-viewer" src="/resources/favicon.svg" alt="fridge.dev">'), $out) ?? $out;
	$out = preg_replace_callback('/\[!\[([^\]]*)\]\(([^)\s]+)\)\]\(([^)\s]+)\)/', static function (array $match) use ($protect): string {
		$imageUrl = mdp_safe_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
		$linkUrl = mdp_safe_url(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));
		if ($imageUrl === null || $linkUrl === null) return $match[0];
		return $protect('<a href="' . mdp_h($linkUrl) . '" target="_blank" rel="noopener noreferrer"><img src="' . mdp_h($imageUrl) . '" alt="' . mdp_h(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')) . '"></a>');
	}, $out) ?? $out;

	$out = preg_replace_callback('/!\[\[([^\]]+)\]\]/', static function (array $match) use ($protect): string {
		$url = mdp_safe_obsidian_image_url(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $protect('<span class="mdpaste-embed">' . mdp_h($match[1]) . '</span>');
		}
		return $protect('<img src="' . mdp_h($url) . '" alt="">');
	}, $out) ?? $out;

	$out = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(?:&quot;([^&]*)&quot;|&#039;([^&]?)&#039;))?\)(?:\{width=(\d{1,3}%)\})?/', static function (array $match) use ($protect): string {
		$url = mdp_safe_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $match[0];
		}
		$width = isset($match[5]) && $match[5] !== '' ? ' width="' . mdp_h($match[5]) . '"' : '';
		return $protect('<img src="' . mdp_h($url) . '" alt="' . mdp_h(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')) . '"' . $width . '>');
	}, $out) ?? $out;

	$out = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+(?:&quot;([^&]*)&quot;|&#039;([^&]?)&#039;))?\)/', static function (array $match) use ($protect): string {
		$url = mdp_safe_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
		if ($url === null) {
			return $match[0];
		}
		$external = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
		return $protect('<a href="' . mdp_h($url) . '"' . $external . '>' . $match[1] . '</a>');
	}, $out) ?? $out;
	$out = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', static function (array $match) use ($protect): string {
		$label = $match[2] ?? $match[1];
		return $protect('<span class="mdpaste-wiki-link" title="' . mdp_h($match[1]) . '">' . mdp_h($label) . '</span>');
	}, $out) ?? $out;

	$patterns = [
		'/(?:\*\*\*|___)(.+?)(?:\*\*\*|___)/' => '<strong><em>$1</em></strong>',
		'/(?:\*\*|__)(.+?)(?:\*\*|__)/' => '<strong>$1</strong>',
		'/(?<!\w)(?:\*|_)([^*_]+?)(?:\*|_)(?!\w)/' => '<em>$1</em>',
		'/~~([^~]+)~~/' => '<del>$1</del>',
		'/==([^=]+)==/' => '<mark>$1</mark>',
		'/\|\|([^|]+)\|\|/' => '<span class="spoiler">$1</span>',
	];
	$out = preg_replace(array_keys($patterns), array_values($patterns), $out) ?? $out;
	$out = preg_replace_callback('/&lt;(https?:\/\/[^&\s]+)&gt;/', static fn(array $match): string => $protect('<a href="' . $match[1] . '" target="_blank" rel="noopener noreferrer">' . $match[1] . '</a>'), $out) ?? $out;
	$out = preg_replace_callback('/&lt;([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})&gt;/i', static fn(array $match): string => $protect('<a href="mailto:' . $match[1] . '">' . $match[1] . '</a>'), $out) ?? $out;
	$out = preg_replace_callback('/(?<!["=])(https?:\/\/[^\s<]+)/', static fn(array $match): string => $protect('<a href="' . $match[1] . '" target="_blank" rel="noopener noreferrer">' . $match[1] . '</a>'), $out) ?? $out;
	$out = preg_replace('/\$([^$\n]+)\$/', '<span class="mdpaste-math">\\($1\\)</span>', $out) ?? $out;
	$out = preg_replace('/\[(-?@[A-Za-z0-9_.-]+(?:\s*;\s*-?@[A-Za-z0-9_.-]+)*)\]/', '<span class="mdpaste-citation">[$1]</span>', $out) ?? $out;
	$out = strtr($out, [':rocket:' => '🚀', ':sparkles:' => '✨', ':warning:' => '⚠️', ':shipit:' => '🐿️']);
	for ($pass = 0; $pass < 8 && str_contains($out, '@@MDP'); $pass++) $out = strtr($out, $tokens);
	return $out;
}

function mdp_render_markdown_legacy(string $markdown, bool $hardBreaks = false): string
{
	$markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
	$markdown = preg_replace('/<!--.*?-->/s', '', $markdown) ?? $markdown;
	$references = [];
	$footnotes = [];
	$abbreviations = [];
	$markdown = preg_replace_callback('/^\[([^\]^]+)\]:\s*(\S+)(?:\s+["\']([^"\']*)["\'])?\s*$/m', static function (array $match) use (&$references): string {
		$references[strtolower(trim($match[1]))] = [$match[2], $match[3] ?? ''];
		return '';
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/^\[\^([^\]]+)\]:\s*(.+)$/m', static function (array $match) use (&$footnotes): string {
		$footnotes[$match[1]] = $match[2];
		return '';
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/^\*\[([^\]]+)\]:\s*(.+)$/m', static function (array $match) use (&$abbreviations): string {
		$abbreviations[$match[1]] = $match[2];
		return '';
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/!\[([^\]]*)\]\[([^\]]*)\]/', static function (array $match) use ($references): string {
		$key = strtolower($match[2] !== '' ? $match[2] : $match[1]);
		return isset($references[$key]) ? '![' . $match[1] . '](' . $references[$key][0] . ')' : $match[0];
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/\[([^\]]+)\]\[([^\]]*)\]/', static function (array $match) use ($references): string {
		$key = strtolower($match[2] !== '' ? $match[2] : $match[1]);
		return isset($references[$key]) ? '[' . $match[1] . '](' . $references[$key][0] . ')' : $match[0];
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/(?<!!)\[([^\]]+)\](?![\[(])/', static function (array $match) use ($references): string {
		$key = strtolower($match[1]);
		return isset($references[$key]) ? '[' . $match[1] . '](' . $references[$key][0] . ')' : $match[0];
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/\[\^([^\]]+)\]/', static fn(array $match): string => '<sup><a href="#footnote-' . rawurlencode($match[1]) . '">[' . mdp_h($match[1]) . ']</a></sup>', $markdown) ?? $markdown;
	foreach ($abbreviations as $term => $meaning) {
		$markdown = preg_replace('/\b' . preg_quote($term, '/') . '\b/', '<abbr title="' . mdp_h($meaning) . '">' . mdp_h($term) . '</abbr>', $markdown) ?? $markdown;
	}
	$frontMatter = '';
	if (preg_match('/\A---\n(.*?)\n---\n/s', $markdown, $front)) {
		$items = [];
		foreach (explode("\n", $front[1]) as $line) {
			if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $field)) $items[] = '<dt>' . mdp_h($field[1]) . '</dt><dd>' . mdp_h(trim($field[2], " \"'")) . '</dd>';
		}
		$frontMatter = $items === [] ? '' : '<dl class="mdpaste-front-matter">' . implode('', $items) . '</dl>';
		$markdown = substr($markdown, strlen($front[0]));
	}
	$markdown = preg_replace_callback('/^>\s*\[!([A-Za-z]+)\]\s*\n((?:>.*(?:\n|$))*)/m', static function (array $match): string {
		$type = strtolower($match[1]);
		$body = preg_replace('/^>\s?/m', '', rtrim($match[2])) ?? rtrim($match[2]);
		return '<div class="mdpaste-alert mdpaste-alert-' . mdp_h($type) . '">' . "\n<strong>" . mdp_h(strtoupper($type)) . "</strong>\n" . $body . "\n</div>\n";
	}, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/^:::\s*([^\n]*)\n(.*?)\n:::\s*$/ms', static function (array $match): string {
		$spec = trim($match[1]);
		$id = '';
		$classes = [];
		if (preg_match('/#([A-Za-z][A-Za-z0-9_-]*)/', $spec, $idMatch)) $id = ' id="' . mdp_h($idMatch[1]) . '"';
		preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $spec, $classMatches);
		$classes = $classMatches[1] ?? [];
		if ($classes === [] && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $spec)) $classes[] = $spec;
		$class = $classes === [] ? '' : ' class="' . mdp_h(implode(' ', $classes)) . '"';
		return '<div' . $id . $class . ">\n" . $match[2] . "\n</div>";
	}, $markdown) ?? $markdown;
	$lines = explode("\n", $markdown);
	for ($index = 0; $index + 1 < count($lines); $index++) {
		if (trim($lines[$index]) !== '' && preg_match('/^\s*(=+|-+)\s*$/', $lines[$index + 1], $setext)) {
			$lines[$index] = ($setext[1][0] === '=' ? '# ' : '## ') . trim($lines[$index]);
			$lines[$index + 1] = '';
		}
	}
	$html = [];
	if ($frontMatter !== '') $html[] = $frontMatter;
	$paragraph = [];
	$code = [];
	$inCode = false;
	$codeFence = '';
	$codeLanguage = '';
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
		$header = mdp_split_table_row($table[0]);
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
			$cells = mdp_split_table_row($row);
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
		$forcedBreak = preg_match('/ {2,}$/', $line) === 1;
		$trimmed = trim($line);
		if ($forcedBreak && $trimmed !== '') $trimmed .= '<br>';

		if (preg_match('/^(```|~~~)\s*([A-Za-z0-9_+-]*)/', $trimmed, $fence)) {
			$flushParagraph();
			$closeLists();
			$flushTable();
			if ($inCode && $fence[1] === $codeFence) {
				$class = $codeLanguage !== '' ? ' class="language-' . mdp_h($codeLanguage) . '"' : '';
				$html[] = '<pre><code' . $class . '>' . mdp_h(implode("\n", $code)) . '</code></pre>';
				$code = [];
				$inCode = false;
			} else {
				$inCode = true;
				$codeFence = $fence[1];
				$codeLanguage = strtolower($fence[2] ?? '');
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

		if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+\{([^}]*)\})?$/', $trimmed, $match)) {
			$flushParagraph();
			$closeLists();
			$level = strlen($match[1]);
			$attributes = '';
			if (!empty($match[3])) {
				if (preg_match('/#([A-Za-z][A-Za-z0-9_-]*)/', $match[3], $id)) $attributes .= ' id="' . mdp_h($id[1]) . '"';
				preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $match[3], $classes);
				if (($classes[1] ?? []) !== []) $attributes .= ' class="' . mdp_h(implode(' ', $classes[1])) . '"';
			}
			$html[] = '<h' . $level . $attributes . '>' . mdp_inline($match[2]) . '</h' . $level . '>';
			continue;
		}

		if ($trimmed === '---' || $trimmed === '***' || $trimmed === '___') {
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

		if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $match)) {
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
				$checkbox = '<input class="checkbox" type="checkbox" disabled' . $checked . '> ';
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

		if (preg_match('/^\s{4}(.+)$/', $line, $match)) {
			$flushParagraph();
			$closeLists();
			$html[] = '<pre><code>' . mdp_h($match[1]) . '</code></pre>';
			continue;
		}

		if (preg_match('/^:\s+(.+)$/', $trimmed, $match)) {
			$flushParagraph();
			$html[] = '<dd>' . mdp_inline($match[1]) . '</dd>';
			continue;
		}

		if (preg_match('/^<\/?(?:div|details|summary|table|thead|tbody|tr|th|td|p|ruby)\b[^>]*>$/i', $trimmed)) {
			$flushParagraph();
			$closeLists();
			$html[] = mdp_inline($trimmed);
			continue;
		}

		$paragraph[] = $trimmed;
	}

	$flushParagraph();
	$closeLists();
	$flushTable();
	if ($inCode) {
		$class = $codeLanguage !== '' ? ' class="language-' . mdp_h($codeLanguage) . '"' : '';
		$html[] = '<pre><code' . $class . '>' . mdp_h(implode("\n", $code)) . '</code></pre>';
	}
	if ($footnotes !== []) {
		$html[] = '<section class="mdpaste-footnotes"><hr><ol>';
		foreach ($footnotes as $id => $note) $html[] = '<li id="footnote-' . mdp_h(rawurlencode((string)$id)) . '">' . mdp_inline($note) . '</li>';
		$html[] = '</ol></section>';
	}

	return $html === [] ? '<p>nothing here.</p>' : implode("\n", $html);
}

function mdp_split_table_row(string $line): array
{
	$escapedPipe = "\x1FMDPPIPE\x1F";
	$spoilers = [];
	$line = trim($line);
	if (str_starts_with($line, '|')) $line = substr($line, 1);
	if (str_ends_with($line, '|')) $line = substr($line, 0, -1);
	$line = str_replace('\\|', $escapedPipe, $line);
	$line = preg_replace_callback('/\|\|[^|\r\n]+\|\|/', static function (array $match) use (&$spoilers): string {
		$key = "\x1FMDPSPOILER" . count($spoilers) . "\x1F";
		$spoilers[$key] = $match[0];
		return $key;
	}, $line) ?? $line;
	return array_map(static function (string $cell) use ($escapedPipe, $spoilers): string {
		return strtr(str_replace($escapedPipe, '|', trim($cell)), $spoilers);
	}, explode('|', $line));
}

function mdp_heading_slug(string $text): string
{
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
	$text = strtolower(trim($text));
	$text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? $text;
	return trim(preg_replace('/[\s-]+/', '-', $text) ?? $text, '-') ?: 'section';
}

function mdp_download_filename(string $markdown, ?int $timestamp = null): string
{
	$name = '';
	if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', $markdown, $front)
		&& preg_match('/^title:\s*(.+)$/mi', $front[1], $title)) {
		$name = trim($title[1], " \t\n\r\0\x0B\"'");
	}
	$withoutFrontMatter = preg_replace('/\A---\R.*?\R---(?:\R|\z)/s', '', $markdown, 1) ?? $markdown;
	if ($name === '' && preg_match('/^#{1,6}\s+(.+?)(?:\s+\{[^}]*\})?\s*$/m', $withoutFrontMatter, $heading)) {
		$name = trim($heading[1]);
	}
	if ($name === '' && preg_match('/^(.+)\R(?:=+|-+)\s*$/m', $withoutFrontMatter, $setext)) {
		$name = trim($setext[1]);
	}
	if ($name === '') $name = 'mdpaste - ' . date('Y-m-d H-i-s', $timestamp ?? time());
	$name = html_entity_decode(strip_tags($name), ENT_QUOTES, 'UTF-8');
	$name = preg_replace('~[\\/:*?"<>|\x00-\x1F]+~u', '-', $name) ?? $name;
	$name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " .-");
	if ($name === '') $name = 'mdpaste - ' . date('Y-m-d H-i-s', $timestamp ?? time());
	$name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
	return $name . '.md';
}

function mdp_render_audio(string $url): string
{
	$safe = mdp_h($url);
	return '<div class="feed-audio-note feed-voice-note feed-uploaded-audio chat-attachment chat-attachment-media chat-attachment-audio">'
		. '<audio class="chat-media-element" preload="metadata" src="' . $safe . '"></audio>'
		. '<div class="chat-media-player" data-media-kind="audio">'
		. '<button class="chat-media-play" type="button" aria-label="play audio"><i class="fa-solid fa-play"></i></button>'
		. '<input class="chat-media-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek audio">'
		. '<span class="chat-media-time">0:00 / 0:00</span></div></div>';
}

function mdp_render_video(string $url, string $label = 'video'): string
{
	$safe = mdp_h($url);
	return '<div class="feed-video-attachment"><video class="feed-video-element" playsinline preload="metadata" src="' . $safe . '" aria-label="' . mdp_h($label) . '"></video>'
		. '<div class="feed-video-controls"><button class="feed-video-control feed-video-play" type="button" aria-label="play video"><i class="fa-solid fa-play"></i></button>'
		. '<input class="feed-video-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek video">'
		. '<span class="feed-video-time">0:00 / 0:00</span><button class="feed-video-control feed-video-mute" type="button" aria-label="mute video"><i class="fa-solid fa-volume-high"></i></button>'
		. '<input class="feed-video-volume" type="range" min="0" max="1" value="1" step="0.05" aria-label="video volume">'
		. '<button class="feed-video-control feed-video-fullscreen" type="button" aria-label="fullscreen video"><i class="fa-solid fa-expand"></i></button></div></div>';
}

function mdp_render_list_v2(array $lines, int &$index, int $baseIndent, bool $hardBreaks): string
{
	$first = $lines[$index] ?? '';
	preg_match('/^(\s*)([-+*]|(\d+)\.)\s+(.*)$/', $first, $firstMatch);
	$ordered = isset($firstMatch[3]) && $firstMatch[3] !== '';
	$tag = $ordered ? 'ol' : 'ul';
	$start = $ordered && (int)$firstMatch[3] !== 1 ? ' start="' . (int)$firstMatch[3] . '"' : '';
	$class = $ordered && $baseIndent > 0 ? ' class="mdpaste-roman-list"' : '';
	$html = '<' . $tag . $start . $class . '>';
	$count = count($lines);
	while ($index < $count && preg_match('/^(\s*)([-+*]|(\d+)\.)\s+(.*)$/', $lines[$index], $match)) {
		$indent = strlen(str_replace("\t", '    ', $match[1]));
		$isOrdered = isset($match[3]) && $match[3] !== '';
		if ($indent !== $baseIndent || $isOrdered !== $ordered) break;
		$item = $match[4];
		$task = '';
		if (preg_match('/^\[(x| )\]\s+(.*)$/i', $item, $taskMatch)) {
			$checked = strtolower($taskMatch[1]) === 'x' ? ' checked' : '';
			$task = '<input class="checkbox" type="checkbox" disabled' . $checked . '> ';
			$item = $taskMatch[2];
		}
		$index++;
		$children = [];
		while ($index < $count) {
			$next = $lines[$index];
			if (trim($next) === '') {
				$children[] = '';
				$index++;
				continue;
			}
			if (preg_match('/^(\s*)([-+*]|\d+\.)\s+/', $next, $nextMatch)) {
				$nextIndent = strlen(str_replace("\t", '    ', $nextMatch[1]));
				if ($nextIndent <= $baseIndent) break;
			}
			$leading = strlen($next) - strlen(ltrim($next, " \t"));
			if ($leading <= $baseIndent && !str_starts_with(ltrim($next), '>')) break;
			$children[] = $next;
			$index++;
		}
		$html .= '<li>' . $task . mdp_inline($item);
		if ($children !== []) $html .= mdp_render_blocks_v2($children, $hardBreaks);
		$html .= '</li>';
	}
	return $html . '</' . $tag . '>';
}

function mdp_render_blocks_v2(array $lines, bool $hardBreaks): string
{
	$html = [];
	$paragraph = [];
	$flushParagraph = static function () use (&$paragraph, &$html, $hardBreaks): void {
		if ($paragraph === []) return;
		$parts = [];
		foreach ($paragraph as $entry) {
			$forced = str_ends_with($entry, '  ');
			$parts[] = mdp_inline(rtrim($entry));
			if ($forced) $parts[] = '<br>';
		}
		$html[] = '<p>' . implode($hardBreaks ? '<br>' : ' ', $parts) . '</p>';
		$paragraph = [];
	};
	$count = count($lines);
	for ($index = 0; $index < $count;) {
		$line = $lines[$index];
		$trimmed = trim($line);
		if ($trimmed === '') { $flushParagraph(); $index++; continue; }

		if (preg_match('/^(```|~~~)\s*([^\s{]+)?(.*)$/', $trimmed, $fence)) {
			$flushParagraph();
			$marker = $fence[1];
			$language = isset($fence[2]) ? preg_replace('/[^A-Za-z0-9_+-]/', '', $fence[2]) : '';
			$metadata = trim($fence[3] ?? '');
			$index++;
			$code = [];
			while ($index < $count && !preg_match('/^' . preg_quote($marker, '/') . '\s*$/', trim($lines[$index]))) $code[] = $lines[$index++];
			if ($index < $count) $index++;
			if (strtolower($language) === 'mermaid') {
				$html[] = '<div class="mermaid mdpaste-mermaid">' . mdp_h(implode("\n", $code)) . '</div>';
				continue;
			}
			$class = $language !== '' ? ' class="language-' . mdp_h(strtolower($language)) . '"' : '';
			$caption = '';
			if (preg_match('/\btitle=["\']([^"\']+)["\']/', $metadata, $titleMatch)) $caption .= '<span>' . mdp_h($titleMatch[1]) . '</span>';
			if (preg_match('/\{([^}]+)\}/', $metadata, $lineMatch)) $caption .= '<span>lines ' . mdp_h($lineMatch[1]) . '</span>';
			$codeHtml = '<pre class="mdpaste-code-block"><code' . $class . '>' . mdp_h(implode("\n", $code)) . '</code></pre>';
			$html[] = $caption !== '' ? '<figure class="mdpaste-code-figure"><figcaption>' . $caption . '</figcaption>' . $codeHtml . '</figure>' : $codeHtml;
			continue;
		}

		if ($trimmed === '$$') {
			$flushParagraph(); $index++; $math = [];
			while ($index < $count && trim($lines[$index]) !== '$$') $math[] = trim($lines[$index++]);
			if ($index < $count) $index++;
			$html[] = '<div class="mdpaste-math mdpaste-math-display">\[' . mdp_h(implode("\n", $math)) . '\]</div>';
			continue;
		}

		if (preg_match('/^>\s?(.*)$/', $trimmed)) {
			$flushParagraph(); $quoted = [];
			while ($index < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$index], $quoteMatch)) { $quoted[] = $quoteMatch[1]; $index++; }
			if (isset($quoted[0]) && preg_match('/^\[!([A-Za-z]+)\]\s*$/', trim($quoted[0]), $alert)) {
				$type = strtolower($alert[1]); array_shift($quoted);
				$html[] = '<aside class="mdpaste-alert mdpaste-alert-' . mdp_h($type) . '"><strong class="mdpaste-alert-title">' . mdp_h(strtoupper($type)) . '</strong>' . mdp_render_blocks_v2($quoted, $hardBreaks) . '</aside>';
			} else {
				$html[] = '<blockquote>' . mdp_render_blocks_v2($quoted, $hardBreaks) . '</blockquote>';
			}
			continue;
		}

		if (preg_match('/^(\s*)([-+*]|\d+\.)\s+/', $line, $listMatch)) {
			$flushParagraph();
			$indent = strlen(str_replace("\t", '    ', $listMatch[1]));
			$html[] = mdp_render_list_v2($lines, $index, $indent, $hardBreaks);
			continue;
		}

		if (preg_match('/^(?: {4}|\t)/', $line)) {
			$flushParagraph(); $code = [];
			while ($index < $count && (trim($lines[$index]) === '' || preg_match('/^(?: {4}|\t)/', $lines[$index]))) {
				$code[] = preg_replace('/^(?: {4}|\t)/', '', $lines[$index]); $index++;
			}
			while ($code !== [] && end($code) === '') array_pop($code);
			$html[] = '<pre class="mdpaste-code-block mdpaste-indented-code"><code>' . mdp_h(implode("\n", $code)) . '</code></pre>';
			continue;
		}

		if (preg_match('/^<details\b([^>]*)>$/i', $trimmed, $detailsOpen)) {
			$flushParagraph(); $index++; $summary = ''; $body = [];
			if ($index < $count && preg_match('/^<summary>(.*?)<\/summary>$/i', trim($lines[$index]), $summaryMatch)) { $summary = mdp_inline($summaryMatch[1]); $index++; }
			while ($index < $count && !preg_match('/^<\/details>\s*$/i', trim($lines[$index]))) $body[] = $lines[$index++];
			if ($index < $count) $index++;
			$open = preg_match('/\bopen\b/i', $detailsOpen[1]) ? ' open' : '';
			$html[] = '<details' . $open . '><summary>' . $summary . '</summary><div class="mdpaste-details-content">' . mdp_render_blocks_v2($body, $hardBreaks) . '</div></details>';
			continue;
		}

		if (preg_match('/^<div\b([^>]*)>$/i', $trimmed, $divOpen)) {
			$flushParagraph(); $opening = mdp_inline($trimmed); $index++; $body = [];
			while ($index < $count && !preg_match('/^<\/div>\s*$/i', trim($lines[$index]))) $body[] = $lines[$index++];
			if ($index < $count) $index++;
			$html[] = $opening . mdp_render_blocks_v2($body, $hardBreaks) . '</div>';
			continue;
		}

		if (preg_match('/^<(table|ruby)\b/i', $trimmed, $htmlOpen)) {
			$flushParagraph(); $tag = strtolower($htmlOpen[1]); $block = [];
			while ($index < $count) { $block[] = trim($lines[$index]); if (preg_match('/<\/' . preg_quote($tag, '/') . '>\s*$/i', trim($lines[$index++]))) break; }
			$renderedBlock = implode("\n", array_map('mdp_inline', $block));
			$html[] = $tag === 'table' ? '<div class="mdpaste-table-scroll">' . $renderedBlock . '</div>' : $renderedBlock;
			continue;
		}

		if (preg_match('/^<audio\b[\s\S]*?src=["\']([^"\']+)["\']/i', $trimmed, $audio)) {
			$flushParagraph(); $url = mdp_safe_url($audio[1]);
			while ($index < $count && !preg_match('/<\/audio>\s*$/i', trim($lines[$index++]))) {}
			$html[] = $url === null ? mdp_inline($line) : mdp_render_audio($url);
			continue;
		}

		if (preg_match('/^<video\b/i', $trimmed)) {
			$flushParagraph(); $mediaLines = [];
			while ($index < $count) { $mediaLines[] = trim($lines[$index]); if (preg_match('/<\/video>\s*$/i', trim($lines[$index++]))) break; }
			$mediaBlock = implode(' ', $mediaLines);
			preg_match('/(?:<video[^>]*\bsrc|<source[^>]*\bsrc)=["\']([^"\']+)["\']/i', $mediaBlock, $source);
			$url = isset($source[1]) ? mdp_safe_url($source[1]) : null;
			$html[] = $url === null ? mdp_inline($mediaBlock) : mdp_render_video($url);
			continue;
		}

		if (str_contains($line, '|') && $index + 1 < $count && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$index + 1])) {
			$flushParagraph(); $headers = mdp_split_table_row($line); $aligners = mdp_split_table_row($lines[$index + 1]); $index += 2;
			$rows = [];
			while ($index < $count && str_contains($lines[$index], '|') && trim($lines[$index]) !== '') $rows[] = mdp_split_table_row($lines[$index++]);
			$table = '<div class="mdpaste-table-scroll"><table><thead><tr>';
			foreach ($headers as $cellIndex => $cell) { $align = trim($aligners[$cellIndex] ?? ''); $style = str_starts_with($align, ':') && str_ends_with($align, ':') ? 'center' : (str_ends_with($align, ':') ? 'right' : 'left'); $table .= '<th style="text-align:' . $style . '">' . mdp_inline($cell) . '</th>'; }
			$table .= '</tr></thead><tbody>';
			foreach ($rows as $row) {
				$table .= '<tr>';
				foreach ($headers as $cellIndex => $_) {
					$align = trim($aligners[$cellIndex] ?? '');
					$style = str_starts_with($align, ':') && str_ends_with($align, ':') ? 'center' : (str_ends_with($align, ':') ? 'right' : 'left');
					$table .= '<td style="text-align:' . $style . '">' . mdp_inline($row[$cellIndex] ?? '') . '</td>';
				}
				$table .= '</tr>';
			}
			$html[] = $table . '</tbody></table></div>';
			continue;
		}

		if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+\{([^}]*)\})?$/', $trimmed, $heading)) {
			$flushParagraph(); $level = strlen($heading[1]); $attrs = '';
			if (!empty($heading[3]) && preg_match('/#([A-Za-z][A-Za-z0-9_-]*)/', $heading[3], $id)) $attrs .= ' id="' . mdp_h($id[1]) . '"';
			else $attrs .= ' id="' . mdp_h(mdp_heading_slug($heading[2])) . '"';
			$html[] = '<h' . $level . $attrs . '>' . mdp_inline($heading[2]) . '</h' . $level . '>'; $index++; continue;
		}

		if ($index + 1 < $count && preg_match('/^\s*(=+|-+)\s*$/', $lines[$index + 1], $setext)) {
			$flushParagraph(); $level = $setext[1][0] === '=' ? 1 : 2; $html[] = '<h' . $level . ' id="' . mdp_h(mdp_heading_slug($trimmed)) . '">' . mdp_inline($trimmed) . '</h' . $level . '>'; $index += 2; continue;
		}
		if (preg_match('/^\s*(?:\*\s*){3,}$|^\s*(?:-\s*){3,}$|^\s*(?:_\s*){3,}$/', $line)) { $flushParagraph(); $html[] = '<hr>'; $index++; continue; }

		$paragraph[] = $line; $index++;
	}
	$flushParagraph();
	return implode("\n", $html);
}

function mdp_render_markdown(string $markdown, bool $hardBreaks = false): string
{
	$markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
	$markdown = preg_replace('/<!--.*?-->/s', '', $markdown) ?? $markdown;
	$references = [];
	$footnotes = [];
	$abbreviations = [];
	$lines = explode("\n", $markdown);
	$kept = [];
	for ($index = 0, $count = count($lines); $index < $count; $index++) {
		$line = $lines[$index];
		if (preg_match('/^\[\^([^\]]+)\]:\s*(.*)$/', $line, $footnote)) {
			$body = [$footnote[2]];
			while ($index + 1 < $count && (trim($lines[$index + 1]) === '' || preg_match('/^(?: {4}|\t)/', $lines[$index + 1]))) { $index++; $body[] = preg_replace('/^(?: {4}|\t)/', '', $lines[$index]); }
			$footnotes[$footnote[1]] = trim(implode("\n", $body)); continue;
		}
		if (preg_match('/^\[([^\]^]+)\]:\s*(\S+)(?:\s+["\']([^"\']*)["\'])?\s*$/', $line, $reference)) { $references[strtolower($reference[1])] = [$reference[2], $reference[3] ?? '']; continue; }
		if (preg_match('/^\*\[([^\]]+)\]:\s*(.+)$/', $line, $abbr)) { $abbreviations[$abbr[1]] = $abbr[2]; continue; }
		$kept[] = $line;
	}
	$markdown = implode("\n", $kept);
	$markdown = preg_replace_callback('/!\[([^\]]*)\]\[([^\]]*)\]/', static function (array $match) use ($references): string { $key = strtolower($match[2] !== '' ? $match[2] : $match[1]); return isset($references[$key]) ? '![' . $match[1] . '](' . $references[$key][0] . ')' : $match[0]; }, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/\[([^\]]+)\]\[([^\]]*)\]/', static function (array $match) use ($references): string { $key = strtolower($match[2] !== '' ? $match[2] : $match[1]); return isset($references[$key]) ? '[' . $match[1] . '](' . $references[$key][0] . ')' : $match[0]; }, $markdown) ?? $markdown;
	$markdown = preg_replace_callback('/(?<!!)\[([^\]]+)\](?![\[(])/', static function (array $match) use ($references): string { $key = strtolower($match[1]); return isset($references[$key]) ? '[' . $match[1] . '](' . $references[$key][0] . ')' : $match[0]; }, $markdown) ?? $markdown;
	$footnoteRefs = [];
	$markdown = preg_replace_callback('/\[\^([^\]]+)\]/', static function (array $match) use (&$footnoteRefs): string {
		$id = (string)$match[1];
		$occurrence = count($footnoteRefs[$id] ?? []) + 1;
		$referenceId = 'footnote-ref-' . rawurlencode($id) . '-' . $occurrence;
		$footnoteRefs[$id][] = $referenceId;
		return '<sup class="mdpaste-footnote-ref" id="' . mdp_h($referenceId) . '"><a href="#footnote-' . mdp_h(rawurlencode($id)) . '">' . mdp_h($id) . '</a></sup>';
	}, $markdown) ?? $markdown;
	foreach ($abbreviations as $term => $meaning) $markdown = preg_replace('/\b' . preg_quote($term, '/') . '\b/', '<abbr title="' . mdp_h($meaning) . '">' . mdp_h($term) . '</abbr>', $markdown) ?? $markdown;
	$frontMatter = '';
	if (preg_match('/\A---\n(.*?)\n---\n/s', $markdown, $front)) {
		$metadata = ['tags' => []];
		$currentKey = '';
		foreach (explode("\n", $front[1]) as $metadataLine) {
			if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $metadataLine, $field)) {
				$currentKey = strtolower($field[1]);
				if ($currentKey !== 'draft' && $currentKey !== 'tags') $metadata[$currentKey] = trim($field[2], " \"'");
				continue;
			}
			if ($currentKey === 'tags' && preg_match('/^\s*-\s*(.+)$/', $metadataLine, $tag)) $metadata['tags'][] = trim($tag[1], " \"'");
		}
		$title = mdp_h((string)($metadata['title'] ?? 'Untitled'));
		$author = isset($metadata['author']) ? '<p class="mdpaste-article-subtitle">by ' . mdp_h((string)$metadata['author']) . '</p>' : '';
		$description = isset($metadata['description']) ? '<p class="mdpaste-article-subtitle">' . mdp_h((string)$metadata['description']) . '</p>' : '';
		$date = isset($metadata['date']) ? '<time class="mdpaste-article-date">' . mdp_h((string)$metadata['date']) . '</time>' : '';
		$tags = '';
		if ($metadata['tags'] !== []) $tags = '<div class="mdpaste-article-tags">' . implode('', array_map(static fn(string $tag): string => '<span>' . mdp_h($tag) . '</span>', $metadata['tags'])) . '</div>';
		$frontMatter = '<header class="mdpaste-article-header"><h1 class="mdpaste-article-title">' . $title . '</h1>' . $description . $author . '<div class="mdpaste-article-meta">' . $date . $tags . '</div></header>';
		$markdown = substr($markdown, strlen($front[0]));
	}
	$html = $frontMatter . mdp_render_blocks_v2(explode("\n", $markdown), $hardBreaks);
	if ($footnotes !== []) {
		$html .= '<section class="mdpaste-footnotes"><hr><ol>';
		foreach ($footnotes as $id => $note) {
			$html .= '<li id="footnote-' . mdp_h(rawurlencode((string)$id)) . '">' . mdp_render_blocks_v2(explode("\n", $note), $hardBreaks);
			foreach ($footnoteRefs[(string)$id] ?? [] as $referenceId) $html .= '<a class="mdpaste-footnote-backref" href="#' . mdp_h($referenceId) . '" aria-label="back to footnote reference">↩</a>';
			$html .= '</li>';
		}
		$html .= '</ol></section>';
	}
	return $html !== '' ? $html : '<p>nothing here.</p>';
}

function mdp_json_response(array $payload, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit;
}
