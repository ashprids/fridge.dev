<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

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

function wiki_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wiki_get_markdown_files($wikiDir) {
    $files = glob($wikiDir . DIRECTORY_SEPARATOR . '*.md');
    if ($files === false) {
        return [];
    }

    $pages = [];
    foreach ($files as $file) {
        $basename = basename($file);
        $slug = preg_replace('/\.md$/i', '', $basename);
        if ($slug === null || $slug === '') {
            continue;
        }
        if ($slug === '_Sidebar') {
            continue;
        }

        $pages[$slug] = [
            'file' => $basename,
            'slug' => $slug,
            'label' => str_replace('-', ' ', $slug),
        ];
    }

    $priority = ['Home' => 0];
    $sidebarPath = $wikiDir . DIRECTORY_SEPARATOR . '_Sidebar.md';
    if (is_file($sidebarPath)) {
        $sidebar = (string)file_get_contents($sidebarPath);
        if (preg_match_all('/\[(.*?)\]\((.*?)\)/', $sidebar, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $slug = basename(preg_replace('/\.md$/i', '', trim((string)$match[2])));
                if ($slug !== '' && isset($pages[$slug])) {
                    $priority[$slug] = $index;
                    $pages[$slug]['label'] = trim((string)$match[1]) ?: $pages[$slug]['label'];
                }
            }
        }
    }

    uksort($pages, function ($a, $b) use ($priority) {
        $aPriority = $priority[$a] ?? 1000;
        $bPriority = $priority[$b] ?? 1000;
        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }
        return strcasecmp($a, $b);
    });

    return $pages;
}

function wiki_normalize_page_slug($rawValue) {
    $value = trim((string)$rawValue);
    if ($value === '') {
        return 'Home';
    }

    $value = basename($value);
    $value = preg_replace('/\.md$/i', '', $value);
    if ($value === null || $value === '') {
        return 'Home';
    }

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return 'Home';
    }

    return $value;
}

function wiki_slug_to_url($slug) {
    return '/tools/frdgbeats/wiki/?page=' . rawurlencode((string)$slug);
}

function wiki_render_inline_markdown($text) {
    $escaped = wiki_escape($text);

    $escaped = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) {
        $alt = $matches[1];
        $target = trim(html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8'));
        if ($target === '' || str_starts_with($target, 'placeholder:')) {
            return '<figure class="wiki-image-placeholder" role="img" aria-label="' . wiki_escape($alt) . '">'
                . '<span>screenshot placeholder</span>'
                . '<small>' . wiki_escape($alt) . '</small>'
                . '</figure>';
        }
        $src = preg_match('#^(https?:)?//#i', $target) || strpos($target, '/') === 0
            ? $target
            : '/tools/frdgbeats/wiki/' . ltrim($target, './');
        return '<img class="wiki-image" src="' . wiki_escape($src) . '" alt="' . wiki_escape($alt) . '">';
    }, $escaped);

    $escaped = preg_replace_callback('/`([^`]+)`/', function ($matches) {
        return '<code>' . $matches[1] . '</code>';
    }, $escaped);

    $escaped = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($matches) {
        $label = $matches[1];
        $target = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
        $target = trim($target);

        if ($target === '') {
            return $label;
        }

        $isExternal = preg_match('#^(https?:)?//#i', $target) || preg_match('#^(mailto|tel):#i', $target);
        $isRootRelative = strpos($target, '/') === 0;

        if ($isExternal || $isRootRelative) {
            $href = wiki_escape($target);
            $rel = $isExternal ? ' rel="noopener noreferrer"' : '';
            return '<a href="' . $href . '"' . $rel . '>' . $label . '</a>';
        }

        $target = preg_replace('#^\./#', '', $target);
        $target = preg_replace('/\.md$/i', '', $target);
        $target = basename((string)$target);

        if ($target === '') {
            return $label;
        }

        return '<a href="' . wiki_escape(wiki_slug_to_url($target)) . '">' . $label . '</a>';
    }, $escaped);

    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $escaped);

    return $escaped;
}

function wiki_render_markdown($markdown) {
    $lines = preg_split("/\r\n|\n|\r/", (string)$markdown);
    if (!is_array($lines)) {
        return '';
    }

    $html = [];
    $inCodeBlock = false;
    $codeLanguage = '';
    $codeLines = [];
    $inUl = false;
    $inOl = false;
    $paragraphLines = [];
    $inBlockquote = false;
    $blockquoteLines = [];

    $flushParagraph = function () use (&$html, &$paragraphLines) {
        if ($paragraphLines === []) {
            return;
        }
        $text = trim(implode(' ', $paragraphLines));
        if ($text !== '') {
            $html[] = '<p>' . wiki_render_inline_markdown($text) . '</p>';
        }
        $paragraphLines = [];
    };

    $closeLists = function () use (&$html, &$inUl, &$inOl) {
        if ($inUl) {
            $html[] = '</ul>';
            $inUl = false;
        }
        if ($inOl) {
            $html[] = '</ol>';
            $inOl = false;
        }
    };

    $flushBlockquote = function () use (&$html, &$blockquoteLines, &$inBlockquote) {
        if (!$inBlockquote) {
            return;
        }
        $content = trim(implode("\n", $blockquoteLines));
        $html[] = '<blockquote><p>' . wiki_render_inline_markdown(str_replace("\n", '<br>', $content)) . '</p></blockquote>';
        $blockquoteLines = [];
        $inBlockquote = false;
    };

    foreach ($lines as $line) {
        if (preg_match('/^```([A-Za-z0-9_-]+)?\s*$/', $line, $matches)) {
            $flushParagraph();
            $closeLists();
            $flushBlockquote();

            if ($inCodeBlock) {
                $classAttr = $codeLanguage !== '' ? ' class="language-' . wiki_escape($codeLanguage) . '"' : '';
                $html[] = '<pre><code' . $classAttr . '>' . wiki_escape(implode("\n", $codeLines)) . '</code></pre>';
                $inCodeBlock = false;
                $codeLanguage = '';
                $codeLines = [];
            } else {
                $inCodeBlock = true;
                $codeLanguage = isset($matches[1]) ? trim((string)$matches[1]) : '';
                $codeLines = [];
            }
            continue;
        }

        if ($inCodeBlock) {
            $codeLines[] = $line;
            continue;
        }

        if (trim($line) === '') {
            $flushParagraph();
            $closeLists();
            $flushBlockquote();
            continue;
        }

        if (preg_match('/^\s*>\s?(.*)$/', $line, $matches)) {
            $flushParagraph();
            $closeLists();
            $inBlockquote = true;
            $blockquoteLines[] = $matches[1];
            continue;
        }

        $flushBlockquote();

        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
            $flushParagraph();
            $closeLists();
            $level = strlen($matches[1]);
            $html[] = '<h' . $level . '>' . wiki_render_inline_markdown(trim($matches[2])) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^!\[(.*?)\]\((.*?)\)\s*$/', trim($line))) {
            $flushParagraph();
            $closeLists();
            $html[] = wiki_render_inline_markdown(trim($line));
            continue;
        }

        if (preg_match('/^\s*---+\s*$/', $line)) {
            $flushParagraph();
            $closeLists();
            $html[] = '<hr>';
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $matches)) {
            $flushParagraph();
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }
            if (!$inUl) {
                $html[] = '<ul>';
                $inUl = true;
            }
            $html[] = '<li>' . wiki_render_inline_markdown(trim($matches[1])) . '</li>';
            continue;
        }

        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $matches)) {
            $flushParagraph();
            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if (!$inOl) {
                $html[] = '<ol>';
                $inOl = true;
            }
            $html[] = '<li>' . wiki_render_inline_markdown(trim($matches[1])) . '</li>';
            continue;
        }

        $closeLists();
        $paragraphLines[] = trim($line);
    }

    if ($inCodeBlock) {
        $classAttr = $codeLanguage !== '' ? ' class="language-' . wiki_escape($codeLanguage) . '"' : '';
        $html[] = '<pre><code' . $classAttr . '>' . wiki_escape(implode("\n", $codeLines)) . '</code></pre>';
    }

    $flushParagraph();
    $closeLists();
    $flushBlockquote();

    return implode("\n", $html);
}

$pages = wiki_get_markdown_files(__DIR__);
$selectedSlug = wiki_normalize_page_slug($_GET['page'] ?? 'Home');

if (!isset($pages[$selectedSlug])) {
    http_response_code(404);
    $selectedSlug = 'Home';
}

$selectedPage = $pages[$selectedSlug] ?? null;
$selectedFile = $selectedPage ? (__DIR__ . DIRECTORY_SEPARATOR . $selectedPage['file']) : null;
$rawMarkdown = ($selectedFile && is_file($selectedFile)) ? (string)file_get_contents($selectedFile) : '# page not found';
$renderedMarkdown = wiki_render_markdown($rawMarkdown);

$title = 'frdgBeats wiki';
if ($selectedPage && $selectedSlug !== 'Home') {
    $title = 'frdgBeats wiki - ' . strtolower(str_replace('-', ' ', $selectedSlug));
}
$description = 'learn how to make music in frdgBeats.';

$render_helper_path = find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
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

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$pageLinks = [];
foreach ($pages as $page) {
    $isActive = $page['slug'] === $selectedSlug;
    $pageLinks[] = '<li><a class="wiki-page-link' . ($isActive ? ' active' : '') . '" href="' . wiki_escape(wiki_slug_to_url($page['slug'])) . '">'
        . wiki_escape($page['label'])
        . '</a></li>';
}

$content = file_get_contents($content_path);
$content = str_replace(
    ['{wiki_page_list}', '{wiki_page_title}', '{wiki_page_name}', '{wiki_page_content}'],
    [
        implode("\n", $pageLinks),
        wiki_escape(str_replace('-', ' ', $selectedSlug)),
        wiki_escape($selectedPage['file'] ?? 'unknown.md'),
        $renderedMarkdown,
    ],
    $content
);

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

$user_greeting = '';
if (isset($_SESSION['user']) && isset($_SESSION['user']['name'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountBtn, $logoutBtn, $html);
}
$html = str_replace('{user_greeting}', $user_greeting, $html);

echo $html;
?>
