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

function wiki_label_from_slug($slug) {
    $label = str_replace(['-', '_'], ' ', (string)$slug);
    $label = preg_replace('/\s+/', ' ', $label);
    $label = trim((string)$label);
    if ($label === '') {
        return 'Untitled';
    }

    $words = explode(' ', $label);
    $smallWords = ['and', 'or', 'the', 'a', 'an', 'to', 'of', 'in', 'for'];
    $words = array_map(function ($word, $index) use ($smallWords) {
        $upper = strtoupper($word);
        if (in_array($upper, ['API', 'PHP', 'HTML', 'CSS', 'JS', 'JSON', 'IP'], true)) {
            return $upper;
        }
        $lower = strtolower($word);
        if ($index > 0 && in_array($lower, $smallWords, true)) {
            return $lower;
        }
        return ucfirst(strtolower($word));
    }, $words, array_keys($words));

    return implode(' ', $words);
}

function wiki_sidebar_order($wikiDir) {
    $sidebarPath = $wikiDir . DIRECTORY_SEPARATOR . '_Sidebar.md';
    if (!is_file($sidebarPath)) {
        return [];
    }

    $markdown = (string)file_get_contents($sidebarPath);
    if (!preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $markdown, $matches)) {
        return [];
    }

    $order = [];
    foreach ($matches[1] as $target) {
        $slug = wiki_normalize_page_slug($target);
        if ($slug !== '_Sidebar' && !isset($order[$slug])) {
            $order[$slug] = count($order);
        }
    }

    return $order;
}

function wiki_get_markdown_files($wikiDir) {
    $files = glob($wikiDir . DIRECTORY_SEPARATOR . '*.md');
    if ($files === false) {
        return [];
    }

    $pages = [];
    $sidebarOrder = wiki_sidebar_order($wikiDir);
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
            'label' => wiki_label_from_slug($slug),
        ];
    }

    uksort($pages, function ($a, $b) use ($sidebarOrder) {
        $aPriority = $sidebarOrder[$a] ?? ($a === 'Home' ? -1 : 1000);
        $bPriority = $sidebarOrder[$b] ?? ($b === 'Home' ? -1 : 1000);
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
    return '/wiki?page=' . rawurlencode((string)$slug);
}

function wiki_render_inline_markdown($text) {
    $escaped = wiki_escape($text);

    $protected = [];
    $protect = static function (string $html) use (&$protected): string {
        $token = '@@WIKI_INLINE_' . count($protected) . '@@';
        $protected[$token] = $html;
        return $token;
    };

    $escaped = preg_replace_callback('/`([^`]+)`/', function ($matches) use ($protect) {
        return $protect('<code>' . $matches[1] . '</code>');
    }, $escaped);

    $escaped = preg_replace_callback('/(?<!\S)!fa[ \t]+(solid|regular|brands)[ \t]+([a-z0-9][a-z0-9-]*)\b/i', function ($matches) use ($protect) {
        return $protect('<i class="fa-' . strtolower($matches[1]) . ' fa-' . strtolower($matches[2]) . '"></i>');
    }, $escaped);

    $escaped = preg_replace_callback('/(?<!\S)!frdg\b/i', static function () use ($protect) {
        return $protect('<img class="markdown-frdg-icon no-image-viewer" src="/resources/favicon.svg" alt="fridge.dev">');
    }, $escaped);

    $escaped = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($matches) use ($protect) {
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
            return $protect('<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>');
        }

        $fragment = '';
        if (strpos($target, '#') !== false) {
            [$target, $fragment] = array_pad(explode('#', $target, 2), 2, '');
        }
        $target = preg_replace('#^\./#', '', $target);
        $target = preg_replace('/\.md$/i', '', $target);
        $target = basename((string)$target);

        if ($target === '') {
            return $label;
        }

        $href = wiki_slug_to_url($target);
        if ($fragment !== '') {
            $href .= '#' . rawurlencode($fragment);
        }
        return $protect('<a href="' . wiki_escape($href) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>');
    }, $escaped);

    $escaped = preg_replace_callback('/&lt;(https?:\/\/[^&\s]+)&gt;/i', function ($matches) use ($protect) {
        $href = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        return $protect('<a href="' . wiki_escape($href) . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>');
    }, $escaped);

    $escaped = preg_replace_callback(
        '/(?<![A-Za-z0-9_="\'])https?:\/\/[^\s<>()]*[A-Za-z0-9\/#=_-]/i',
        function ($matches) use ($protect) {
            $href = html_entity_decode($matches[0], ENT_QUOTES, 'UTF-8');
            return $protect('<a href="' . wiki_escape($href) . '" target="_blank" rel="noopener noreferrer">' . $matches[0] . '</a>');
        },
        $escaped
    );

    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $escaped);
    $escaped = preg_replace('/==([^=]+)==/', '<mark>$1</mark>', $escaped);
    $escaped = preg_replace('/\|\|([^|]+)\|\|/', '<span class="spoiler">$1</span>', $escaped);

    return strtr($escaped, $protected);
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

        if (($inUl || $inOl) && preg_match('/^\s{2,}(\S.*)$/', $line, $matches)) {
            $lastIndex = count($html) - 1;
            if ($lastIndex >= 0 && str_ends_with($html[$lastIndex], '</li>')) {
                $continuation = '<br>' . wiki_render_inline_markdown(trim($matches[1])) . '</li>';
                $html[$lastIndex] = substr($html[$lastIndex], 0, -5) . $continuation;
                continue;
            }
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

$title = 'wiki';
if ($selectedPage && $selectedSlug !== 'Home') {
    $title = 'wiki - ' . strtolower(str_replace('-', ' ', $selectedSlug));
}
$description = 'browse the internal developer wiki for fridge.dev.';

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

$template = preg_replace(
    '/<div id="content-footer">\s*<span id="content-footer-views"[^>]*>.*?<\/span>\s*<\/div>/is',
    '',
    $template,
    1
) ?: $template;

// Keep the marker for developer-mode frontend behavior, but the wiki has its
// own sidebar indicator and should not display the general developer banner.
$template = preg_replace_callback('/<span id="dev-mode-banner"([^>]*)>/i', static function ($matches) {
    $attributes = preg_replace('/\sstyle=("[^"]*"|\'[^\']*\')/i', '', (string)$matches[1]);
    return '<span id="dev-mode-banner"' . $attributes . ' aria-hidden="true" style="display: none !important;">';
}, $template, 1) ?: $template;
$template = preg_replace_callback('/<span class="mobile-collapsed-dev-mode-banner"([^>]*)>/i', static function ($matches) {
    $attributes = preg_replace('/\sstyle=("[^"]*"|\'[^\']*\')/i', '', (string)$matches[1]);
    return '<span class="mobile-collapsed-dev-mode-banner"' . $attributes . ' aria-hidden="true" style="display: none !important;">';
}, $template, 1) ?: $template;

$wikiBanner = '<span id="wiki-mode-banner" data-tooltip="You are viewing the fridge.dev developer wiki." style="color: var(--links);"><i class="fa-solid fa-book-open"></i> <b>Developer Wiki</b></span>';
if (strpos($template, 'id="dev-mode-banner"') !== false) {
    $template = preg_replace('/(<span id="dev-mode-banner"[^>]*>.*?<\/span>)/is', '$1' . $wikiBanner, $template, 1) ?: $template;
} elseif (strpos($template, 'id="maintenance-banner"') !== false) {
    $template = preg_replace('/(<span id="maintenance-banner"[^>]*>.*?<\/span>)/is', '$1' . $wikiBanner, $template, 1) ?: $template;
}

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$desktopPageLinks = [];
$mobilePageLinks = [];
foreach ($pages as $page) {
    $isActive = $page['slug'] === $selectedSlug;
    $href = wiki_escape(wiki_slug_to_url($page['slug']));
    $label = wiki_escape($page['label']);
    $activeClass = $isActive ? ' active' : '';
    $desktopPageLinks[] = '<a href="' . $href . '"><div id="tab" class="wiki-nav-button' . $activeClass . '">' . $label . '</div></a>';
    $mobilePageLinks[] = '<a class="mobile-nav-link" href="' . $href . '"><div id="tab" class="mobile-nav-button wiki-nav-button' . $activeClass . '">' . $label . '</div></a>';
}

$desktopNavigationPattern = '#(<div id="header">.*?</div>\s*)(?:<a href="/feed">.*?<a href="/others"><div id="tab".*?</div></a>)(\s*\{user_greeting\})#s';
$mobileNavigationPattern = '#<div class="mobile-nav-grid">.*?</div>\s*(\{user_greeting\})#s';

if (strpos($template, 'class="mobile-nav-grid"') !== false) {
    $mobileNavigation = '<div class="mobile-nav-grid">' . "\n        "
        . implode("\n        ", $mobilePageLinks) . "\n    </div>\n\n    $1";
    $template = preg_replace($mobileNavigationPattern, $mobileNavigation, $template, 1);
} else {
    $desktopNavigation = '$1' . implode("\n    ", $desktopPageLinks) . '$2';
    $template = preg_replace($desktopNavigationPattern, $desktopNavigation, $template, 1);
}

$content = file_get_contents($content_path);
$content = str_replace(
    ['{wiki_page_title}', '{wiki_page_name}', '{wiki_page_content}'],
    [
        wiki_escape($selectedPage['label'] ?? wiki_label_from_slug($selectedSlug)),
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
