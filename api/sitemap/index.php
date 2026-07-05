<?php
// Generate sitemap.xml at the project root. Admin-only.
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user']['isAdmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$root = dirname(__DIR__, 2);
$baseUrl = 'https://fridge.dev';

$urls = [];

// Normalize path to forward slashes and drop the workspace root prefix
$rootNorm = str_replace('\\', '/', realpath($root));

$addUrl = function(string $path, ?int $lastMod = null, ?float $priority = null) use (&$urls, $baseUrl) {
    $normalized = '/' . ltrim($path, '/');
    $loc = rtrim($baseUrl, '/') . $normalized;

    // Priority defaults
    $isRoot = ($normalized === '/');
    $isFeedPost = str_starts_with($normalized, '/feed/posts/');
    $isJournalPost = str_starts_with($normalized, '/journal/posts/');
    if ($priority === null) {
        if ($isRoot) {
            $priority = 1.0;
        } elseif ($isFeedPost || $isJournalPost) {
            $priority = 0.5;
        } else {
            $priority = 0.6;
        }
    }

    $urls[$loc] = [
        'lastmod' => $lastMod,
        'priority' => $priority,
    ];
};

// Collect static pages by locating index.php files (skip account/api/data/resources/settings/error/formatting)
$skipDirs = ['account', 'api', 'data', 'resources', 'settings', 'error', 'formatting', '.git', 'node_modules'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getFilename() !== 'index.php') {
        continue;
    }
    $currentDir = str_replace('\\', '/', $file->getPath());
    $relativeDir = $currentDir;
    $lowerRoot = strtolower($rootNorm);
    $lowerCurrent = strtolower($currentDir);
    if (strpos($lowerCurrent, $lowerRoot) === 0) {
        $relativeDir = substr($currentDir, strlen($rootNorm));
    }
    $relativeDir = ltrim($relativeDir, '/');
    $parts = array_values(array_filter(explode('/', $relativeDir), 'strlen'));
    if (!empty($parts) && in_array($parts[0], $skipDirs, true)) {
        continue;
    }
    $path = '/';
    if (!empty($parts)) {
        $path .= implode('/', $parts);
    }
    $lastMod = @filemtime($file->getPathname()) ?: null;
    $pathNormalized = $path === '/' ? '/' : $path . '/';
    $skipPaths = ['/journal/create/', '/feed/create/', '/feed/edit/'];
    if (in_array($pathNormalized, $skipPaths, true)) {
        continue;
    }
    $addUrl($pathNormalized, $lastMod);
}

// Feed posts
$feedDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
if (is_dir($feedDir)) {
    foreach (glob($feedDir . DIRECTORY_SEPARATOR . '*.txt') as $postFile) {
        $slug = basename($postFile, '.txt');
        $lastMod = @filemtime($postFile) ?: null;
        $addUrl('/feed/posts/' . rawurlencode($slug), $lastMod);
    }
}

// Journal posts
$journalDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'journal';
if (is_dir($journalDir)) {
    foreach (glob($journalDir . DIRECTORY_SEPARATOR . '*.txt') as $postFile) {
        $slug = basename($postFile, '.txt');
        $lastMod = @filemtime($postFile) ?: null;
        $addUrl('/journal/posts/' . rawurlencode($slug), $lastMod);
    }
}

// Build sitemap XML
$xml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
];
// Order by priority (desc), then loc (asc)
uasort($urls, function($a, $b) {
    $pa = is_array($a) ? ($a['priority'] ?? 0.6) : 0.6;
    $pb = is_array($b) ? ($b['priority'] ?? 0.6) : 0.6;
    if ($pa === $pb) {
        return 0;
    }
    return ($pa > $pb) ? -1 : 1;
});

foreach ($urls as $loc => $lastMod) {
    $lastModVal = is_array($lastMod) ? ($lastMod['lastmod'] ?? null) : $lastMod;
    $priorityVal = is_array($lastMod) ? ($lastMod['priority'] ?? 0.6) : 0.6;
    $xml[] = '  <url>';
    $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc>';
    if ($lastModVal !== null) {
        $xml[] = '    <lastmod>' . gmdate('c', $lastModVal) . '</lastmod>';
    }
    $xml[] = '    <priority>' . number_format((float)$priorityVal, 1, '.', '') . '</priority>';
    $xml[] = '  </url>';
}
$xml[] = '</urlset>';
$xmlContent = implode("\n", $xml) . "\n";

$target = $root . DIRECTORY_SEPARATOR . 'sitemap.xml';
if (@file_put_contents($target, $xmlContent, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}

echo json_encode(['ok' => true, 'count' => count($urls), 'path' => '/sitemap.xml']);
?>
