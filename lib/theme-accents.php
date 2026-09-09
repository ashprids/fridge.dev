<?php
declare(strict_types=1);

// Shared by server rendering, settings validation, and the browser's palette picker.
function fridg3_theme_accent_palettes(): array
{
    static $palettes = null;
    return $palettes ??= json_decode(
        file_get_contents(dirname(__DIR__) . '/themes/palettes/accents.json'),
        true, 512, JSON_THROW_ON_ERROR
    );
}

function fridg3_normalize_theme_accents($values): array
{
    if (!is_array($values)) return [];
    $normalized = [];
    foreach (fridg3_theme_accent_palettes() as $theme => $palette) {
        $value = $values[$theme] ?? null;
        if (is_string($value) && isset($palette['colors'][$value])) {
            $normalized[$theme] = $value;
        }
    }
    return $normalized;
}

function fridg3_inject_theme_accents(string $template, string $theme): string
{
    $palettes = fridg3_theme_accent_palettes();
    $saved = fridg3_normalize_theme_accents([$theme => $_COOKIE['theme_accent_' . $theme] ?? null]);
    $accent = $saved[$theme] ?? ($palettes[$theme]['default'] ?? null);
    $template = preg_replace_callback('/<html\b([^>]*)>/i', static function ($match) use ($accent) {
        $attributes = preg_replace('/\s+data-theme-accent=(["\']).*?\1/i', '', $match[1]);
        return '<html' . $attributes . ($accent === null ? '' : ' data-theme-accent="' . $accent . '"') . '>';
    }, $template, 1) ?? $template;
    if (strpos($template, 'id="theme-accent-palettes"') === false) {
        $json = json_encode($palettes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $config = '<scr' . 'ipt type="application/json" id="theme-accent-palettes">' . $json . '</scr' . 'ipt>';
        $config .= '<scr' . 'ipt>(function(){try{if(sessionStorage.getItem("fridg3ThemeSwitchPending")==="1"){document.documentElement.classList.add("theme-switch-transition","theme-switch-active");window.setTimeout(function(){document.documentElement.classList.remove("theme-switch-active","theme-switch-transition")},2000)}}catch(_){}})()</scr' . 'ipt>';
        $template = preg_replace('/(<head\b[^>]*>)/i', '$1' . $config, $template, 1) ?? $template;
    }
    return $template;
}
