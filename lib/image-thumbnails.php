<?php

if (!function_exists('fridg3_image_thumbnail_path')) {
    function fridg3_image_thumbnail_path(string $sourcePath, string $thumbnailDir): ?string
    {
        if (!is_dir($thumbnailDir) && !@mkdir($thumbnailDir, 0777, true)) return null;
        $thumbnailName = hash('sha256', basename($sourcePath)) . '.jpg';
        $thumbnailPath = $thumbnailDir . DIRECTORY_SEPARATOR . $thumbnailName;
        $sourceMtime = @filemtime($sourcePath) ?: 0;
        if (is_file($thumbnailPath) && ((@filemtime($thumbnailPath) ?: 0) >= $sourceMtime)) return $thumbnailPath;

        $info = @getimagesize($sourcePath);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        $loaders = [
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
        ];
        if (function_exists('imagecreatetruecolor') && isset($loaders[$mime]) && function_exists($loaders[$mime])) {
            $source = @$loaders[$mime]($sourcePath);
            if (!$source) return null;
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                imagedestroy($source);
                return null;
            }
            $cropSize = min($sourceWidth, $sourceHeight);
            $sourceX = (int)floor(($sourceWidth - $cropSize) / 2);
            $sourceY = (int)floor(($sourceHeight - $cropSize) / 2);
            $thumbnail = imagecreatetruecolor(500, 500);
            $background = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefill($thumbnail, 0, 0, $background);
            imagecopyresampled($thumbnail, $source, 0, 0, $sourceX, $sourceY, 500, 500, $cropSize, $cropSize);
            $saved = @imagejpeg($thumbnail, $thumbnailPath, 68);
            imagedestroy($thumbnail);
            imagedestroy($source);
            return $saved ? $thumbnailPath : null;
        }

        // Production already requires ffmpeg for voice notes. Use it when PHP GD
        // is unavailable so listings do not silently fall back to full-size files.
        $ffmpeg = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '') return null;
        $filter = 'scale=500:500:force_original_aspect_ratio=increase,crop=500:500';
        $command = escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -loglevel error -y -i '
            . escapeshellarg($sourcePath) . ' -vf ' . escapeshellarg($filter)
            . ' -frames:v 1 -q:v 8 ' . escapeshellarg($thumbnailPath) . ' 2>&1';
        @exec($command, $output, $status);
        if ($status !== 0 || !is_file($thumbnailPath)) {
            @unlink($thumbnailPath);
            return null;
        }
        return $thumbnailPath;
    }
}

if (!function_exists('fridg3_local_image_thumbnail_url')) {
    function fridg3_local_image_thumbnail_url(string $imageUrl, string $rootDir): string
    {
        $urlPath = parse_url($imageUrl, PHP_URL_PATH);
        if (!is_string($urlPath) || !preg_match('#^/data/images/([^/]+)$#', $urlPath, $matches)) {
            return $imageUrl;
        }

        $filename = rawurldecode($matches[1]);
        if ($filename === '' || basename($filename) !== $filename) {
            return $imageUrl;
        }

        $imagesDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
        $sourcePath = $imagesDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($sourcePath)) {
            return $imageUrl;
        }

        $thumbnailPath = fridg3_image_thumbnail_path(
            $sourcePath,
            $imagesDir . DIRECTORY_SEPARATOR . 'thumbnails'
        );
        if ($thumbnailPath === null) {
            return $imageUrl;
        }

        return '/data/images/thumbnails/' . rawurlencode(basename($thumbnailPath));
    }
}
