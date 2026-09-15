<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'moderator-audit.php';
require_once __DIR__ . '/notification-revision.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'debug.php';

if (!function_exists('fridge_feed_find_root')) {
    function fridge_feed_find_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('fridge_feed_posts_dir')) {
    function fridge_feed_posts_dir(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
    }
}

if (!function_exists('fridge_feed_replies_dir')) {
    function fridge_feed_replies_dir(): string
    {
        return fridge_feed_posts_dir() . DIRECTORY_SEPARATOR . 'replies';
    }
}

if (!function_exists('fridge_feed_images_dir')) {
    function fridge_feed_images_dir(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'images';
    }
}

if (!function_exists('fridge_feed_voice_dir')) {
    function fridge_feed_voice_dir(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . 'voice';
    }
}

if (!function_exists('fridge_feed_banned_ips_path')) {
    function fridge_feed_banned_ips_path(): string
    {
        return fridge_feed_posts_dir() . DIRECTORY_SEPARATOR . 'banned_ips.json';
    }
}

function fridge_feed_post_ips_path(): string { return fridge_feed_posts_dir() . DIRECTORY_SEPARATOR . 'post_ips.json'; }
function fridge_feed_load_post_ips(): array {
    $decoded = is_file(fridge_feed_post_ips_path()) ? json_decode((string)@file_get_contents(fridge_feed_post_ips_path()), true) : [];
    return is_array($decoded) ? $decoded : [];
}
function fridge_feed_record_account_ip(string $username, string $ip): void {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    $path = fridge_feed_accounts_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data) || !is_array($data['accounts'] ?? null)) return;
    $accountBanned = false;
    $banReason = '';
    $banBy = 'system';
    foreach ($data['accounts'] as &$account) {
        if (!is_array($account) || strcasecmp((string)($account['username'] ?? ''), ltrim($username, '@')) !== 0) continue;
        $ips = array_values(array_filter(array_map('strval', (array)($account['ips'] ?? [])), static fn(string $known): bool => filter_var($known, FILTER_VALIDATE_IP) !== false));
        if (!in_array($ip, $ips, true)) $ips[] = $ip;
        $account['ips'] = $ips;
        $accountBanned = !empty($account['accountBanned']);
        $banReason = (string)($account['accountBanReason'] ?? '');
        $banBy = (string)($account['accountBannedBy'] ?? 'system');
        break;
    }
    unset($account);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) @file_put_contents($path, $encoded, LOCK_EX);
    if (!empty($accountBanned)) fridge_feed_ban_guest_ip($ip, $banBy, ltrim($username, '@'), $banReason);
}

function fridge_feed_set_account_banned(string $username, bool $banned, string $actor, string $reason = ''): bool {
    $path = fridge_feed_accounts_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data) || !is_array($data['accounts'] ?? null)) return false;
    $ips = [];
    $found = false;
    foreach ($data['accounts'] as &$account) {
        if (!is_array($account) || strcasecmp((string)($account['username'] ?? ''), ltrim($username, '@')) !== 0) continue;
        if (!empty($account['isAdmin']) || !empty($account['isModerator'])) return false;
        $account['accountBanned'] = $banned;
        if ($banned) { $account['accountBannedBy'] = $actor; $account['accountBanReason'] = trim($reason); }
        else { unset($account['accountBannedBy'], $account['accountBanReason']); }
        $ips = array_values(array_filter(array_map('strval', (array)($account['ips'] ?? [])), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        $found = true;
        break;
    }
    unset($account);
    if (!$found) return false;
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || @file_put_contents($path, $encoded, LOCK_EX) === false) return false;
    $ok = true;
    foreach ($ips as $ip) $ok = ($banned ? fridge_feed_ban_guest_ip($ip, $actor, $username, $reason) : fridge_feed_unban_ip($ip)) && $ok;
    return $ok;
}
function fridge_feed_record_post_ip(string $postId, string $username, string $ip): void {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    $records = fridge_feed_load_post_ips();
    $records[preg_replace('/[^a-zA-Z0-9_-]/', '', basename($postId))] = ['username' => ltrim($username, '@'), 'ip' => $ip];
    $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) @file_put_contents(fridge_feed_post_ips_path(), $encoded, LOCK_EX);
    fridge_feed_record_account_ip($username, $ip);
}

function fridge_feed_write_post_ips(array $records): bool {
    $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $encoded !== false && @file_put_contents(fridge_feed_post_ips_path(), $encoded, LOCK_EX) !== false;
}

if (!function_exists('fridge_feed_filters_dir')) {
    function fridge_feed_filters_dir(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'feed' . DIRECTORY_SEPARATOR . 'filters';
    }
}

if (!function_exists('fridge_feed_filter_terms')) {
    function fridge_feed_filter_terms(): array
    {
        static $terms = null;
        if (is_array($terms)) {
            return $terms;
        }

        $terms = [];
        $seen = [];
        $files = glob(fridge_feed_filters_dir() . DIRECTORY_SEPARATOR . '*.txt');
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

if (!function_exists('fridge_feed_star_count')) {
    function fridge_feed_star_count(string $value): int
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

if (!function_exists('fridge_feed_filter_term_pattern')) {
    function fridge_feed_filter_term_pattern(string $term): string
    {
        $escaped = preg_quote($term, '/');
        $needsStartBoundary = preg_match('/^[\p{L}\p{N}_]/u', $term) === 1;
        $needsEndBoundary = preg_match('/[\p{L}\p{N}_]$/u', $term) === 1;
        $prefix = $needsStartBoundary ? '(^|[^\p{L}\p{N}_])' : '()';
        $suffix = $needsEndBoundary ? '(?=$|[^\p{L}\p{N}_])' : '';

        return '/' . $prefix . '(' . $escaped . ')' . $suffix . '/iu';
    }
}

if (!function_exists('fridge_feed_filter_tooltip_text')) {
    function fridge_feed_filter_tooltip_text(): string
    {
        return 'this phrase was automatically filtered.';
    }
}

if (!function_exists('fridge_feed_non_whitespace_count')) {
    function fridge_feed_non_whitespace_count(string $value): int
    {
        if (preg_match_all('/\S/u', $value, $matches) !== false) {
            return count($matches[0]);
        }
        return strlen(preg_replace('/\s+/', '', $value) ?? '');
    }
}

if (!function_exists('fridge_feed_filter_visible_text')) {
    function fridge_feed_filter_visible_text(string $text): string
    {
        $withoutTags = preg_replace('/\[[^\]]+\]/', ' ', $text);
        return is_string($withoutTags) ? $withoutTags : $text;
    }
}

if (!function_exists('fridge_feed_filter_stats')) {
    function fridge_feed_filter_stats(string $text): array
    {
        $terms = fridge_feed_filter_terms();
        $scanText = fridge_feed_filter_visible_text($text);
        $stats = [
            'totalChars' => fridge_feed_non_whitespace_count($scanText),
            'matchedChars' => 0,
            'matchedTerms' => 0,
        ];

        if ($scanText === '' || empty($terms)) {
            return $stats;
        }

        foreach ($terms as $term) {
            $next = preg_replace_callback(fridge_feed_filter_term_pattern($term), static function (array $match) use (&$stats): string {
                $prefix = (string)($match[1] ?? '');
                $matchedTerm = (string)($match[2] ?? '');
                $stats['matchedChars'] += fridge_feed_non_whitespace_count($matchedTerm);
                $stats['matchedTerms']++;
                return $prefix . str_repeat('★', fridge_feed_star_count($matchedTerm));
            }, $scanText);
            if (is_string($next)) {
                $scanText = $next;
            }
        }

        return $stats;
    }
}

if (!function_exists('fridge_feed_guest_filter_is_mostly_filtered')) {
    function fridge_feed_guest_filter_is_mostly_filtered(string $text): bool
    {
        $stats = fridge_feed_filter_stats($text);
        if ($stats['totalChars'] <= 0 || $stats['matchedTerms'] <= 0) {
            return false;
        }

        return ($stats['matchedChars'] / $stats['totalChars']) >= 0.5;
    }
}

if (!function_exists('fridge_feed_guest_reply_has_filtered_text')) {
    function fridge_feed_guest_reply_has_filtered_text(array $reply): bool
    {
        $body = (string)($reply['body'] ?? '');
        if ($body === '') {
            return false;
        }
        if (strpos($body, fridge_feed_filter_tooltip_text()) !== false) {
            return true;
        }

        return fridge_feed_apply_guest_filter($body, true) !== $body;
    }
}

if (!function_exists('fridge_feed_apply_guest_filter')) {
    function fridge_feed_apply_guest_filter(string $text, bool $withTooltip = false, bool $revealOriginal = false): string
    {
        $terms = fridge_feed_filter_terms();
        if ($text === '' || empty($terms)) {
            return $text;
        }

        $filtered = $text;
        foreach ($terms as $term) {
            $next = preg_replace_callback(fridge_feed_filter_term_pattern($term), static function (array $match) use ($withTooltip, $revealOriginal): string {
                $prefix = (string)($match[1] ?? '');
                $matchedTerm = (string)($match[2] ?? '');
                $stars = str_repeat('★', fridge_feed_star_count($matchedTerm));
                if ($withTooltip) {
                    $context = $revealOriginal ? '[filter-original=' . base64_encode($matchedTerm) . ']' : '';
                    $contextEnd = $revealOriginal ? '[/filter-original]' : '';
                    return $prefix . '[tooltip="' . fridge_feed_filter_tooltip_text() . '"]' . $context . $stars . $contextEnd . '[/tooltip]';
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

if (!function_exists('fridge_feed_client_ip')) {
    function fridge_feed_client_ip(): string
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

if (!function_exists('fridge_feed_humanize_datetime')) {
    function fridge_feed_humanize_datetime(string $dtStr): string
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

if (!function_exists('fridge_feed_accounts_path')) {
    function fridge_feed_accounts_path(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . 'accounts.json';
    }
}

if (!function_exists('fridge_feed_load_accounts')) {
    function fridge_feed_load_accounts(): array
    {
        $accountsPath = fridge_feed_accounts_path();
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

if (!function_exists('fridge_feed_registered_username_exists')) {
    function fridge_feed_registered_username_exists(string $username): bool
    {
        $target = strtolower(ltrim(trim($username), '@'));
        if ($target === '') {
            return false;
        }

        foreach (fridge_feed_load_accounts()['accounts'] as $account) {
            $accountUsername = strtolower(trim((string)($account['username'] ?? '')));
            if ($accountUsername !== '' && $accountUsername === $target) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fridge_feed_refresh_session_user')) {
    function fridge_feed_refresh_session_user(): void
    {
        if (!isset($_SESSION['user']['username'])) {
            return;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        $accountsData = fridge_feed_load_accounts();
        foreach ($accountsData['accounts'] as $account) {
            if (!isset($account['username']) || (string)$account['username'] !== $currentUsername) {
                continue;
            }

            $_SESSION['user']['name'] = htmlspecialchars((string)($account['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $_SESSION['user']['isAdmin'] = (bool)($account['isAdmin'] ?? false);
            $_SESSION['user']['isModerator'] = (bool)($account['isModerator'] ?? false);
            $_SESSION['user']['postingRestricted'] = !empty($account['postingRestricted']) || !empty($account['accountBanned']);
            $_SESSION['user']['allowedPages'] = array_map(static function ($page) {
                return htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8');
            }, (array)($account['allowedPages'] ?? []));
            break;
        }
    }
}

if (!function_exists('fridge_feed_account_is_admin')) {
    function fridge_feed_account_is_admin(string $username): bool
    {
        $target = strtolower(ltrim(trim($username), '@'));
        if ($target === '') return false;
        foreach (fridge_feed_load_accounts()['accounts'] as $account) {
            if (strtolower((string)($account['username'] ?? '')) === $target) return !empty($account['isAdmin']);
        }
        return false;
    }
}

if (!function_exists('fridge_feed_current_user_is_moderator')) {
    function fridge_feed_current_user_is_moderator(): bool
    {
        return !empty($_SESSION['user']['isAdmin']) || !empty($_SESSION['user']['isModerator']);
    }
}

if (!function_exists('fridge_feed_current_user_can_moderate_author')) {
    function fridge_feed_current_user_can_moderate_author(string $username): bool
    {
        if (!empty($_SESSION['user']['isAdmin'])) return true;
        return !empty($_SESSION['user']['isModerator']) && !fridge_feed_account_is_admin($username);
    }
}

if (!function_exists('fridge_feed_current_user_can_moderate_replies')) {
    function fridge_feed_current_user_can_moderate_replies(string $postOwnerUsername): bool
    {
        if (!isset($_SESSION['user']['username'])) {
            return false;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        $isAdmin = !empty($_SESSION['user']['isAdmin']);
        $allowedPages = array_map('strval', (array)($_SESSION['user']['allowedPages'] ?? []));
        return $isAdmin
            || !empty($_SESSION['user']['isModerator'])
            || $currentUsername === ltrim($postOwnerUsername, '@')
            || in_array('comments', $allowedPages, true);
    }
}

if (!function_exists('fridge_feed_current_user_can_manage_reply')) {
    function fridge_feed_current_user_can_manage_reply(string $postOwnerUsername, string $replyUsername): bool
    {
        if (!isset($_SESSION['user']['username'])) {
            return false;
        }

        $currentUsername = (string)$_SESSION['user']['username'];
        $allowedPages = array_map('strval', (array)($_SESSION['user']['allowedPages'] ?? []));
        if ($currentUsername === ltrim($replyUsername, '@') || !empty($_SESSION['user']['isAdmin'])) return true;
        if (!empty($_SESSION['user']['isModerator'])) return !fridge_feed_account_is_admin($replyUsername);
        return $currentUsername === ltrim($postOwnerUsername, '@') || in_array('comments', $allowedPages, true);
    }
}

if (!function_exists('fridge_feed_current_visitor_can_manage_reply')) {
    function fridge_feed_current_visitor_can_manage_reply(string $postOwnerUsername, array $reply, string $clientIp): bool
    {
        if (isset($_SESSION['user']['username'])) {
            return fridge_feed_current_user_can_manage_reply($postOwnerUsername, (string)($reply['username'] ?? ''));
        }

        return ($reply['isGuest'] ?? false) === true
            && $clientIp !== ''
            && (string)($reply['ip'] ?? '') === $clientIp;
    }
}

if (!function_exists('fridge_feed_reply_fallback_id')) {
    function fridge_feed_reply_fallback_id(array $reply, int $index): string
    {
        $seed = ($reply['username'] ?? '') . '|' . ($reply['date'] ?? '') . '|' . ($reply['body'] ?? '') . '|' . $index;
        return 'legacy_' . substr(sha1($seed), 0, 16);
    }
}

if (!function_exists('fridge_feed_reply_format')) {
    function fridge_feed_reply_format(array $reply): string
    {
        if (($reply['format'] ?? '') === 'v2') return 'v2';
        // Reply ids begin with their creation timestamp. This fallback covers a
        // cached/older writer that accepted a Markdown-editor submission before
        // it began persisting the explicit format field.
        $id = (string)($reply['id'] ?? '');
        if (preg_match('/^(\d{14})_/', $id, $match) === 1 && strcmp($match[1], '20260809000000') >= 0) {
            return 'v2';
        }
        $body = (string)($reply['body'] ?? '');
        if (preg_match('/\[(?:b|i|u|s|h[3-5]|spoiler|color(?::[^\]]+)?|code(?:=[^\]]+)?|list|tooltip(?:=[^\]]+)?|link=[^\]]+|img=[^\]]+|audio=[^\]]+|video=[^\]]+)\]/i', $body) === 1) {
            return 'legacy';
        }
        // Plain legacy replies render identically through the restricted
        // Markdown renderer, while this also catches Markdown submissions from
        // writers that omitted both the format field and timestamped id.
        return 'v2';
    }
}

if (!function_exists('fridge_feed_write_replies')) {
    function fridge_feed_write_replies(string $postId, array $replies): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return false;
        }

        $repliesDir = fridge_feed_replies_dir();
        if (!is_dir($repliesDir) && !@mkdir($repliesDir, 0777, true) && !is_dir($repliesDir)) {
            return false;
        }

        $payload = json_encode(['replies' => array_values($replies)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $replyFile = $repliesDir . DIRECTORY_SEPARATOR . $safePostId . '.json';
        $saved = @file_put_contents($replyFile, $payload, LOCK_EX) !== false;
        if ($saved) fridge_notification_revision_touch();
        return $saved;
    }
}

if (!function_exists('fridge_feed_load_banned_ips')) {
    function fridge_feed_load_banned_ips(): array
    {
        $path = fridge_feed_banned_ips_path();
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

if (!function_exists('fridge_feed_write_banned_ips')) {
    function fridge_feed_write_banned_ips(array $bannedIps): bool
    {
        $path = fridge_feed_banned_ips_path();
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

if (!function_exists('fridge_feed_ban_archive_path')) {
    function fridge_feed_ban_archive_path(): string
    {
        return fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'banned-ip-content.json';
    }
}

if (!function_exists('fridge_feed_load_ban_archive')) {
    function fridge_feed_load_ban_archive(): array
    {
        $path = fridge_feed_ban_archive_path();
        $decoded = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('fridge_feed_archive_ip_content')) {
    function fridge_feed_archive_ip_content(string $ip, string $type, string $id, array $content): bool
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        $archive = fridge_feed_load_ban_archive();
        $key = hash('sha256', $type . "\0" . $id);
        $archive[$ip] = is_array($archive[$ip] ?? null) ? $archive[$ip] : [];
        $archive[$ip][$key] = array_merge($content, [
            'type' => $type,
            'id' => $id,
            'ip' => $ip,
            'deletedAt' => date('Y-m-d H:i:s'),
        ]);
        $path = fridge_feed_ban_archive_path();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) return false;
        $encoded = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $encoded !== false && @file_put_contents($path, $encoded, LOCK_EX) !== false;
    }
}

if (!function_exists('fridge_feed_banned_ip_record')) {
    function fridge_feed_banned_ip_record(string $ip): ?array
    {
        $targetIp = trim($ip);
        foreach (fridge_feed_load_banned_ips() as $key => $entry) {
            $entryIp = is_string($key) && filter_var($key, FILTER_VALIDATE_IP)
                ? $key
                : (is_array($entry) ? (string)($entry['ip'] ?? '') : (is_string($entry) ? $entry : ''));
            if ($entryIp !== $targetIp) continue;
            return is_array($entry) ? array_merge($entry, ['ip' => $targetIp]) : ['ip' => $targetIp];
        }
        return null;
    }
}

if (!function_exists('fridge_feed_unban_ip')) {
    function fridge_feed_unban_ip(string $ip): bool
    {
        $targetIp = trim($ip);
        if (fridge_feed_ip_belongs_to_banned_account($targetIp)) {
            return false;
        }
        $bannedIps = fridge_feed_load_banned_ips();
        $updated = [];
        $found = false;
        $wasList = array_keys($bannedIps) === range(0, count($bannedIps) - 1);
        foreach ($bannedIps as $key => $entry) {
            $entryIp = is_string($key) && filter_var($key, FILTER_VALIDATE_IP)
                ? $key
                : (is_array($entry) ? (string)($entry['ip'] ?? '') : (is_string($entry) ? $entry : ''));
            if ($entryIp === $targetIp) {
                $found = true;
                continue;
            }
            $updated[$key] = $entry;
        }
        return !$found || fridge_feed_write_banned_ips($wasList ? array_values($updated) : $updated);
    }
}

if (!function_exists('fridge_feed_ip_belongs_to_banned_account')) {
    function fridge_feed_ip_belongs_to_banned_account(string $ip): bool
    {
        $targetIp = trim($ip);
        if (!filter_var($targetIp, FILTER_VALIDATE_IP)) return false;
        foreach ((array)(fridge_feed_load_accounts()['accounts'] ?? []) as $account) {
            if (!is_array($account) || empty($account['accountBanned'])) continue;
            if (in_array($targetIp, array_map('strval', (array)($account['ips'] ?? [])), true)) return true;
        }
        return false;
    }
}

if (!function_exists('fridge_feed_is_ip_banned')) {
    function fridge_feed_is_ip_banned(string $ip): bool
    {
        $targetIp = trim($ip);
        if ($targetIp === '') {
            return false;
        }

        foreach (fridge_feed_load_banned_ips() as $key => $entry) {
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
        return fridge_feed_ip_belongs_to_banned_account($targetIp);
    }
}

if (!function_exists('fridge_current_user_bypasses_ip_restrictions')) {
    function fridge_current_user_bypasses_ip_restrictions(): bool
    {
        return !empty($_SESSION['user']['isAdmin']) || !empty($_SESSION['user']['isModerator']);
    }
}

if (!function_exists('fridge_feed_is_current_client_ip_banned')) {
    function fridge_feed_is_current_client_ip_banned(?string $ip = null): bool
    {
        if (fridge_current_user_bypasses_ip_restrictions()) return false;
        return fridge_feed_is_ip_banned($ip ?? fridge_feed_client_ip());
    }
}

if (!function_exists('fridge_feed_ban_guest_ip')) {
    function fridge_feed_ban_guest_ip(string $ip, string $adminUsername, string $guestUsername, string $reason = ''): bool
    {
        $targetIp = trim($ip);
        if (!filter_var($targetIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        $bannedIps = fridge_feed_load_banned_ips();
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

        $reason = trim($reason);
        if (function_exists('mb_substr')) $reason = mb_substr($reason, 0, 500);
        else $reason = substr($reason, 0, 500);
        $bannedIps[$targetIp] = array_merge($existing, [
            'ip' => $targetIp,
            'bannedAt' => date('Y-m-d H:i:s'),
            'bannedBy' => $adminUsername,
            'reason' => $reason,
            'notificationId' => bin2hex(random_bytes(16)),
            'usernames' => array_keys($usernames),
        ]);

        return fridge_feed_write_banned_ips($bannedIps);
    }
}

if (!function_exists('fridge_feed_verify_current_admin_password')) {
    function fridge_feed_verify_current_admin_password(string $password): bool
    {
        $currentUsername = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
        if ($currentUsername === '' || (empty($_SESSION['user']['isAdmin']) && empty($_SESSION['user']['isModerator']))) {
            return false;
        }

        $accountsData = fridge_feed_load_accounts();
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

if (!function_exists('fridge_feed_extract_voice_files')) {
    function fridge_feed_extract_voice_files(string $content): array
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

if (!function_exists('fridge_feed_delete_voice_files_from_content')) {
    function fridge_feed_delete_voice_files_from_content(string $content): void
    {
        $voiceDir = fridge_feed_voice_dir();
        foreach (fridge_feed_extract_voice_files($content) as $filename) {
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
                $directory = fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                    . ($type === 'video'
                        ? 'video'
                        : 'audio' . DIRECTORY_SEPARATOR . (str_contains($relativePrefix, '/attachments/') ? 'attachments' : 'uploads'));
                $path = $directory . DIRECTORY_SEPARATOR . basename($urlPath);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        if (preg_match_all('/<(audio|video)\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $htmlMatches, PREG_SET_ORDER)) {
            foreach ($htmlMatches as $match) {
                $type = strtolower((string)$match[1]);
                $urlPath = (string)(parse_url(html_entity_decode((string)$match[2], ENT_QUOTES, 'UTF-8'), PHP_URL_PATH) ?? '');
                $prefix = $type === 'video' ? '/data/video/' : '/data/audio/';
                if (!str_starts_with($urlPath, $prefix)) continue;
                $relative = ltrim(substr($urlPath, strlen('/data/')), '/');
                $path = fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($path)) @unlink($path);
            }
        }
    }
}

if (!function_exists('fridge_feed_delete_media_files_from_content')) {
    /**
     * Remove any successfully stored media referenced by a submission that is
     * being rejected because one or more attachment placeholders were unresolved.
     */
    function fridge_feed_delete_media_files_from_content(string $content): void
    {
        fridge_feed_delete_voice_files_from_content($content);
    }
}

if (!function_exists('fridge_feed_delete_post_voice_files')) {
    function fridge_feed_delete_post_voice_files(string $postId, string $postBody): void
    {
        fridge_feed_delete_voice_files_from_content($postBody);

        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return;
        }

        foreach (fridge_feed_load_replies($safePostId) as $reply) {
            fridge_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
        }
    }
}

if (!function_exists('fridge_feed_load_replies')) {
    function fridge_feed_load_replies(string $postId): array
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        if ($safePostId === '') {
            return [];
        }

        $replyFile = fridge_feed_replies_dir() . DIRECTORY_SEPARATOR . $safePostId . '.json';
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
                : fridge_feed_reply_fallback_id($reply, $index);
            $normalizedReply['username'] = $username;
            $normalizedReply['date'] = $date;
            $normalizedReply['body'] = $body;
            if (fridge_feed_reply_format($reply) === 'v2') $normalizedReply['format'] = 'v2';
            else unset($normalizedReply['format']);
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

if (!function_exists('fridge_feed_reply_exists')) {
    function fridge_feed_reply_exists(array $replies, string $replyId): bool
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

if (!function_exists('fridge_feed_normalize_guest_browser_id')) {
    function fridge_feed_normalize_guest_browser_id(string $browserId): string
    {
        $browserId = strtolower(trim($browserId));
        return preg_match('/^[a-f0-9]{32}$/', $browserId) === 1 ? $browserId : '';
    }
}

if (!function_exists('fridge_feed_collect_guest_usernames_by_ip')) {
    function fridge_feed_collect_guest_usernames_by_ip(): array
    {
        $repliesDir = fridge_feed_replies_dir();
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
            foreach (fridge_feed_load_replies($postId) as $reply) {
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

if (!function_exists('fridge_feed_collect_guest_replies_by_ip')) {
    function fridge_feed_collect_guest_replies_by_ip(): array
    {
        $repliesDir = fridge_feed_replies_dir();
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
            foreach (fridge_feed_load_replies($postId) as $reply) {
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
                    'format' => fridge_feed_reply_format($reply),
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

if (!function_exists('fridge_feed_purge_guest_replies_by_ip')) {
    function fridge_feed_purge_guest_replies_by_ip(string $ip): array
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

        $repliesDir = fridge_feed_replies_dir();
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
            $replies = fridge_feed_load_replies($postId);
            $updatedReplies = [];
            $removedReplies = [];
            $removedFromFile = 0;

            foreach ($replies as $reply) {
                $isTargetGuestReply = ($reply['isGuest'] ?? false) === true
                    && (string)($reply['ip'] ?? '') === $targetIp;

                if ($isTargetGuestReply) {
                    fridge_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
                    $removedReplies[] = $reply;
                    $removedFromFile++;
                    continue;
                }

                $updatedReplies[] = $reply;
            }

            if ($removedFromFile === 0) {
                continue;
            }

            if (fridge_feed_write_replies($postId, $updatedReplies)) {
                foreach ($removedReplies as $removedReply) {
                    fridge_feed_archive_ip_content($targetIp, 'feed_reply', $postId . ':' . (string)($removedReply['id'] ?? ''), array_merge($removedReply, ['postId' => $postId]));
                }
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

if (!function_exists('fridge_feed_purge_all_content_by_ip')) {
    function fridge_feed_purge_all_content_by_ip(string $ip): array
    {
        $targetIp = trim($ip);
        if (!filter_var($targetIp, FILTER_VALIDATE_IP)) return ['deleted' => 0, 'failed' => 0];

        $deleted = 0;
        $failed = 0;
        foreach (glob(fridge_feed_replies_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $replyFile) {
            $postId = pathinfo(basename($replyFile), PATHINFO_FILENAME);
            foreach (fridge_feed_load_replies($postId) as $reply) {
                if ((string)($reply['ip'] ?? '') !== $targetIp) continue;
                if (fridge_feed_delete_reply($postId, (string)($reply['id'] ?? ''))) $deleted++;
                else $failed++;
            }
        }

        $postIps = fridge_feed_load_post_ips();
        foreach ($postIps as $postId => $record) {
            if (!is_array($record) || (string)($record['ip'] ?? '') !== $targetIp) continue;
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', basename((string)$postId));
            $matches = glob(fridge_feed_posts_dir() . DIRECTORY_SEPARATOR . $safeId . '.*') ?: [];
            $postPath = null;
            foreach ($matches as $candidate) {
                if (is_file($candidate) && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'json') { $postPath = $candidate; break; }
            }
            if ($postPath === null) { unset($postIps[$postId]); continue; }
            $parsed = fridge_feed_parse_post((string)@file_get_contents($postPath));
            fridge_feed_delete_post_voice_files($safeId, (string)($parsed['body'] ?? ''));
            if (@unlink($postPath)) {
                fridge_feed_archive_ip_content($targetIp, 'feed_post', $safeId, array_merge($parsed, ['postId' => $safeId]));
                @unlink(fridge_feed_replies_dir() . DIRECTORY_SEPARATOR . $safeId . '.json');
                unset($postIps[$postId]);
                $deleted++;
            } else $failed++;
        }
        if (!fridge_feed_write_post_ips($postIps)) $failed++;
        fridge_notification_revision_touch();
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}

if (!function_exists('fridge_feed_save_reply')) {
    function fridge_feed_save_reply(string $postId, string $username, string $body, string $parentId = '', string $format = 'legacy', ?string &$savedReplyId = null): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', ltrim($username, '@'));
        $trimmedBody = trim($body);

        if ($safePostId === '' || $safeUsername === '' || $trimmedBody === '') {
            return false;
        }

        $repliesDir = fridge_feed_replies_dir();
        if (!is_dir($repliesDir) && !@mkdir($repliesDir, 0777, true) && !is_dir($repliesDir)) {
            return false;
        }

        $replyFile = $repliesDir . DIRECTORY_SEPARATOR . $safePostId . '.json';
        $existingReplies = fridge_feed_load_replies($safePostId);
        $newReply = [
            'id' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'username' => $safeUsername,
            'date' => date('Y-m-d H:i:s'),
            'body' => $trimmedBody,
            'ip' => fridge_feed_client_ip(),
        ];
        fridge_feed_record_account_ip($safeUsername, (string)$newReply['ip']);
        if ($format === 'v2') $newReply['format'] = 'v2';
        if ($parentId !== '' && fridge_feed_reply_exists($existingReplies, $parentId)) {
            $newReply['parentId'] = $parentId;
        }
        $existingReplies[] = $newReply;

        $payload = json_encode(['replies' => $existingReplies], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $saved = @file_put_contents($replyFile, $payload, LOCK_EX) !== false;
        if ($saved) {
            $savedReplyId = (string)$newReply['id'];
            fridge_notification_revision_touch();
        }
        return $saved;
    }
}

if (!function_exists('fridge_feed_save_guest_reply')) {
    function fridge_feed_save_guest_reply(string $postId, string $displayName, string $ip, string $body, string $parentId = '', string $guestBrowserId = '', string $format = 'legacy', string $originalBody = '', ?string &$savedReplyId = null): bool
    {
        $safePostId = preg_replace('/[^a-zA-Z0-9_\-]/', '', basename($postId));
        $safeIp = trim($ip);
        $trimmedBody = trim($body);
        $name = trim((string)preg_replace('/\s+/', ' ', strip_tags($displayName)));
        if ($name === '') {
            $name = 'Anonymous';
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 50) : substr($name, 0, 50);
        if (fridge_feed_registered_username_exists($name)) {
            return false;
        }
        $name = fridge_feed_apply_guest_filter($name);
        $trimmedBody = fridge_feed_apply_guest_filter($trimmedBody, true);

        if ($safePostId === '' || !filter_var($safeIp, FILTER_VALIDATE_IP) || $trimmedBody === '') {
            return false;
        }

        $existingReplies = fridge_feed_load_replies($safePostId);
        $newReply = [
            'id' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'username' => $name,
            'date' => date('Y-m-d H:i:s'),
            'body' => $trimmedBody,
            'isGuest' => true,
            'ip' => $safeIp,
        ];
        $cleanOriginalBody = trim($originalBody);
        if ($cleanOriginalBody !== '' && $cleanOriginalBody !== $trimmedBody) {
            $newReply['originalBody'] = $cleanOriginalBody;
        }
        if ($format === 'v2') $newReply['format'] = 'v2';
        if ($parentId !== '' && fridge_feed_reply_exists($existingReplies, $parentId)) {
            $newReply['parentId'] = $parentId;
        }
        $safeGuestBrowserId = fridge_feed_normalize_guest_browser_id($guestBrowserId);
        if ($safeGuestBrowserId !== '') {
            $newReply['guestBrowserId'] = $safeGuestBrowserId;
        }
        $existingReplies[] = $newReply;

        $saved = fridge_feed_write_replies($safePostId, $existingReplies);
        if ($saved) $savedReplyId = (string)$newReply['id'];
        return $saved;
    }
}

if (!function_exists('fridge_feed_update_reply')) {
    function fridge_feed_update_reply(string $postId, string $replyId, string $body, ?string $format = null): bool
    {
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            return false;
        }

        $replies = fridge_feed_load_replies($postId);
        foreach ($replies as $index => $reply) {
            if (($reply['id'] ?? '') !== $replyId) {
                continue;
            }
            $replies[$index]['body'] = $trimmedBody;
            if ($format === 'v2') $replies[$index]['format'] = 'v2';
            elseif ($format === 'legacy') unset($replies[$index]['format']);
            return fridge_feed_write_replies($postId, $replies);
        }

        return false;
    }
}

if (!function_exists('fridge_feed_delete_reply')) {
    function fridge_feed_delete_reply(string $postId, string $replyId): bool
    {
        $replies = fridge_feed_load_replies($postId);
        $updatedReplies = [];
        $deleted = false;
        $deletedReply = null;

        foreach ($replies as $reply) {
            if (($reply['id'] ?? '') === $replyId) {
                fridge_feed_delete_voice_files_from_content((string)($reply['body'] ?? ''));
                $deleted = true;
                $deletedReply = $reply;
                continue;
            }
            $updatedReplies[] = $reply;
        }

        if (!$deleted) {
            return false;
        }

        $saved = fridge_feed_write_replies($postId, $updatedReplies);
        if ($saved && is_array($deletedReply)) {
            fridge_feed_archive_ip_content((string)($deletedReply['ip'] ?? ''), 'feed_reply', $postId . ':' . $replyId, array_merge($deletedReply, ['postId' => $postId]));
        }
        return $saved;
    }
}

if (!function_exists('fridge_feed_probe_audio_duration')) {
    function fridge_feed_probe_audio_duration(string $path): ?float
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

if (!function_exists('fridge_feed_transcode_voice_note')) {
    function fridge_feed_transcode_voice_note(string $srcPath, string $destPath): bool
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

        $duration = fridge_feed_probe_audio_duration($tmpPath);
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

if (!function_exists('fridge_feed_process_uploaded_voice_notes')) {
    function fridge_feed_process_uploaded_voice_notes(array $files): array
    {
        $voiceDir = fridge_feed_voice_dir();
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

            $sourceDuration = fridge_feed_probe_audio_duration($tmpPath);
            if ($sourceDuration !== null && $sourceDuration > 121.0) {
                continue;
            }

            $randomName = bin2hex(random_bytes(12));
            $destName = $randomName . '.m4a';
            $destPath = $voiceDir . DIRECTORY_SEPARATOR . $destName;
            if (!fridge_feed_transcode_voice_note($tmpPath, $destPath)) {
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
                'duration' => fridge_feed_probe_audio_duration($destPath) ?? $sourceDuration ?? 0,
            ];
            fridge_debug_submission_log('[UPLOAD] feed/journal voice attachment saved bytes=' . (@filesize($destPath) ?: 0) . ' type=' . pathinfo($destName, PATHINFO_EXTENSION));
        }

        $received = count(array_filter((array)($files['error'] ?? []), static fn($error) => (int)$error === UPLOAD_ERR_OK));
        if ($received > 0) fridge_debug_submission_log('[UPLOAD] feed/journal voice attachments processed received=' . $received . ' saved=' . count($voiceMap) . ' rejected=' . max(0, $received - count($voiceMap)));

        return $voiceMap;
    }
}

if (!function_exists('fridge_feed_replace_voice_placeholders')) {
    function fridge_feed_replace_voice_placeholders(string $content, array $voiceMap, bool $markdown = false): string
    {
        if (empty($voiceMap)) {
            return $content;
        }

        return (string)preg_replace_callback('/\[voice:(\d+)\](?:\[name:([^\]]*)\])?/i', function($m) use ($voiceMap, $markdown) {
            $idx = (int)$m[1];
            if (!isset($voiceMap[$idx])) {
                return $m[0];
            }
            $name = isset($m[2]) && strlen(trim($m[2])) ? trim($m[2]) : ($voiceMap[$idx]['name'] ?? 'voice-note.m4a');
            if ($markdown) {
                return '<audio src="' . htmlspecialchars((string)$voiceMap[$idx]['url'], ENT_QUOTES, 'UTF-8') . '"></audio>';
            }
            return '[audio=' . $voiceMap[$idx]['url'] . '][name:' . $name . ']';
        }, $content);
    }
}

if (!function_exists('fridge_feed_save_jpeg_under_limit')) {
    function fridge_feed_save_jpeg_with_ffmpeg(string $srcPath, string $destPath, int $maxBytes): bool
    {
        $ffmpeg = '';
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $ffmpeg = $candidate;
                break;
            }
        }
        if ($ffmpeg === '') return false;

        $imageInfo = @getimagesize($srcPath);
        $width = is_array($imageInfo) ? (int)($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int)($imageInfo[1] ?? 0) : 0;
        if ($width < 1 || $height < 1) return false;

        $tmpPath = tempnam(dirname($destPath), '.image-');
        if ($tmpPath === false) return false;

        $filters = [
            '[1:v][0:v]overlay=shortest=1,format=yuv420p',
            '[1:v][0:v]overlay=shortest=1,scale=2400:2400:force_original_aspect_ratio=decrease,format=yuv420p',
            '[1:v][0:v]overlay=shortest=1,scale=1600:1600:force_original_aspect_ratio=decrease,format=yuv420p',
        ];
        $saved = false;
        foreach ($filters as $filter) {
            foreach ([7, 13, 19, 25, 31] as $quality) {
                $command = escapeshellarg($ffmpeg)
                    . ' -nostdin -hide_banner -loglevel error -y -i ' . escapeshellarg($srcPath)
                    . ' -f lavfi -i ' . escapeshellarg('color=c=white:s=' . $width . 'x' . $height)
                    . ' -filter_complex ' . escapeshellarg($filter)
                    . ' -frames:v 1 -c:v mjpeg -q:v ' . $quality
                    . ' -f image2 ' . escapeshellarg($tmpPath) . ' 2>/dev/null';
                $output = [];
                $status = 1;
                @exec($command, $output, $status);
                clearstatcache(true, $tmpPath);
                $size = @filesize($tmpPath);
                if ($status === 0 && $size !== false && $size > 0 && $size <= $maxBytes) {
                    $saved = true;
                    break 2;
                }
            }
        }
        if (!$saved) {
            @unlink($tmpPath);
            return false;
        }

        $moved = @rename($tmpPath, $destPath);
        if (!$moved) @unlink($tmpPath);
        return $moved;
    }

    function fridge_feed_save_jpeg_under_limit(string $srcPath, string $mime, string $destPath, int $maxBytes = 1000000): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return fridge_feed_save_jpeg_with_ffmpeg($srcPath, $destPath, $maxBytes);
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

if (!function_exists('fridge_feed_process_uploaded_images')) {
    function fridge_feed_process_uploaded_images(array $files): array
    {
        $imagesDir = fridge_feed_images_dir();
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
                if ((int)$error !== UPLOAD_ERR_NO_FILE) {
                    fridge_debug_submission_log('[UPLOAD] feed/journal image rejected reason=upload_error code=' . (int)$error);
                }
                continue;
            }

            $tmpPath = $files['tmp_name'][$i] ?? '';
            $origName = $files['name'][$i] ?? ('image_' . $i);
            $uploadSize = (int)($files['size'][$i] ?? 0);
            if ($tmpPath === '' || $uploadSize <= 0 || $uploadSize > 8 * 1024 * 1024) {
                fridge_debug_submission_log('[UPLOAD] feed/journal image rejected reason=size bytes=' . max(0, $uploadSize));
                continue;
            }

            $imageInfo = @getimagesize($tmpPath);
            $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
            if (!isset($allowed[$mime])) {
                fridge_debug_submission_log('[UPLOAD] feed/journal image rejected reason=invalid_image');
                continue;
            }

            $ext = $allowed[$mime];
            $sizeBytes = @filesize($tmpPath) ?: 0;
            $mustCompress = $sizeBytes > 1000000;
            $randomBase = bin2hex(random_bytes(8));
            $destExt = $mustCompress ? 'jpg' : $ext;
            $destName = $randomBase . '.' . $destExt;
            $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;

            $saved = false;
            if ($mustCompress) {
                $saved = fridge_feed_save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            } else {
                $saved = @move_uploaded_file($tmpPath, $destPath);
            }

            $finalSize = $saved ? (@filesize($destPath) ?: 0) : 0;
            if (!$saved || $finalSize > 1000000) {
                @unlink($destPath);
                $destName = $randomBase . '.jpg';
                $destPath = $imagesDir . DIRECTORY_SEPARATOR . $destName;
                $saved = fridge_feed_save_jpeg_under_limit($tmpPath, $mime, $destPath, 1000000);
            }

            if ($saved) {
                $imageMap[$i] = [
                    'url' => '/data/images/' . $destName,
                    'name' => $origName ?: $destName,
                ];
                fridge_debug_submission_log('[UPLOAD] feed/journal image attachment saved bytes=' . (@filesize($destPath) ?: 0) . ' type=' . $destExt);
            } else {
                fridge_debug_submission_log('[UPLOAD] feed/journal image rejected reason=compression_or_write_failed type=' . $ext . ' bytes=' . $sizeBytes);
            }
        }

        return $imageMap;
    }
}

if (!function_exists('fridge_feed_replace_image_placeholders')) {
    function fridge_feed_replace_image_placeholders(string $content, array $imageMap): string
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

if (!function_exists('fridge_feed_process_uploaded_media')) {
    function fridge_feed_process_uploaded_media(array $files): array
    {
        $mediaMap = [];
        foreach (fridge_feed_process_uploaded_images($files) as $index => $image) {
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
            $directory = fridge_feed_find_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
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
            fridge_debug_submission_log('[UPLOAD] feed/journal ' . $type . ' attachment saved bytes=' . (@filesize($directory . DIRECTORY_SEPARATOR . $destName) ?: 0) . ' type=' . $extension);
        }
        if ($finfo) {
            finfo_close($finfo);
        }
        ksort($mediaMap);
        $received = count(array_filter((array)($files['error'] ?? []), static fn($error) => (int)$error === UPLOAD_ERR_OK));
        if ($received > 0) fridge_debug_submission_log('[UPLOAD] feed/journal media attachments processed received=' . $received . ' saved=' . count($mediaMap) . ' rejected=' . max(0, $received - count($mediaMap)));
        return $mediaMap;
    }
}

if (!function_exists('fridge_feed_replace_media_placeholders')) {
    function fridge_feed_replace_media_placeholders(string $content, array $mediaMap, bool $markdown = false): string
    {
        return (string)preg_replace_callback('/\[(media|img|audio|video):(\d+)\](?:\[name:([^\]]*)\])?/i', static function (array $match) use ($mediaMap, $markdown): string {
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
            if ($markdown) {
                $url = (string)$media['url'];
                if ($type === 'image') return '![' . str_replace(['[', ']'], '', $name) . '](' . $url . ')';
                return '<' . $type . ' src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></' . $type . '>';
            }
            return '[' . $tag . '=' . $media['url'] . '][name:' . $name . ']';
        }, $content);
    }
}

if (!function_exists('fridge_feed_parse_post')) {
    function fridge_feed_parse_post(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $isV2 = trim((string)($lines[0] ?? '')) === 'v2';
        $offset = $isV2 ? 1 : 0;
        return [
            'format' => $isV2 ? 'v2' : 'legacy',
            'username' => ltrim(trim((string)($lines[$offset] ?? '')), '@'),
            'date' => trim((string)($lines[$offset + 1] ?? '')),
            'body' => implode("\n", array_slice($lines, $offset + 2)),
        ];
    }
}

if (!function_exists('fridge_feed_markdown_inline')) {
    function fridge_feed_raw_media_type(string $value): ?string
    {
        $url = mdp_safe_url(html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8'));
        if ($url === null || !preg_match('#^https?://#i', $url)) return null;
        $extension = strtolower(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) return 'image';
        if (in_array($extension, ['mp3', 'aac', 'm4a', 'ogg', 'wav', 'flac'], true)) return 'audio';
        if (in_array($extension, ['mp4', 'webm', 'ogv', 'mov'], true)) return 'video';
        return null;
    }

    function fridge_feed_markdown_inline(string $text): string
    {
        $tokens = [];
        $protect = static function (string $html) use (&$tokens): string {
            $key = '@@FEEDMD' . count($tokens) . '@@';
            $tokens[$key] = $html;
            return $key;
        };
        $text = preg_replace_callback('/(`+)(.+?)\1/', static fn(array $m): string => $protect('<code>' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</code>'), $text) ?? $text;
        $text = preg_replace_callback('/<\/?u\s*>/i', static fn(array $m): string => $protect(str_starts_with($m[0], '</') ? '</u>' : '<u>'), $text) ?? $text;
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $accountNames = [];
        foreach ((array)(fridge_feed_load_accounts()['accounts'] ?? []) as $account) {
            $username = trim((string)($account['username'] ?? ''));
            if ($username !== '') $accountNames[strtolower($username)] = $username;
        }
        $accountNames['toast'] = 'toast';
        $html = preg_replace_callback('/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_-]{1,32})\b/', static function (array $match) use ($protect, $accountNames): string {
            $key = strtolower((string)$match[1]);
            return isset($accountNames[$key]) ? $protect('<code class="feed-account-mention" data-tooltip="registered fridge.dev account">@' . htmlspecialchars($accountNames[$key], ENT_QUOTES, 'UTF-8') . '</code>') : $match[0];
        }, $html) ?? $html;
        $html = preg_replace_callback('/(?<!\S)!fa[ \t]+(solid|regular|brands)[ \t]+([a-z0-9][a-z0-9-]*)\b/i', static function (array $m) use ($protect): string {
            return $protect('<i class="fa-' . strtolower($m[1]) . ' fa-' . strtolower($m[2]) . '"></i>');
        }, $html) ?? $html;
        $html = preg_replace_callback('/(?<!\S)!frdg\b/i', static fn(): string => $protect('<img class="markdown-frdg-icon no-image-viewer" src="/resources/icons/favicon.svg" alt="fridge.dev">'), $html) ?? $html;
        $html = preg_replace_callback('/\[filter-original=([a-zA-Z0-9+\/=]+)\](.*?)\[\/filter-original\]/i', static function (array $m) use ($protect): string {
            $original = base64_decode($m[1], true);
            return $original === false ? $m[2] : $protect('<span class="filtered-word" data-context-tooltip="original: ' . htmlspecialchars($original, ENT_QUOTES, 'UTF-8') . '">' . $m[2] . '</span>');
        }, $html) ?? $html;
        $html = preg_replace_callback('/\[tooltip=&quot;([^&]*)&quot;\](.*?)\[\/tooltip\]/i', static function (array $m) use ($protect): string {
            $tooltip = htmlspecialchars(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
            return $protect('<span data-tooltip="' . $tooltip . '">' . $m[2] . '</span>');
        }, $html) ?? $html;
        $html = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m) use ($protect): string {
            $url = mdp_safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            return $url === null ? $m[0] : $protect('<img src="' . mdp_h($url) . '" alt="' . $m[1] . '">');
        }, $html) ?? $html;
        $html = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($protect): string {
            $url = mdp_safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            if ($url === null) return $m[0];
            $external = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
            return $protect('<a href="' . mdp_h($url) . '"' . $external . '>' . $m[1] . '</a>');
        }, $html) ?? $html;
        // Protect simple bold spans first so their closing delimiters cannot be
        // mistaken for the opening of a later nested-emphasis span.
        $html = preg_replace_callback('/\*\*([^*\n]+)\*\*/', static function (array $m) use ($protect): string {
            return $protect('<strong>' . $m[1] . '</strong>');
        }, $html) ?? $html;
        $html = preg_replace_callback('/\*\*([^*\n]*)\*([^*\n]+)\*([^*\n]*)\*\*/', static function (array $m) use ($protect): string {
            return $protect('<strong>' . $m[1] . '<em>' . $m[2] . '</em>' . $m[3] . '</strong>');
        }, $html) ?? $html;
        $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $html) ?? $html;
        $html = preg_replace('/==([^=]+)==/', '<mark>$1</mark>', $html) ?? $html;
        $html = preg_replace('/\|\|([^|]+)\|\|/', '<span class="spoiler">$1</span>', $html) ?? $html;
        for ($pass = 0; $pass < 4 && str_contains($html, '@@FEEDMD'); $pass++) $html = strtr($html, $tokens);
        return $html;
    }
}

if (!function_exists('fridge_feed_render_v2_markdown')) {
    function fridge_feed_markdown_split_table_row(string $row): array
    {
        $escapedPipe = "\x1FFEEDPIPE\x1F";
        $spoilers = [];
        $row = trim($row);
        if (str_starts_with($row, '|')) $row = substr($row, 1);
        if (str_ends_with($row, '|')) $row = substr($row, 0, -1);
        $row = str_replace('\\|', $escapedPipe, $row);
        $row = preg_replace_callback('/\|\|[^|\r\n]+\|\|/', static function (array $match) use (&$spoilers): string {
            $key = "\x1FFEEDSPOILER" . count($spoilers) . "\x1F";
            $spoilers[$key] = $match[0];
            return $key;
        }, $row) ?? $row;
        return array_map(static function (string $cell) use ($escapedPipe, $spoilers): string {
            return strtr(str_replace($escapedPipe, '|', trim($cell)), $spoilers);
        }, explode('|', $row));
    }

    function fridge_feed_render_v2_list(array $lines, int &$index, int $baseIndent): string
    {
        preg_match('/^(\s*)([-+*]|(\d+)\.)\s+(.+)$/', $lines[$index] ?? '', $first);
        $ordered = isset($first[3]) && $first[3] !== '';
        $tag = $ordered ? 'ol' : 'ul';
        $start = $ordered && (int)$first[3] !== 1 ? ' start="' . (int)$first[3] . '"' : '';
        $class = $ordered && $baseIndent > 0 ? ' class="mdpaste-roman-list"' : '';
        $html = '<' . $tag . $start . $class . '>';
        $count = count($lines);
        while ($index < $count && preg_match('/^(\s*)([-+*]|(\d+)\.)\s+(.+)$/', $lines[$index], $item)) {
            $indent = strlen(str_replace("\t", '    ', $item[1]));
            $itemOrdered = isset($item[3]) && $item[3] !== '';
            if ($indent !== $baseIndent || $itemOrdered !== $ordered) break;
            $html .= '<li>' . fridge_feed_markdown_inline($item[4]);
            $index++;
            while ($index < $count && preg_match('/^(\s*)([-+*]|\d+\.)\s+(.+)$/', $lines[$index], $child)) {
                $childIndent = strlen(str_replace("\t", '    ', $child[1]));
                if ($childIndent <= $baseIndent) break;
                $html .= fridge_feed_render_v2_list($lines, $index, $childIndent);
            }
            $html .= '</li>';
        }
        return $html . '</' . $tag . '>';
    }

    function fridge_feed_render_v2_markdown(string $body): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $html = [];
        $count = count($lines);
        for ($i = 0; $i < $count;) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ($trimmed === '') { $i++; continue; }
            if ($trimmed === '```') {
                $code = [];
                for ($i++; $i < $count && trim($lines[$i]) !== '```'; $i++) $code[] = $lines[$i];
                if ($i < $count) $i++;
                $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                continue;
            }
            if (preg_match('/^<(audio|video)\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*><\/\1>$/i', $trimmed, $media)) {
                $url = mdp_safe_url(html_entity_decode($media[2], ENT_QUOTES, 'UTF-8'));
                if ($url !== null) {
                    $html[] = strtolower($media[1]) === 'audio'
                        ? fridge_feed_render_audio_attachment($url, basename((string)parse_url($url, PHP_URL_PATH)))
                        : fridge_feed_render_video_attachment($url, basename((string)parse_url($url, PHP_URL_PATH)));
                    $i++;
                    continue;
                }
            }
            $rawMediaType = fridge_feed_raw_media_type($trimmed);
            if ($rawMediaType !== null) {
                $rawMediaUrl = mdp_safe_url(html_entity_decode($trimmed, ENT_QUOTES, 'UTF-8'));
                if ($rawMediaUrl !== null) {
                    $rawMediaName = basename((string)(parse_url($rawMediaUrl, PHP_URL_PATH) ?? ''));
                    $html[] = $rawMediaType === 'image'
                        ? '<img src="' . mdp_h($rawMediaUrl) . '" alt="' . mdp_h($rawMediaName !== '' ? $rawMediaName : 'linked image') . '">'
                        : ($rawMediaType === 'audio'
                            ? fridge_feed_render_audio_attachment($rawMediaUrl, $rawMediaName)
                            : fridge_feed_render_video_attachment($rawMediaUrl, $rawMediaName));
                    $i++;
                    continue;
                }
            }
            if ($i + 1 < $count && str_contains($line, '|') && preg_match('/^\s*\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)+\|?\s*$/', $lines[$i + 1])) {
                $headers = fridge_feed_markdown_split_table_row($line);
                $i += 2;
                $rows = [];
                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) $rows[] = fridge_feed_markdown_split_table_row($lines[$i++]);
                $table = '<div class="mdpaste-table-scroll"><table><thead><tr>';
                foreach ($headers as $cell) $table .= '<th>' . fridge_feed_markdown_inline($cell) . '</th>';
                $table .= '</tr></thead><tbody>';
                foreach ($rows as $row) { $table .= '<tr>'; foreach ($headers as $index => $_) $table .= '<td>' . fridge_feed_markdown_inline((string)($row[$index] ?? '')) . '</td>'; $table .= '</tr>'; }
                $html[] = $table . '</tbody></table></div>';
                continue;
            }
            if (str_starts_with($trimmed, '>')) {
                $quote = [];
                while ($i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $match)) { $quote[] = $match[1]; $i++; }
                if (isset($quote[0]) && preg_match('/^\[!QUOTE\s+(.+?)\]\s*$/i', trim($quote[0]), $attributedQuote)) {
                    array_shift($quote);
                    $body = implode('<br>', array_map('fridge_feed_markdown_inline', $quote));
                    $html[] = '<figure class="markdown-attributed-quote"><blockquote>' . $body
                        . '</blockquote><figcaption>— <cite>' . htmlspecialchars(trim($attributedQuote[1]), ENT_QUOTES, 'UTF-8') . '</cite></figcaption></figure>';
                } else {
                    $html[] = '<blockquote>' . implode('<br>', array_map('fridge_feed_markdown_inline', $quote)) . '</blockquote>';
                }
                continue;
            }
            if (preg_match('/^(\s*)([-+*]|\d+\.)\s+(.+)$/', $line, $list)) {
                $indent = strlen(str_replace("\t", '    ', $list[1]));
                $html[] = fridge_feed_render_v2_list($lines, $i, $indent);
                continue;
            }
            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '') {
                if ($paragraph !== [] && (str_starts_with(trim($lines[$i]), '>') || str_starts_with(trim($lines[$i]), '```') || preg_match('/^\s*(?:[-+*]|\d+\.)\s+/', $lines[$i]))) break;
                $paragraph[] = fridge_feed_markdown_inline($lines[$i++]);
            }
            $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
        }
        return $html === [] ? '<p>nothing here.</p>' : implode("\n", $html);
    }
}

if (!function_exists('fridge_feed_render_post_body')) {
    function fridge_feed_render_post_body(string $body, string $format): string
    {
        if ($format !== 'v2') return htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mdpaste' . DIRECTORY_SEPARATOR . 'lib.php';
        return '<div class="feed-markdown mdpaste-markdown" data-feed-format="v2">' . fridge_feed_render_v2_markdown($body) . '</div>';
    }
}

if (!function_exists('fridge_feed_render_audio_attachment')) {
    function fridge_feed_render_audio_attachment(string $url, string $name): string
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

if (!function_exists('fridge_feed_render_video_attachment')) {
    function fridge_feed_render_video_attachment(string $url, string $name): string
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
