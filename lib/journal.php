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
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        if (str_starts_with($normalized, "v2\n")) {
            $markdown = substr($normalized, 3);
            if (!preg_match('/\A---\n(.*?)\n---(?:\n|$)/s', $markdown, $front)) {
                return null;
            }
            $metadata = [];
            foreach (explode("\n", $front[1]) as $line) {
                if (!preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $field)) continue;
                $metadata[strtolower($field[1])] = trim($field[2], " \t\"'");
            }
            return [
                'format' => 'v2',
                'date' => (string)($metadata['date'] ?? ''),
                'title' => (string)($metadata['title'] ?? ''),
                'description' => (string)($metadata['description'] ?? $metadata['author'] ?? ''),
                'cardImage' => fridg3_journal_valid_image_src((string)($metadata['card_image'] ?? '')),
                'body' => substr($markdown, strlen($front[0])),
                'markdown' => $markdown,
            ];
        }
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
            'format' => 'legacy',
            'date' => (string)($lines[0] ?? ''),
            'title' => (string)($lines[1] ?? ''),
            'description' => (string)($lines[2] ?? ''),
            'cardImage' => $cardImage,
            'body' => implode(PHP_EOL, array_slice($lines, $bodyOffset)),
            'markdown' => '',
        ];
    }
}

if (!function_exists('fridg3_journal_yaml_value')) {
    function fridg3_journal_yaml_value(string $value): string
    {
        return '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ' '], trim($value)) . '"';
    }
}

if (!function_exists('fridg3_journal_build_v2_post')) {
    function fridg3_journal_build_v2_post(string $date, string $title, string $description, string $body, string $cardImage = ''): string
    {
        $metadata = [
            '---',
            'title: ' . fridg3_journal_yaml_value($title),
            'description: ' . fridg3_journal_yaml_value($description),
            'date: ' . $date,
        ];
        if ($cardImage !== '') $metadata[] = 'card_image: ' . fridg3_journal_yaml_value($cardImage);
        $metadata[] = '---';
        return "v2\n" . implode("\n", $metadata) . "\n\n" . trim($body) . "\n";
    }
}

if (!function_exists('fridg3_journal_post_files')) {
    function fridg3_journal_post_files(string $directory): array
    {
        $posts = [];
        foreach (array_merge(glob($directory . DIRECTORY_SEPARATOR . '*.txt') ?: [], glob($directory . DIRECTORY_SEPARATOR . '*.md') ?: []) as $path) {
            $posts[pathinfo($path, PATHINFO_FILENAME)] = $path;
        }
        return array_values($posts);
    }
}

if (!function_exists('fridg3_journal_post_path')) {
    function fridg3_journal_post_path(string $directory, string $postId): ?string
    {
        foreach (['md', 'txt'] as $extension) {
            $path = $directory . DIRECTORY_SEPARATOR . $postId . '.' . $extension;
            if (is_file($path)) return $path;
        }
        return null;
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
