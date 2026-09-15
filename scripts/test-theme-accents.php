<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/theme-accents.php';

function check_accent(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$template = '<!doctype html><html lang="en"><head></head><body></body></html>';
$originalCookies = $_COOKIE;
try {
    foreach (fridge_theme_accent_palettes() as $theme => $palette) {
        check_accent(isset($palette['colors'][$palette['default']]), "Missing $theme default");
        foreach ($palette['colors'] as $choice => $hex) {
            check_accent((bool)preg_match('/^#[A-F0-9]{6}$/', $hex), "Invalid $theme swatch");
            check_accent(fridge_normalize_theme_accents([$theme => $choice]) === [$theme => $choice], "Rejected $theme/$choice");
            $_COOKIE['theme_accent_' . $theme] = $choice;
            $html = fridge_inject_theme_accents($template, $theme);
            check_accent(str_contains($html, 'data-theme-accent="' . $choice . '"'), "Missing first-render $theme/$choice");
            check_accent(str_contains($html, 'fridg3ThemeSwitchPending'), 'Missing incoming theme-fade bootstrap');
            check_accent(fridge_inject_theme_accents($html, $theme) === $html, "Duplicate injection for $theme");
        }
        foreach (['not-a-color', '#00FF00', '"><script>alert(1)</script>', ['red'], null] as $invalid) {
            check_accent(fridge_normalize_theme_accents([$theme => $invalid]) === [], "Accepted invalid $theme accent");
            $_COOKIE['theme_accent_' . $theme] = $invalid;
            $html = fridge_inject_theme_accents($template, $theme);
            check_accent(str_contains($html, 'data-theme-accent="' . $palette['default'] . '"'), "Unsafe $theme cookie did not fall back");
        }
    }
    check_accent(fridge_normalize_theme_accents(['classic' => 'red']) === [], 'Classic must keep its independent colour contract');
    check_accent(!str_contains(fridge_inject_theme_accents($template, 'classic'), 'data-theme-accent='), 'Accent leaked into Classic');
    check_accent(!str_contains(fridge_inject_theme_accents($html, 'default'), 'data-theme-accent='), 'Accent leaked when switching to default');
} finally {
    $_COOKIE = $originalCookies;
}
echo "Theme accent validation and first-render checks passed.\n";
