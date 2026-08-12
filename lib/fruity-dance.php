<?php

function fridg3_default_fruity_dance_animations(): array {
    return ['waiting', 'stepping', 'jumping', 'zombie', 'waving', 'hula', 'windmill', 'zitabata', 'dervish', 'held'];
}

function fridg3_fruity_dance_spritesheets(?string $root = null): array {
    $root ??= dirname(__DIR__);
    $directory = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'fruity-dance';
    if (!is_dir($directory)) return [];

    $sheets = [];
    foreach (scandir($directory) ?: [] as $filename) {
        if (in_array(strtolower($filename), ['_custom.png', '_custom2.png'], true)) continue;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.png$/i', $filename)) continue;
        if (!is_file($directory . DIRECTORY_SEPARATOR . $filename)) continue;
        $label = pathinfo($filename, PATHINFO_FILENAME);
        $label = trim(preg_replace('/[_-]+/', ' ', $label));
        $description = 'idle animation preview';
        $animations = fridg3_default_fruity_dance_animations();
        $metaPath = $directory . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME) . '.txt';
        if (is_file($metaPath)) {
            $lines = preg_split('/\R/', (string)file_get_contents($metaPath));
            $metaName = trim((string)($lines[0] ?? ''));
            $metaDescription = trim((string)($lines[1] ?? ''));
            $metaAnimations = array_values(array_filter(array_map('trim', array_slice($lines, 2)), static fn($line) => $line !== ''));
            if ($metaName !== '') $label = $metaName;
            if ($metaDescription !== '') $description = $metaDescription;
            if ($metaAnimations) $animations = $metaAnimations;
        }
        $sheets[$filename] = [
            'filename' => $filename,
            'label' => $label !== '' ? $label : $filename,
            'description' => $description,
            'animations' => $animations,
            'url' => '/resources/images/fruity-dance/' . rawurlencode($filename),
        ];
    }
    uksort($sheets, 'strnatcasecmp');
    return $sheets;
}

function fridg3_default_fruity_dance_spritesheet(?string $root = null): string {
    $sheets = fridg3_fruity_dance_spritesheets($root);
    if (isset($sheets['fl_chan.png'])) return 'fl_chan.png';
    return (string)(array_key_first($sheets) ?? 'fl_chan.png');
}

function fridg3_normalize_fruity_dance_spritesheet($value, ?string $root = null): string {
    $filename = basename((string)$value);
    $sheets = fridg3_fruity_dance_spritesheets($root);
    return isset($sheets[$filename]) ? $filename : fridg3_default_fruity_dance_spritesheet($root);
}
