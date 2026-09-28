<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/canonical.php';

$cases = [
    '/' => null,
    '/feed/' => null,
    '/feed' => '/feed/',
    '/feed?legacy_domain=fridg3.org' => '/feed/',
    '/feed/?page=1&legacy_domain=fridg3.org' => '/feed/',
    '/feed?page=3&legacy_domain=fridg3.org' => '/feed/?page=3',
    '/gallery?page=2&legacy_domain=fridg3.org' => '/gallery/?page=2',
    '/journal?legacy_domain=fridg3.org' => '/journal/',
    '/wiki?page=Home' => '/wiki/',
    '/wiki/?page=Data-Contracts&legacy_domain=fridg3.org' => '/wiki/?page=Data-Contracts',
    '/feed/posts/2026-01-01_12-00-00/?legacy_domain=fridg3.org' => '/feed/posts/2026-01-01_12-00-00',
    '/feed/posts/2026-01-01_12-00-00?reply_to=abc' => null,
    '/settings/?legacy_domain=fridg3.org' => '/settings/',
    '/feed/?q=hello+world' => null,
    '/feed?q=hello+world' => '/feed/?q=hello+world',
    '/feed/?page=2&q=hello+world' => null,
    '/feed/?page=1&q=hello+world' => '/feed/?q=hello%20world',
];
foreach ($cases as $uri => $expected) {
    $actual = fridge_canonical_request_target($uri);
    if ($actual !== $expected) throw new RuntimeException($uri . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// Subrequests (the nginx hard-ban auth_request) and API scripts receive the
// visitor's REQUEST_URI and must never answer with a canonical redirect.
$root = dirname(__DIR__);
$scriptCases = [
    $root . '/feed/index.php' => true,
    $root . '/account/login/index.php' => true,
    $root . '/api/hard-ban-check/index.php' => false,
    $root . '/api/settings/index.php' => false,
];
foreach ($scriptCases as $script => $expected) {
    $_SERVER['SCRIPT_FILENAME'] = $script;
    if (fridge_canonical_redirect_applies() !== $expected) throw new RuntimeException($script . ': expected canonical redirect ' . ($expected ? 'on' : 'off'));
}
echo "Canonical request redirects passed.\n";
