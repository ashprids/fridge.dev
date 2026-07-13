<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['username'])) {
    header('Location: /account/login');
    exit;
}

$currentUsername = $_SESSION['user']['username'] ?? null;
if ($currentUsername !== null) {
    $accountsPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    if (is_file($accountsPath)) {
        $accountsData = json_decode(@file_get_contents($accountsPath), true);
        if (is_array($accountsData) && isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
            foreach ($accountsData['accounts'] as $account) {
                if (isset($account['username']) && $account['username'] === $currentUsername) {
                    $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
                    $_SESSION['user']['postingRestricted'] = (bool)($account['postingRestricted'] ?? false);
                    $_SESSION['user']['allowedPages'] = (array)($account['allowedPages'] ?? []);
                    break;
                }
            }
        }
    }
}

$isAdmin = $_SESSION['user']['isAdmin'] ?? false;
if (!$isAdmin) {
    header('Location: /journal');
    exit;
}

function bbcode_to_html(string $text): string {
    $replacements = [
        '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
        '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[s\](.*?)\[\/s\]/is' => '<s>$1</s>',
        '/\[code=([^\]]*)\](.*?)\[\/code\]/is' => '<pre><code class="language-$1">$2</code></pre>',
        '/\[code\](.*?)\[\/code\]/is' => '<pre><code>$1</code></pre>',
        '/\[h3\](.*?)\[\/h3\]/is' => '<h3>$1</h3>',
        '/\[h4\](.*?)\[\/h4\]/is' => '<h4>$1</h4>',
        '/\[h5\](.*?)\[\/h5\]/is' => '<h5>$1</h5>',
    ];

    $html = $text;
    foreach ($replacements as $pattern => $replacement) {
        $html = preg_replace($pattern, $replacement, $html);
    }

    $html = preg_replace_callback('/\[color[:=]([^\]]+)\](.*?)\[\/color\]/is', function($m) {
        $color = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        return '<span style="color: ' . $color . ';">' . $m[2] . '</span>';
    }, $html);

    $html = preg_replace_callback('/\[list\](.*?)\[\/list\]/is', function($m) {
        $inner = $m[1];
        $lines = preg_split('/\r\n|\r|\n/', $inner);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $items[] = '<li>' . $line . '</li>';
        }
        if (empty($items)) {
            return '';
        }
        return '<ul>' . implode('', $items) . '</ul>';
    }, $html);

    $html = preg_replace_callback('/\[img=([^\]]+)\](?:\[name:([^\]]*)\])?(?:\[\/img\])?/i', function($m) {
        $rawUrl = $m[1];
        $url = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
        $altSource = isset($m[2]) && strlen(trim($m[2])) > 0 ? trim($m[2]) : basename($rawUrl);
        $alt = htmlspecialchars($altSource, ENT_QUOTES, 'UTF-8');
        return '<img id="post-image" src="' . $url . '" alt="' . $alt . '">';
    }, $html);

    $html = preg_replace_callback('/\[spoiler\](.*?)\[\/spoiler\]/is', function($m) {
        return '<span class="spoiler">' . $m[1] . '</span>';
    }, $html);

    $html = preg_replace_callback('/\[tooltip=([^\]]+)\](.*?)\[\/tooltip\]/is', function($m) {
        $tooltip = htmlspecialchars($m[1], ENT_COMPAT, 'UTF-8');
        return '<span data-tooltip="' . $tooltip . '">' . $m[2] . '</span>';
    }, $html);

    $html = preg_replace_callback('/\[(link|url)=([^\]]+)\](.*?)\[\/(link|url)\]/is', function($m) {
        $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        return '<a href="' . $url . '">' . $m[3] . '</a>';
    }, $html);

    $preserved = [];
    $html = preg_replace_callback('/<h[345][^>]*>.*?<\/h[345]>|<pre><code[^>]*>.*?<\/code><\/pre>/is', function($m) use (&$preserved) {
        $placeholder = '___PRESERVE_' . count($preserved) . '___';
        $preserved[$placeholder] = $m[0];
        return $placeholder;
    }, $html);

    $html = preg_replace('/\r\n|\r|\n/', '<br>', $html);

    foreach ($preserved as $placeholder => $content) {
        $html = str_replace($placeholder, $content, $html);
    }

    $html = preg_replace('/(<h3[^>]*>.*?<\/h3>)(?:<br\s*\/?\s*>)+/is', '$1', $html);
    $html = preg_replace('/(<h4[^>]*>.*?<\/h4>)(?:<br\s*\/?\s*>)+/is', '$1', $html);
    $html = preg_replace('/<\/h5><br\s*\/?\s*>/i', '</h5>', $html);
    $html = preg_replace('/<br\s*\/?\s*>\s*(<h3[^>]*>)/i', '$1', $html);

    return $html;
}

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

$draftsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'drafts';
$requestedDraft = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['draft'] ?? ''));
$postId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['post'] ?? ''));
$backHref = '/journal/edit' . ($postId !== '' ? ('?post=' . urlencode($postId)) : '');

$draftTitle = 'draft preview';
$draftSubtitle = '';
$draftDate = date('Y-m-d H:i:s');
$draftBody = '';
$draftFormat = 'bbcode';
$hasDraft = false;

$tryLoadDraft = function(string $draftFilePath) use (&$draftTitle, &$draftSubtitle, &$draftBody, &$draftDate, &$draftFormat, &$hasDraft) {
    $lines = @file($draftFilePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false || count($lines) < 2) {
        return;
    }

    $offset = 0;
    if (isset($lines[0]) && strncmp($lines[0], 'USER:', 5) === 0) {
        $offset = 1;
    }

    if (!isset($lines[$offset + 1])) {
        return;
    }

    $draftTitle = trim((string)($lines[$offset] ?? ''));
    $draftSubtitle = trim((string)($lines[$offset + 1] ?? ''));

    $bodyOffset = $offset + 2;
    if (isset($lines[$bodyOffset]) && preg_match('/^FORMAT:([a-zA-Z0-9_-]+)$/', (string)$lines[$bodyOffset], $fmt)) {
        $draftFormat = strtolower($fmt[1]);
        $bodyOffset++;
    } else {
        $draftFormat = 'bbcode';
    }

    $draftBody = implode(PHP_EOL, array_slice($lines, $bodyOffset));
    $mtime = @filemtime($draftFilePath);
    if ($mtime !== false) {
        $draftDate = date('Y-m-d H:i:s', $mtime);
    }
    $hasDraft = true;
};

$sessionUsername = $_SESSION['user']['username'] ?? null;
if (is_dir($draftsDir)) {
    if ($requestedDraft !== '') {
        $target = $draftsDir . DIRECTORY_SEPARATOR . $requestedDraft . '.txt';
        if (is_file($target)) {
            $lines = @file($target, FILE_IGNORE_NEW_LINES);
            $owner = null;
            if (is_array($lines) && isset($lines[0]) && strncmp($lines[0], 'USER:', 5) === 0) {
                $owner = substr($lines[0], 5);
            }
            $isAllowed = $isAdmin || ($sessionUsername !== null && $owner !== null && $owner === $sessionUsername);
            if ($isAllowed) {
                $tryLoadDraft($target);
            }
        }
    }

    if (!$hasDraft) {
        $draftFiles = glob($draftsDir . DIRECTORY_SEPARATOR . '*.txt');
        usort($draftFiles, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        foreach ($draftFiles as $draftFile) {
            $lines = @file($draftFile, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines) || count($lines) < 2) {
                continue;
            }
            $owner = null;
            if (isset($lines[0]) && strncmp($lines[0], 'USER:', 5) === 0) {
                $owner = substr($lines[0], 5);
            }
            $isAllowed = $isAdmin || ($sessionUsername !== null && $owner !== null && $owner === $sessionUsername);
            if (!$isAllowed) {
                continue;
            }
            $tryLoadDraft($draftFile);
            if ($hasDraft) {
                break;
            }
        }
    }
}

$title = $hasDraft ? $draftTitle : 'preview';
$description = $hasDraft ? $draftSubtitle : 'preview draft';
$contentHtml = '<p>no draft found. save a draft from the journal editor first.</p><p><a href="' . htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') . '">return to editor</a></p>';
if ($hasDraft) {
    if ($draftFormat === 'html') {
        $contentHtml = $draftBody;
    } else {
        $contentHtml = bbcode_to_html($draftBody);
    }
}

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
$content = file_get_contents($content_path);
$content = str_replace('{title}', htmlspecialchars($hasDraft ? $draftTitle : 'draft preview', ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{subtitle}', htmlspecialchars($hasDraft ? $draftSubtitle : 'journal post preview', ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{date}', htmlspecialchars($draftDate, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{back_href}', htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8'), $content);
$content = str_replace('{content}', $contentHtml, $content);

$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), $html);
$html = str_replace('{description}', htmlspecialchars($description, ENT_QUOTES, 'UTF-8'), $html);

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
