<?php

if (!function_exists('fridg3_journal_valid_image_src')) {
    function fridg3_journal_valid_image_src(string $src): string
    {
        $src = trim($src);
        if ($src !== '' && ($src[0] === '/' || preg_match('~^https?://~i', $src))) {
            return $src;
        }

        return '';
    }
}

if (!function_exists('fridg3_journal_parse_post')) {
    function fridg3_journal_parse_post(string $raw): ?array
    {
        $lines = preg_split('/\R/', $raw);
        if (!is_array($lines) || count($lines) < 3) {
            return null;
        }

        $bodyOffset = 3;
        $cardImage = '';
        if (isset($lines[$bodyOffset]) && strncmp((string)$lines[$bodyOffset], 'CARD_IMAGE:', 11) === 0) {
            $cardImage = fridg3_journal_valid_image_src(substr((string)$lines[$bodyOffset], 11));
            $bodyOffset++;
        }

        return [
            'date' => (string)($lines[0] ?? ''),
            'title' => (string)($lines[1] ?? ''),
            'description' => (string)($lines[2] ?? ''),
            'cardImage' => $cardImage,
            'body' => implode(PHP_EOL, array_slice($lines, $bodyOffset)),
        ];
    }
}

if (!function_exists('fridg3_journal_build_post')) {
    function fridg3_journal_build_post(
        string $date,
        string $title,
        string $description,
        string $body,
        string $cardImage = ''
    ): string {
        $lines = [$date, $title, $description];
        if ($cardImage !== '') {
            $lines[] = 'CARD_IMAGE:' . $cardImage;
        }
        $lines[] = $body;

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}

if (!function_exists('fridg3_journal_process_card_image')) {
    function fridg3_journal_process_card_image(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['provided' => false, 'url' => ''];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['provided' => true, 'url' => ''];
        }

        $files = [
            'name' => [(string)($file['name'] ?? '')],
            'type' => [(string)($file['type'] ?? '')],
            'tmp_name' => [(string)($file['tmp_name'] ?? '')],
            'error' => [$error],
            'size' => [(int)($file['size'] ?? 0)],
        ];
        $images = fridg3_feed_process_uploaded_images($files);

        return [
            'provided' => true,
            'url' => (string)($images[0]['url'] ?? ''),
        ];
    }
}
