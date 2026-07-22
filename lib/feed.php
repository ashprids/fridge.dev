<?php

if (!function_exists('fridg3_feed_find_root')) {
    function fridg3_feed_find_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('fridg3_feed_posts_dir')) {
    function fridg3_feed_posts_dir(): string
    {
        return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
    }
}

if (!function_exists('fridg3_feed_replies_dir')) {
    function fridg3_feed_replies_dir(): string
    {
        return fridg3_feed_posts_dir() . DIRECTORY_SEPARATOR . 'replies';
    }
}

if (!function_exists('fridg3_feed_images_dir')) {
    function fridg3_feed_images_dir(): string
    {
        return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
    }
}

if (!function_exists('fridg3_feed_voice_dir')) {
    function fridg3_feed_voice_dir(): string
    {
        return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . 'voice';
    }
}

if (!function_exists('fridg3_feed_banned_ips_path')) {
    function fridg3_feed_banned_ips_path(): string
    {
        return fridg3_feed_posts_dir() . DIRECTORY_SEPARATOR . 'banned_ips.json';
    }
}

if (!function_exists('fridg3_feed_filters_dir')) {
    function fridg3_feed_filters_dir(): string
    {
        return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'feed' . DIRECTORY_SEPARATOR . 'filters';
    }
}

if (!function_exists('fridg3_feed_filter_terms')) {
    function fridg3_feed_filter_terms(): array
    {
        static $terms = null;
        if (is_array($terms)) {
            return $terms;
        }

        $terms = [];
        $seen = [];
        $files = glob(fridg3_feed_filters_dir() . DIRECTORY_SEPARATOR . '*.txt');
        if ($files === false) {
            return $terms;
        }

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $term = trim((string)$line);
                if ($term === '' || str_starts_with($term, '#')) {
                    continue;
                }
                $key = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $terms[] = $term;
            }
        }

        usort($terms, static function (string $a, string $b): int {
            $aLength = function_exists('mb_strlen') ? mb_strlen($a) : strlen($a);
            $bLength = function_exists('mb_strlen') ? mb_strlen($b) : strlen($b);
            return $bLength <=> $aLength;
        });

        return $terms;
    }
}

if (!function_exists('fridg3_feed_star_count')) {
    function fridg3_feed_star_count(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return max(1, mb_strlen($value));
        }
        if (preg_match_all('/./us', $value, $matches) !== false) {
            return max(1, count($matches[0]));
        }
        return max(1, strlen($value));
    }
}

if (!function_exists('fridg3_feed_filter_term_pattern')) {
    function fridg3_feed_filter_term_pattern(string $term): string
    {
        $escaped = preg_quote($term, '/');
        $needsStartBoundary = preg_match('/^[\p{L}\p{N}_]/u', $term) === 1;
        $needsEndBoundary = preg_match('/[\p{L}\p{N}_]$/u', $term) === 1;
        $prefix = $needsStartBoundary ? '(^|[^\p{L}\p{N}_])' : '()';
        $suffix = $needsEndBoundary ? '(?=$|[^\p{L}\p{N}_])' : '';

        return '/' . $prefix . '(' . $escaped . ')' . $suffix . '/iu';
    }
}

if (!function_exists('fridg3_feed_filter_tooltip_text')) {
    function fridg3_feed_filter_tooltip_text(): string
    {
        return 'this phrase was automatically filtered.';
    }
}

if (!function_exists('fridg3_feed_non_whitespace_count')) {
    function fridg3_feed_non_whitespace_count(string $value): int
    {
        if (preg_match_all('/\S/u', $value, $matches) !== false) {
            return count($matches[0]);
        }
        return strlen(preg_replace('/\s+/', '', $value) ?? '');
    }
}

if (!function_exists('fridg3_feed_filter_visible_text')) {
    function fridg3_feed_filter_visible_text(string $text): string
    {
        $withoutTags = preg_replace('/\[[^\]]+\]/', ' ', $text);
        return is_string($withoutTags) ? $withoutTags : $text;
    }
}

if (!function_exists('fridg3_feed_filter_stats')) {
    function fridg3_feed_filter_stats(string $text): array
    {
        $terms = fridg3_feed_filter_terms();
        $scanText = fridg3_feed_filter_visible_text($text);
        $stats = [
            'totalChars' => fridg3_feed_non_whitespace_count($scanText),
            'matchedChars' => 0,
            'matchedTerms' => 0,
        ];

        if ($scanText === '' || empty($terms)) {
            return $stats;
        }

        foreach ($terms as $term) {
            $next = preg_replace_callback(fridg3_feed_filter_term_pattern($term), static function (array $match) use (&$stats): string {
                $prefix = (string)($match[1] ?? '');
                $matchedTerm = (string)($match[2] ?? '');
                $stats['matchedChars'] += fridg3_feed_non_whitespace_count($matchedTerm);
                $stats['matchedTerms']++;
                return $prefix . str_repeat('★', fridg3_feed_star_count($matchedTerm));
            }, $scanText);
            if (is_string($next)) {
                $scanText = $next;
            }
        }

        return $stats;
    }
}

if (!function_exists('fridg3_feed_guest_filter_is_mostly_filtered')) {
    function fridg3_feed_guest_filter_is_mostly_filtered(string $text): bool
    {
        $stats = fridg3_feed_filter_stats($text);
        if ($stats['totalChars'] <= 0 || $stats['matchedTerms'] <= 0) {
            return false;
        }

        return ($stats['matchedChars'] / $stats['totalChars']) >= 0.5;
    }
}

if (!function_exists('fridg3_feed_guest_reply_has_filtered_text')) {
    function fridg3_feed_guest_reply_has_filtered_text(array $reply): bool
    {
        $body = (string)($reply['body'] ?? '');
        if ($body === '') {
            return false;
        }
        if (strpos($body, fridg3_feed_filter_tooltip_text()) !== false) {
            return true;
        }

        return fridg3_feed_apply_guest_filter($body, true) !== $body;
    }
}

if (!function_exists('fridg3_feed_apply_guest_filter')) {
    function fridg3_feed_apply_guest_filter(string $text, bool $withTooltip = false): string
    {
        $terms = fridg3_feed_filter_terms();
        if ($text === '' || empty($terms)) {
            return $text;
        }

        $filtered = $text;
        foreach ($terms as $term) {
            $next = preg_replace_callback(fridg3_feed_filter_term_pattern($term), static function (array $match) use ($withTooltip): string {
                $prefix = (string)($match[1] ?? '');
                $matchedTerm = (string)($match[2] ?? '');
                $stars = str_repeat('★', fridg3_feed_star_count($matchedTerm));
                if ($withTooltip) {
                    return $prefix . '[tooltip="' . fridg3_feed_filter_tooltip_text() . '"]' . $stars . '[/tooltip]';
                }
                return $prefix . $stars;
            }, $filtered);
            if (is_string($next)) {
                $filtered = $next;
            }
        }

        return $filtered;
    }
}

if (!function_exists('fridg3_feed_client_ip')) {
    function fridg3_feed_client_ip(): string
    {
        $headerCandidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headerCandidates as $header) {
            if (!isset($_SERVER[$header]) || $_SERVER[$header] === '') {
                continue;
            }

            foreach (explode(',', (string)$_SERVER[$header]) as $part) {
                $candidate = trim($part);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('fridg3_feed_humanize_datetime')) {
    function fridg3_feed_humanize_datetime(string $dtStr): string
    {
        try {
            $dt = new DateTime($dtStr);
            $now = new DateTime('now');
            $diff = $now->getTimestamp() - $dt->getTimestamp();
            if ($diff < 60) return $diff . 's ago';
            if ($diff < 3600) return floor($diff / 60) . 'm ago';
            if ($diff < 86400) return floor($diff / 3600) . 'h ago';
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return $dtStr;
        }
    }
}

if (!function_exists('fridg3_feed_accounts_path')) {
    function fridg3_feed_accounts_path(): string
    {
        return fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    }
}

if (!function_exists('fridg3_feed_load_accounts')) {
    function fridg3_feed_load_accounts(): array
    {
        $accountsPath = fridg3_feed_accounts_path();
        if (!is_file($accountsPath)) {
            return ['accounts' => []];
        }

        $decoded = json_decode((string)@file_get_contents($accountsPath), true);
        if (!is_array($decoded) || !isset($decoded['accounts']) || !is_array($decoded['accounts'])) {
            return ['accounts' => []];
        }

        return $decoded;
    }
}

if (!function_exists('fridg3_feed_registered_username_exists')) {
    function fridg3_feed_registered_username_exists(string $username): bool
    {
        $target = strtolower(ltrim(trim($username), '@'));
        if ($target === '') {
            return false;
        }

        foreach (fridg3_feed_load_accounts()['accounts'] as $account) {
            $accountUsername = strtolower(trim((string)($account['username'] ?? '')));
            if ($accountUsername !== '' && $accountUsername === $target) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fridg3_feed_refresh_session_user')) {
    function fridg3_feed_refresh_session_user(): void
    {
        if (!isset($_SESSION['user']['username'])) {
            return;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        $accountsData = fridg3_feed_load_accounts();
        foreach ($accountsData['accounts'] as $account) {
            if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
                continue;
            }

            $_SESSION['user']['name'] = htmlspecialchars((string)($account['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
            $_SESSION['user']['postingRestricted'] = (bool)($account['postingRestricted'] ?? false);
            $_SESSION['user']['allowedPages'] = array_map(static function ($page) {
                return htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8');
            }, (array)($account['allowedPages'] ?? []));
            break;
        }
    }
}

if (!function_exists('fridg3_feed_current_user_can_moderate_replies')) {
    function fridg3_feed_current_user_can_moderate_replies(string $postOwnerUsername): bool
    {
        if (!isset($_SESSION['user']['username'])) {
            return false;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        $isAdmin = !empty($_SESSION['user']['isAdmin']);
        $allowedPages = array_map('strval', (array)($_SESSION['user']['allowedPages'] ?? []));
        return $isAdmin
            || $currentUsername === ltrim($postOwnerUsername, '@')
            || in_array('comments', $allowedPages, true);
    }
}

if (!function_exists('fridg3_feed_current_user_can_manage_reply')) {
    function fridg3_feed_current_user_can_manage_reply(string $postOwnerUsername, string $replyUsername): bool
    {
        if (!isset($_SESSION['user']['username'])) {
            return false;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        return $currentUsername === ltrim($replyUsername, '@')
            || fridg3_feed_current_user_can_moderate_replies($postOwnerUsername);
    }
}

if (!function_exists('fridg3_feed_current_visitor_can_manage_reply')) {
    function fridg3_feed_current_visitor_can_manage_reply(string $postOwnerUsername, array $reply, string $clientIp): bool
    {
        if (isset($_SESSION['user']['username'])) {
            return fridg3_feed_current_user_can_manage_reply($postOwnerUsername, (string)($reply['username'] ?? ''));
        }

        return ($reply['isGuest'] ?? false) === true
            && $clientIp !== ''
            && (string)($reply['ip'] ?? '') === $clientIp;
    }
}

if (!function_exists('fridg3_feed_reply_fallback_id')) {
    function fridg3_feed_reply_fallback_id(array $reply, int $index): string
    {
        $seed = ($reply['username'] ?? '') . '|' . ($reply['date'] ?? '') . '|' . ($reply['body'] ?? '') . '|' . $index;
        return 'legacy_' . substr(sha1($seed), 0, 16);
    }
}

if (!function_exists('fridg3_feed_write_replies')) {
    function fridg3_feed_write_replies(string $postId, array $replies): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return false;
        }

        $repliesDir = fridg3_feed_replies_dir();
        if (!is_dir($repliesDir) && !@mkdir($repliesDir, 0777, true) && !is_dir($repliesDir)) {
            return false;
        }

        $payload = json_encode(['replies' => array_values($replies)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $replyFile = $repliesDir . DIRECTORY_SEPARATOR . $safePostId . '.json';
        return @file_put_contents($replyFile, $payload, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_feed_load_banned_ips')) {
    function fridg3_feed_load_banned_ips(): array
    {
        $path = fridg3_feed_banned_ips_path();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}

if (!function_exists('fridg3_feed_write_banned_ips')) {
    function fridg3_feed_write_banned_ips(array $bannedIps): bool
    {
        $path = fridg3_feed_banned_ips_path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        $payload = json_encode($bannedIps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        return @file_put_contents($path, $payload, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_feed_is_ip_banned')) {
    function fridg3_feed_is_ip_banned(string $ip): bool
    {
        $targetIp = trim($ip);
        if ($targetIp === '') {
            return false;
        }

        foreach (fridg3_feed_load_banned_ips() as $key => $entry) {
            if (is_string($key) && $key === $targetIp) {
                return true;
            }
            if (is_string($entry) && $entry === $targetIp) {
                return true;
            }
            if (is_array($entry) && (string)($entry['ip'] ?? '') === $targetIp) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fridg3_feed_ban_guest_ip')) {
    function fridg3_feed_ban_guest_ip(string $ip, string $adminUsername, string $guestUsername): bool
    {
        $targetIp = trim($ip);
        if (!filter_var($targetIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        $bannedIps = fridg3_feed_load_banned_ips();
        $existing = isset($bannedIps[$targetIp]) && is_array($bannedIps[$targetIp])
            ? $bannedIps[$targetIp]
            : [];
        $usernames = [];
        foreach ((array)($existing['usernames'] ?? []) as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $usernames[$name] = true;
            }
        }
        $guestUsername = trim($guestUsername);
        if ($guestUsername !== '') {
            $usernames[$guestUsername] = true;
        }

        $bannedIps[$targetIp] = array_merge($existing, [
            'ip' => $targetIp,
            'bannedAt' => date('Y-m-d H:i:s'),
            'bannedBy' => $adminUsername,
            'usernames' => array_keys($usernames),
        ]);

        return fridg3_feed_write_banned_ips($bannedIps);
    }
}

if (!function_exists('fridg3_feed_verify_current_admin_password')) {
    function fridg3_feed_verify_current_admin_password(string $password): bool
    {
        $currentUsername = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
        if ($currentUsername === '' || empty($_SESSION['user']['isAdmin'])) {
            return false;
        }

        $accountsData = fridg3_feed_load_accounts();
        foreach ($accountsData['accounts'] as $account) {
            if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
                continue;
            }
            if (empty($account['password'])) {
                return $password === '';
            }

            $storedPassword = (string)$account['password'];
            if (password_get_info($storedPassword)['algo'] !== null) {
                return password_verify($password, $storedPassword);
            }

            return hash_equals($storedPassword, $password);
        }

        return false;
    }
}

if (!function_exists('fridg3_feed_extract_voice_files')) {
    function fridg3_feed_extract_voice_files(string $content): array
    {
        preg_match_all('/\[audio=([^\]\s]+)\](?:\[name:[^\]]*\])?/i', $content, $matches);

        $filenames = [];
        foreach ($matches[1] ?? [] as $rawUrl) {
            $url = trim(html_entity_decode((string)$rawUrl, ENT_QUOTES, 'UTF-8'), "\"'");
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                $path = $url;
            }

            $path = str_replace('\\', '/', rawurldecode($path));
            if (preg_match('#(?:^|/)data/audio/voice/([a-zA-Z0-9_.-]+)$#i', $path, $fileMatch) !== 1) {
                continue;
            }

            $filenames[] = basename($fileMatch[1]);
        }

        return array_values(array_unique($filenames));
    }
}

if (!function_exists('fridg3_feed_delete_voice_files_from_content')) {
    function fridg3_feed_delete_voice_files_from_content(string $content): void
    {
        $voiceDir = fridg3_feed_voice_dir();
        foreach (fridg3_feed_extract_voice_files($content) as $filename) {
            $path = $voiceDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (preg_match_all('/\[(audio|video)=([^\]]+)\]/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtolower((string)$match[1]);
                $urlPath = (string)(parse_url(html_entity_decode((string)$match[2], ENT_QUOTES, 'UTF-8'), PHP_URL_PATH) ?? '');
                $relativePrefixes = $type === 'video'
                    ? ['/data/video/']
                    : ['/data/audio/uploads/', '/data/audio/attachments/'];
                $relativePrefix = null;
                foreach ($relativePrefixes as $candidatePrefix) {
                    if (str_starts_with($urlPath, $candidatePrefix)) {
                        $relativePrefix = $candidatePrefix;
                        break;
                    }
                }
                if ($relativePrefix === null) {
                    continue;
                }
                $directory = fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                    . ($type === 'video'
                        ? 'video'
                        : 'audio' . DIRECTORY_SEPARATOR . (str_contains($relativePrefix, '/attachments/') ? 'attachments' : 'uploads'));
                $path = $directory . DIRECTORY_SEPARATOR . basename($urlPath);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}

if (!function_exists('fridg3_feed_delete_post_voice_files')) {
    function fridg3_feed_delete_post_voice_files(string $postId, string $postBody): void
    {
        fridg3_feed_delete_voice_files_from_content($postBody);

        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return;
        }

        foreach (fridg3_feed_load_replies($safePostId) as $reply) {
            fridg3_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
        }
    }
}

if (!function_exists('fridg3_feed_load_replies')) {
    function fridg3_feed_load_replies(string $postId): array
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return [];
        }

        $replyFile = fridg3_feed_replies_dir() . DIRECTORY_SEPARATOR . $safePostId . '.json';
        if (!is_file($replyFile)) {
            return [];
        }

        $json = @file_get_contents($replyFile);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        $replies = is_array($decoded['replies'] ?? null) ? $decoded['replies'] : [];

        $normalized = [];
        foreach ($replies as $index => $reply) {
            if (!is_array($reply)) {
                continue;
            }

            $username = isset($reply['username']) ? ltrim((string)$reply['username'], '@') : '';
            $date = isset($reply['date']) ? (string)$reply['date'] : '';
            $body = isset($reply['body']) ? (string)$reply['body'] : '';
            if ($username === '' || $date === '' || trim($body) === '') {
                continue;
            }

            $normalizedReply = $reply;
            $normalizedReply['id'] = isset($reply['id']) && (string)$reply['id'] !== ''
                ? (string)$reply['id']
                : fridg3_feed_reply_fallback_id($reply, $index);
            $normalizedReply['username'] = $username;
            $normalizedReply['date'] = $date;
            $normalizedReply['body'] = $body;
            if (isset($reply['parentId']) && is_string($reply['parentId'])) {
                $parentId = trim($reply['parentId']);
                if ($parentId !== '' && $parentId !== $normalizedReply['id']) {
                    $normalizedReply['parentId'] = $parentId;
                } else {
                    unset($normalizedReply['parentId']);
                }
            }
            $normalized[] = $normalizedReply;
        }

        return $normalized;
    }
}

if (!function_exists('fridg3_feed_reply_exists')) {
    function fridg3_feed_reply_exists(array $replies, string $replyId): bool
    {
        $targetId = trim($replyId);
        if ($targetId === '') {
            return false;
        }

        foreach ($replies as $reply) {
            if ((string)($reply['id'] ?? '') === $targetId) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fridg3_feed_normalize_guest_browser_id')) {
    function fridg3_feed_normalize_guest_browser_id(string $browserId): string
    {
        $browserId = strtolower(trim($browserId));
        return preg_match('/^[a-f0-9]{32}$/', $browserId) === 1 ? $browserId : '';
    }
}

if (!function_exists('fridg3_feed_collect_guest_usernames_by_ip')) {
    function fridg3_feed_collect_guest_usernames_by_ip(): array
    {
        $repliesDir = fridg3_feed_replies_dir();
        if (!is_dir($repliesDir)) {
            return [];
        }

        $files = glob($repliesDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return [];
        }

        $usernamesByIp = [];
        foreach ($files as $replyFile) {
            $postId = pathinfo(basename((string)$replyFile), PATHINFO_FILENAME);
            foreach (fridg3_feed_load_replies($postId) as $reply) {
                if (($reply['isGuest'] ?? false) !== true) {
                    continue;
                }

                $ip = trim((string)($reply['ip'] ?? ''));
                if ($ip === '') {
                    continue;
                }

                $username = trim((string)($reply['username'] ?? 'Anonymous'));
                if ($username === '') {
                    $username = 'Anonymous';
                }

                if (!isset($usernamesByIp[$ip])) {
                    $usernamesByIp[$ip] = [];
                }
                $usernamesByIp[$ip][$username] = true;
            }
        }

        $result = [];
        foreach ($usernamesByIp as $ip => $usernames) {
            $result[$ip] = array_keys($usernames);
            sort($result[$ip], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $result;
    }
}

if (!function_exists('fridg3_feed_collect_guest_replies_by_ip')) {
    function fridg3_feed_collect_guest_replies_by_ip(): array
    {
        $repliesDir = fridg3_feed_replies_dir();
        if (!is_dir($repliesDir)) {
            return [];
        }

        $files = glob($repliesDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return [];
        }

        $repliesByIp = [];
        foreach ($files as $replyFile) {
            $postId = pathinfo(basename((string)$replyFile), PATHINFO_FILENAME);
            foreach (fridg3_feed_load_replies($postId) as $reply) {
                if (($reply['isGuest'] ?? false) !== true) {
                    continue;
                }

                $ip = trim((string)($reply['ip'] ?? ''));
                if ($ip === '') {
                    continue;
                }

                if (!isset($repliesByIp[$ip])) {
                    $repliesByIp[$ip] = [];
                }

                $repliesByIp[$ip][] = [
                    'postId' => $postId,
                    'replyId' => (string)($reply['id'] ?? ''),
                    'username' => (string)($reply['username'] ?? 'Anonymous'),
                    'date' => (string)($reply['date'] ?? ''),
                    'body' => (string)($reply['body'] ?? ''),
                ];
            }
        }

        foreach ($repliesByIp as $ip => $replies) {
            usort($replies, static function (array $a, array $b): int {
                return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
            });
            $repliesByIp[$ip] = $replies;
        }
        ksort($repliesByIp, SORT_NATURAL);

        return $repliesByIp;
    }
}

if (!function_exists('fridg3_feed_purge_guest_replies_by_ip')) {
    function fridg3_feed_purge_guest_replies_by_ip(string $ip): array
    {
        $targetIp = trim($ip);
        $deleted = 0;
        $failed = 0;
        $touchedFiles = 0;

        if ($targetIp === '' || !filter_var($targetIp, FILTER_VALIDATE_IP)) {
            return [
                'deleted' => 0,
                'failed' => 0,
                'touchedFiles' => 0,
            ];
        }

        $repliesDir = fridg3_feed_replies_dir();
        if (!is_dir($repliesDir)) {
            return [
                'deleted' => 0,
                'failed' => 0,
                'touchedFiles' => 0,
            ];
        }

        $files = glob($repliesDir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return [
                'deleted' => 0,
                'failed' => 1,
                'touchedFiles' => 0,
            ];
        }

        foreach ($files as $replyFile) {
            $postId = pathinfo(basename((string)$replyFile), PATHINFO_FILENAME);
            $replies = fridg3_feed_load_replies($postId);
            $updatedReplies = [];
            $removedFromFile = 0;

            foreach ($replies as $reply) {
                $isTargetGuestReply = ($reply['isGuest'] ?? false) === true
                    && (string)($reply['ip'] ?? '') === $targetIp;

                if ($isTargetGuestReply) {
                    fridg3_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
                    $removedFromFile++;
                    continue;
                }

                $updatedReplies[] = $reply;
            }

            if ($removedFromFile === 0) {
                continue;
            }

            if (fridg3_feed_write_replies($postId, $updatedReplies)) {
                $deleted += $removedFromFile;
                $touchedFiles++;
            } else {
                $failed++;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'touchedFiles' => $touchedFiles,
        ];
    }
}

if (!function_exists('fridg3_feed_save_reply')) {
    function fridg3_feed_save_reply(string $postId, string $username, string $body, string $parentId = ''): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', ltrim($username, '@'));
        $trimmedBody = trim($body);

        if ($safePostId === '' || $safeUsername === '' || $trimmedBody === '') {
            return false;
        }

        $repliesDir = fridg3_feed_replies_dir();
        if (!is_dir($repliesDir) && !@mkdir($repliesDir, 0777, true) && !is_dir($repliesDir)) {
            return false;
        }

        $replyFile = $repliesDir . DIRECTORY_SEPARATOR . $safePostId . '.json';
        $existingReplies = fridg3_feed_load_replies($safePostId);
        $newReply = [
            'id' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'username' => $safeUsername,
            'date' => date('Y-m-d H:i:s'),
            'body' => $trimmedBody,
        ];
        if ($parentId !== '' && fridg3_feed_reply_exists($existingReplies, $parentId)) {
            $newReply['parentId'] = $parentId;
        }
        $existingReplies[] = $newReply;

        $payload = json_encode(['replies' => $existingReplies], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        return @file_put_contents($replyFile, $payload, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_feed_save_guest_reply')) {
    function fridg3_feed_save_guest_reply(string $postId, string $displayName, string $ip, string $body, string $parentId = '', string $guestBrowserId = ''): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        $safeIp = trim($ip);
        $trimmedBody = trim($body);
        $name = trim((string)preg_replace('/\s+/', ' ', strip_tags($displayName)));
        if ($name === '') {
            $name = 'Anonymous';
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 50) : substr($name, 0, 50);
        if (fridg3_feed_registered_username_exists($name)) {
            return false;
        }
        $name = fridg3_feed_apply_guest_filter($name);
        $trimmedBody = fridg3_feed_apply_guest_filter($trimmedBody, true);

        if ($safePostId === '' || !filter_var($safeIp, FILTER_VALIDATE_IP) || $trimmedBody === '') {
            return false;
        }

        $existingReplies = fridg3_feed_load_replies($safePostId);
        $newReply = [
            'id' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'username' => $name,
            'date' => date('Y-m-d H:i:s'),
            'body' => $trimmedBody,
            'isGuest' => true,
            'ip' => $safeIp,
        ];
        if ($parentId !== '' && fridg3_feed_reply_exists($existingReplies, $parentId)) {
            $newReply['parentId'] = $parentId;
        }
        $safeGuestBrowserId = fridg3_feed_normalize_guest_browser_id($guestBrowserId);
        if ($safeGuestBrowserId !== '') {
            $newReply['guestBrowserId'] = $safeGuestBrowserId;
        }
        $existingReplies[] = $newReply;

        return fridg3_feed_write_replies($safePostId, $existingReplies);
    }
}

if (!function_exists('fridg3_feed_update_reply')) {
    function fridg3_feed_update_reply(string $postId, string $replyId, string $body): bool
    {
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            return false;
        }

        $replies = fridg3_feed_load_replies($postId);
        foreach ($replies as $index => $reply) {
            if (($reply['id'] ?? '') !== $replyId) {
                continue;
            }
            $replies[$index]['body'] = $trimmedBody;
            return fridg3_feed_write_replies($postId, $replies);
        }

        return false;
    }
}

if (!function_exists('fridg3_feed_delete_reply')) {
    function fridg3_feed_delete_reply(string $postId, string $replyId): bool
    {
        $replies = fridg3_feed_load_replies($postId);
        $updatedReplies = [];
        $deleted = false;

        foreach ($replies as $reply) {
            if (($reply['id'] ?? '') === $replyId) {
                fridg3_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
                $deleted = true;
                continue;
            }
            $updatedReplies[] = $reply;
        }

        if (!$deleted) {
            return false;
        }

        return fridg3_feed_write_replies($postId, $updatedReplies);
    }
}

if (!function_exists('fridg3_feed_probe_audio_duration')) {
    function fridg3_feed_probe_audio_duration(string $path): ?float
    {
        if (!is_file($path) || !function_exists('shell_exec')) {
            return null;
        }

        $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>/dev/null';
        $output = @shell_exec($cmd);
        if (!is_string($output)) {
            return null;
        }

        $duration = (float)trim($output);
        return $duration > 0 ? $duration : null;
    }
}

if (!function_exists('fridg3_feed_transcode_voice_note')) {
    function fridg3_feed_transcode_voice_note(string $srcPath, string $destPath): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        $tmpPath = $destPath . '.tmp.m4a';
        @unlink($tmpPath);
        $cmd = 'ffmpeg -y -v error -i ' . escapeshellarg($srcPath)
            . ' -vn -ac 1 -ar 24000 -c:a aac -b:a 32k -movflags +faststart '
            . escapeshellarg($tmpPath) . ' 2>/dev/null';
        @shell_exec($cmd);

        if (!is_file($tmpPath) || (@filesize($tmpPath) ?: 0) <= 0) {
            @unlink($tmpPath);
            return false;
        }

        $duration = fridg3_feed_probe_audio_duration($tmpPath);
        if ($duration === null || $duration > 121.0) {
            @unlink($tmpPath);
            return false;
        }

        $moved = @rename($tmpPath, $destPath);
        if (!$moved) {
            @unlink($tmpPath);
        }

        return $moved;
    }
}

if (!function_exists('fridg3_feed_process_uploaded_voice_notes')) {
    function fridg3_feed_process_uploaded_voice_notes(array $files): array
    {
        $voiceDir = fridg3_feed_voice_dir();
        if (!is_dir($voiceDir)) {
            @mkdir($voiceDir, 0777, true);
        }

        $voiceMap = [];
        if (!isset($files['name']) || !is_array($files['name'])) {
            return $voiceMap;
        }

        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpPath = (string)($files['tmp_name'][$i] ?? '');
            $origName = (string)($files['name'][$i] ?? ('voice-note-' . $i . '.m4a'));
            $size = (int)($files['size'][$i] ?? 0);
            if ($tmpPath === '' || $size <= 0 || $size > 8 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
                continue;
            }

            $sourceDuration = fridg3_feed_probe_audio_duration($tmpPath);
            if ($sourceDuration !== null && $sourceDuration > 121.0) {
                continue;
            }

            $randomName = bin2hex(random_bytes(12));
            $destName = $randomName . '.m4a';
            $destPath = $voiceDir . DIRECTORY_SEPARATOR . $destName;
            if (!fridg3_feed_transcode_voice_note($tmpPath, $destPath)) {
                @unlink($destPath);
                // MediaRecorder output varies by browser, and some valid browser
                // containers cannot be remuxed by a particular ffmpeg build. Keep
                // a validated original recording instead of rejecting the post.
                $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
                $mime = $finfo ? (string)@finfo_file($finfo, $tmpPath) : (string)($files['type'][$i] ?? '');
                if ($finfo) {
                    finfo_close($finfo);
                }
                $fallbackTypes = [
                    'audio/webm' => 'webm',
                    'video/webm' => 'webm',
                    'audio/ogg' => 'ogg',
                    'application/ogg' => 'ogg',
                    'audio/mp4' => 'm4a',
                    'video/mp4' => 'm4a',
                    'audio/mpeg' => 'mp3',
                    'audio/wav' => 'wav',
                    'audio/x-wav' => 'wav',
                ];
                if (!isset($fallbackTypes[$mime])) {
                    continue;
                }
                $destName = $randomName . '.' . $fallbackTypes[$mime];
                $destPath = $voiceDir . DIRECTORY_SEPARATOR . $destName;
                if (!@move_uploaded_file($tmpPath, $destPath)) {
                    continue;
                }
            }

            $voiceMap[$i] = [
                'url' => '/data/audio/voice/' . $destName,
                'name' => 'voice-note.' . pathinfo($destName, PATHINFO_EXTENSION),
                'duration' => fridg3_feed_probe_audio_duration($destPath) ?? $sourceDuration ?? 0,
            ];
        }

        return $voiceMap;
    }
}

if (!function_exists('fridg3_feed_replace_voice_placeholders')) {
    function fridg3_feed_replace_voice_placeholders(string $content, array $voiceMap): string
    {
        if (empty($voiceMap)) {
            return $content;
        }

        return (string)preg_replace_callback('/\[voice:(\d+)\](?:\[name:([^\]]*)\])?/i', function($m) use ($voiceMap) {
            $idx = (int)$m[1];
            if (!isset($voiceMap[$idx])) {
                return $m[0];
            }
            $name = isset($m[2]) && strlen(trim($m[2])) ? trim($m[2]) : ($voiceMap[$idx]['name'] ?? 'voice-note.m4a');
            return '[audio=' . $voiceMap[$idx]['url'] . '][name:' . $name . ']';
        }, $content);
    }
}

if (!function_exists('fridg3_feed_save_jpeg_under_limit')) {
    function fridg3_feed_save_jpeg_under_limit(string $srcPath, string $mime, string $destPath, int $maxBytes = 1000000): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $createMap = [
            'image/png' => function($p) { return @imagecreatefrompng($p); },
            'image/jpeg' => function($p) { return @imagecreatefromjpeg($p); },
            'image/gif' => function($p) { return function_exists('imagecreatefromgif') ? @imagecreatefromgif($p) : false; },
            'image/webp' => function($p) { return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($p) : false; },
        ];

        if (!isset($createMap[$mime])) {
            return false;
        }

        $img = $createMap[$mime]($srcPath);
        if (!$img) {
            return false;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);

        $tmpPath = tempnam(sys_get_temp_dir(), 'img');
        if ($tmpPath === false) {
            imagedestroy($canvas);
            return false;
        }

        $quality = 90;
        do {
            imagejpeg($canvas, $tmpPath, $quality);
            $size = @filesize($tmpPath);
            if ($size !== false && $size <= $maxBytes) {
                break;
            }
            $quality -= 5;
        } while ($quality >= 40);

        imagedestroy($canvas);
        $finalSize = @filesize($tmpPath);
        if ($finalSize === false || $finalSize > $maxBytes) {
            @unlink($tmpPath);
            return false;
        }

        $moved = @rename($tmpPath, $destPath);
        if (!$moved) {
            @unlink($tmpPath);
        }

        return $moved;
    }
}

if (!function_exists('fridg3_feed_process_uploaded_images')) {
    function fridg3_feed_process_uploaded_images(array $files): array
    {
        $imagesDir = fridg3_feed_images_dir();
        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0777, true);
        }

        $imageMap = [];
        if (!isset($files['name']) || !is_array($files['name'])) {
            return $imageMap;
        }

        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpPath = $files['tmp_name'][$i] ?? '';
            $origName = $files['name'][$i] ?? ('image_' . $i);
            $uploadSize = (int)($files['size'][$i] ?? 0);
            if ($tmpPath === '' || $uploadSize <= 0 || $uploadSize > 8 * 1024 * 1024) {
                continue;
            }

            $imageInfo = @getimagesize($tmpPath);
            $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
            if (!isset($allowed[$mime])) {
                continue;
            }

            $ext = $allowed[$mime];
            $sizeBytes = @filesize($tmpPath) ?: 0;
            $mustJpeg = ($mime === 'image/png');
            $mustCompress = $mustJpeg || ($sizeBytes > 1000000);
            $randomBase = bin2hex(random_bytes(8));
            $destExt = $mustCompress ? 'jpg' : $ext;
            $destName = $randomBase . '.' . $destExt;
            $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;

            $saved = false;
            if ($mustCompress) {
                $saved = fridg3_feed_save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            } else {
                $saved = @move_uploaded_file($tmpPath, $destPath);
            }

            $finalSize = $saved ? (@filesize($destPath) ?: 0) : 0;
            if (!$saved || $finalSize > 1000000) {
                @unlink($destPath);
                $destName = $randomBase . '.jpg';
                $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;
                $saved = fridg3_feed_save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            }

            if ($saved) {
                $imageMap[$i] = [
                    'url' => '/data/images/' . $destName,
                    'name' => $origName ?: $destName,
                ];
            }
        }

        return $imageMap;
    }
}

if (!function_exists('fridg3_feed_replace_image_placeholders')) {
    function fridg3_feed_replace_image_placeholders(string $content, array $imageMap): string
    {
        if (empty($imageMap)) {
            return $content;
        }

        return (string)preg_replace_callback('/\[img:(\d+)\](?:\[name:([^\]]*)\])?/i', function($m) use ($imageMap) {
            $idx = (int)$m[1];
            if (!isset($imageMap[$idx])) {
                return $m[0];
            }
            $name = isset($m[2]) && strlen(trim($m[2])) ? trim($m[2]) : ($imageMap[$idx]['name'] ?? 'image');
            return '[img=' . $imageMap[$idx]['url'] . '][name:' . $name . ']';
        }, $content);
    }
}

if (!function_exists('fridg3_feed_process_uploaded_media')) {
    function fridg3_feed_process_uploaded_media(array $files): array
    {
        $mediaMap = [];
        foreach (fridg3_feed_process_uploaded_images($files) as $index => $image) {
            $mediaMap[$index] = $image + ['type' => 'image'];
        }
        if (!isset($files['name']) || !is_array($files['name'])) {
            return $mediaMap;
        }

        $allowed = [
            'audio/mpeg' => ['audio', 'mp3'],
            'audio/aac' => ['audio', 'aac'],
            'audio/x-aac' => ['audio', 'aac'],
            'audio/x-hx-aac-adts' => ['audio', 'aac'],
            'audio/vnd.dlna.adts' => ['audio', 'aac'],
            'audio/mp4' => ['audio', 'm4a'],
            'audio/x-m4a' => ['audio', 'm4a'],
            'audio/ogg' => ['audio', 'ogg'],
            'audio/wav' => ['audio', 'wav'],
            'audio/x-wav' => ['audio', 'wav'],
            'audio/flac' => ['audio', 'flac'],
            'audio/webm' => ['audio', 'webm'],
            'video/mp4' => ['video', 'mp4'],
            'video/webm' => ['video', 'webm'],
            'video/ogg' => ['video', 'ogv'],
            'video/quicktime' => ['video', 'mov'],
        ];
        $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
        foreach ($files['name'] as $index => $originalName) {
            if (isset($mediaMap[$index]) || ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpPath = (string)($files['tmp_name'][$index] ?? '');
            $size = (int)($files['size'][$index] ?? 0);
            $declaredMimeParts = explode(';', strtolower((string)($files['type'][$index] ?? '')), 2);
            $declaredMime = trim($declaredMimeParts[0]);
            $mime = $finfo && $tmpPath !== '' ? (string)@finfo_file($finfo, $tmpPath) : $declaredMime;
            // libmagic commonly labels audio-only WebM as video/webm because the
            // container is shared. Once the container itself is validated as
            // WebM, use MediaRecorder/the file input's audio kind to distinguish
            // which player and storage directory should be used.
            if ($mime === 'video/webm' && $declaredMime === 'audio/webm') {
                $mime = 'audio/webm';
            }
            if (in_array($mime, ['video/mp4', 'video/quicktime', 'application/mp4'], true)
                && in_array($declaredMime, ['audio/mp4', 'audio/x-m4a'], true)) {
                $mime = $declaredMime;
            }
            if ($mime === 'application/ogg' && $declaredMime === 'audio/ogg') {
                $mime = 'audio/ogg';
            }
            // Some upload clients omit the MIME type altogether. Only fall back
            // to the filename when libmagic has still positively identified a
            // compatible media container; never trust the extension by itself.
            $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
            if ($declaredMime === '' && in_array($mime, ['video/mp4', 'video/quicktime', 'application/mp4'], true)
                && in_array($extension, ['m4a', 'aac'], true)) {
                $mime = $extension === 'aac' ? 'audio/aac' : 'audio/mp4';
            }
            if ($declaredMime === '' && $mime === 'application/ogg' && in_array($extension, ['ogg', 'oga'], true)) {
                $mime = 'audio/ogg';
            }
            if ($tmpPath === '' || !isset($allowed[$mime]) || !is_uploaded_file($tmpPath)) {
                continue;
            }
            [$type, $extension] = $allowed[$mime];
            if ($size <= 0 || $size > 8 * 1024 * 1024) {
                continue;
            }
            $relativeDir = $type === 'video' ? 'video' : 'audio/uploads';
            $directory = fridg3_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
                continue;
            }
            $destName = bin2hex(random_bytes(12)) . '.' . $extension;
            if (!@move_uploaded_file($tmpPath, $directory . DIRECTORY_SEPARATOR . $destName)) {
                continue;
            }
            $mediaMap[$index] = [
                'type' => $type,
                'url' => '/data/' . $relativeDir . '/' . $destName,
                'name' => (string)$originalName ?: $destName,
            ];
        }
        if ($finfo) {
            finfo_close($finfo);
        }
        ksort($mediaMap);
        return $mediaMap;
    }
}

if (!function_exists('fridg3_feed_replace_media_placeholders')) {
    function fridg3_feed_replace_media_placeholders(string $content, array $mediaMap): string
    {
        return (string)preg_replace_callback('/\[(media|img|audio|video):(\d+)\](?:\[name:([^\]]*)\])?/i', static function (array $match) use ($mediaMap): string {
            $index = (int)$match[2];
            if (!isset($mediaMap[$index])) {
                return $match[0];
            }
            $media = $mediaMap[$index];
            $type = in_array(($media['type'] ?? ''), ['image', 'audio', 'video'], true) ? $media['type'] : 'image';
            $tag = $type === 'image' ? 'img' : $type;
            $placeholderType = strtolower((string)$match[1]);
            if ($placeholderType !== 'media' && $placeholderType !== $tag) {
                return $match[0];
            }
            $name = trim((string)($match[3] ?? '')) ?: (string)($media['name'] ?? $type);
            $name = str_replace([']', "\r", "\n"], '', $name);
            return '[' . $tag . '=' . $media['url'] . '][name:' . $name . ']';
        }, $content);
    }
}

if (!function_exists('fridg3_feed_render_audio_attachment')) {
    function fridg3_feed_render_audio_attachment(string $url, string $name): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $isVoiceNote = str_contains($path, '/data/audio/voice/');
        return '<div class="feed-audio-note feed-voice-note' . ($isVoiceNote ? '' : ' feed-uploaded-audio') . ' chat-attachment chat-attachment-media chat-attachment-audio">'
            . '<audio class="chat-media-element" preload="metadata" src="' . $safeUrl . '"></audio>'
            . '<div class="chat-media-player" data-media-kind="audio">'
            . '<button class="chat-media-play" type="button" aria-label="play audio"><i class="fa-solid fa-play"></i></button>'
            . '<input class="chat-media-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek audio">'
            . '<span class="chat-media-time">0:00 / 0:00</span>'
            . ($isVoiceNote ? '<button class="chat-media-speed" type="button" aria-label="playback speed"><span class="chat-media-speed-label">1x</span></button>' : '')
            . '</div></div>';
    }
}

if (!function_exists('fridg3_feed_render_video_attachment')) {
    function fridg3_feed_render_video_attachment(string $url, string $name): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name !== '' ? $name : 'video', ENT_QUOTES, 'UTF-8');
        return '<div class="feed-video-attachment">'
            . '<video class="feed-video-element" playsinline preload="metadata" src="' . $safeUrl . '" aria-label="' . $safeName . '"></video>'
            . '<div class="feed-video-controls">'
            . '<button class="feed-video-control feed-video-play" type="button" aria-label="play video"><i class="fa-solid fa-play"></i></button>'
            . '<input class="feed-video-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek video">'
            . '<span class="feed-video-time">0:00 / 0:00</span>'
            . '<button class="feed-video-control feed-video-mute" type="button" aria-label="mute video"><i class="fa-solid fa-volume-high"></i></button>'
            . '<input class="feed-video-volume" type="range" min="0" max="1" value="1" step="0.05" aria-label="video volume">'
            . '<button class="feed-video-control feed-video-fullscreen" type="button" aria-label="fullscreen video"><i class="fa-solid fa-expand"></i></button>'
            . '</div></div>';
    }
}
