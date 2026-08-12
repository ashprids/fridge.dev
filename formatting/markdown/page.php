<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 'lib.php';
fridg3_start_session();

$pageTitle = isset($markdownPageTitle) ? (string)$markdownPageTitle : 'Markdown formatting';
$pageDescription = isset($markdownPageDescription) ? (string)$markdownPageDescription : 'Markdown formatting supported by fridge.dev.';
$sourcePath = isset($markdownSourcePath) ? (string)$markdownSourcePath : '';
$renderer = isset($markdownRenderer) ? (string)$markdownRenderer : 'site';

if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
    http_response_code(500);
    die('Markdown showcase source not found.');
}
$markdown = (string)file_get_contents($sourcePath);
if ($renderer === 'feed') {
    $renderedParts = [];
    $sectionLines = [];
    $flushSection = static function () use (&$renderedParts, &$sectionLines): void {
        if ($sectionLines === []) return;
        $section = trim(implode("\n", $sectionLines));
        if ($section !== '') $renderedParts[] = fridg3_feed_render_post_body($section, 'v2');
        $sectionLines = [];
    };
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
        if (preg_match('/^(#{1,2})\s+(.+)$/', $line, $heading)) {
            $flushSection();
            $level = strlen($heading[1]);
            $renderedParts[] = '<h' . $level . '>' . htmlspecialchars($heading[2], ENT_QUOTES, 'UTF-8') . '</h' . $level . '>';
            continue;
        }
        $sectionLines[] = $line;
    }
    $flushSection();
    $rendered = implode("\n", $renderedParts);
} else {
    $rendered = mdp_render_markdown($markdown);
}

require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'render.php';
$templateName = get_preferred_template_name(__DIR__);
$templatePath = $root . DIRECTORY_SEPARATOR . $templateName;
if (!is_file($templatePath)) $templatePath = $root . DIRECTORY_SEPARATOR . 'template.html';
$template = (string)file_get_contents($templatePath);
$template = apply_preferred_theme_stylesheet($template, __DIR__);
$template = preg_replace('/<body class="([^"]*)">/i', '<body class="$1 mdpaste-shared-page markdown-formatting-page">', $template, 1) ?: $template;
$template = preg_replace('/<span id="show-sidebar"[^>]*>.*?<\/span>\s*/is', '', $template, 1) ?: $template;
$template = preg_replace(
    '/(<div id="page-wrapper">\s*)<div id="sidebar">.*?(<div id="container">)/is',
    '$1$2',
    $template,
    1
) ?: $template;

$payload = json_encode(
    ['markdown' => $markdown, 'filename' => basename($sourcePath)],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '{}';
$showcase = '<style>'
    . '.markdown-formatting-page .mdpaste-markdown > :is(h1,h2,h3,h4,h5,h6) ~ :is(h1,h2,h3,h4,h5,h6){margin-top:2em}'
    . '</style>'
    . '<div class="mdpaste-paste-actions">'
    . '<button type="button" id="mdpaste-font-toggle" data-tooltip="toggle serif font" aria-label="toggle serif font" aria-pressed="false"><i class="fa-solid fa-font"></i></button>'
    . '<button type="button" id="mdpaste-format-toggle" data-tooltip="toggle formatting" aria-label="toggle formatting" aria-pressed="false"><i class="fa-solid fa-code"></i></button>'
    . '</div>'
    . '<article class="mdpaste-markdown" id="mdpaste-formatted">' . $rendered . '</article>'
    . '<pre class="mdpaste-raw-markdown" id="mdpaste-raw" hidden><code class="nohighlight">' . mdp_h($markdown) . '</code></pre>'
    . '<script id="mdpaste-source-data" type="application/json">' . $payload . '</script>';
$contentTemplate = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 's' . DIRECTORY_SEPARATOR . 'content.html');
$content = str_replace('{paste_content}', $showcase, $contentTemplate);
$html = str_replace(
    ['{content}', '{title}', '{description}', '{user_greeting}'],
    [$content, $pageTitle, $pageDescription, ''],
    $template
);

header('Content-Type: text/html; charset=utf-8');
echo $html;
