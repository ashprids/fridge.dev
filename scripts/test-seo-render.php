<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/render.php';
$_SERVER['HTTP_HOST'] = 'fridge.dev';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'Desktop browser';
fridge_start_session();
$_SESSION = [];
$root = dirname(__DIR__);
function render_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach (array_merge(['default'], array_keys(fridge_list_themes($root))) as $theme) {
    foreach ([false, true] as $mobile) {
        $_COOKIE['theme_pref'] = $theme;
        $_COOKIE['mobile_friendly_view'] = $mobile ? '1' : '0';
        $path = get_preferred_template_name($root);
        $template = file_get_contents($root . '/' . ltrim($path, '/'));
        render_check(is_string($template), 'missing template: ' . $path);
        $html = apply_preferred_theme_stylesheet($template, $root);
        $html = str_replace(['{title}', '{description}', '{content}', '{user_greeting}'], ['homepage', 'fallback', '<p>Site content</p>', ''], $html);
        render_check(str_contains($html, '<title data-fridge-seo>fridge.dev</title>'), 'bad home title: ' . $theme);
        render_check(substr_count($html, 'rel="canonical"') === 1, 'bad canonical count: ' . $theme);
        render_check(str_contains($html, 'href="https://fridge.dev/"'), 'bad canonical: ' . $theme);
        render_check(str_contains($html, 'application/ld+json'), 'missing schema: ' . $theme);
        render_check(str_contains($html, 'original music, journal stories'), 'missing description: ' . $theme);
    }
}
unset($_COOKIE['mobile_friendly_view']);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile';
render_check(should_use_mobile_template($root), 'first mobile request did not use mobile layout');
$_COOKIE['mobile_friendly_view'] = '0';
render_check(!should_use_mobile_template($root), 'explicit desktop preference ignored');
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$html = apply_preferred_theme_stylesheet(file_get_contents($root . '/template.html'), $root);
render_check(str_contains($html, '<title data-fridge-seo>[DEV] fridge.dev</title>'), 'dev title lost');
render_check(str_contains($html, 'noindex, follow'), 'dev page indexable');
echo "Desktop/mobile theme metadata, homepage title, first-visit mobile layout, explicit preferences, and developer noindex passed.\n";
