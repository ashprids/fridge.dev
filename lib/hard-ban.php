<?php
declare(strict_types=1);

const FRIDG3_HARD_BAN_COOKIE = 'fridg3_hard_ban_id';

if (!function_exists('fridg3_hard_ban_path')) {
    function fridg3_hard_ban_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-banned-ips.txt';
    }
}

if (!function_exists('fridg3_hard_ban_parse')) {
    function fridg3_hard_ban_parse(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ips = [];
        $invalid = [];

        foreach ($tokens as $token) {
            $candidate = trim((string)$token);
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                $invalid[$candidate] = true;
                continue;
            }

            $packed = @inet_pton($candidate);
            $key = $packed === false ? strtolower($candidate) : bin2hex($packed);
            if (!isset($ips[$key])) {
                $ips[$key] = $candidate;
            }
        }

        return [
            'ips' => array_values($ips),
            'invalid' => array_keys($invalid),
        ];
    }
}

if (!function_exists('fridg3_hard_ban_load')) {
    function fridg3_hard_ban_load(): array
    {
        $path = fridg3_hard_ban_path();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        return fridg3_hard_ban_parse($raw)['ips'];
    }
}

if (!function_exists('fridg3_hard_ban_write')) {
    function fridg3_hard_ban_write(array $ips): bool
    {
        $path = fridg3_hard_ban_path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return false;
        }

        $content = $ips === [] ? '' : implode(PHP_EOL, $ips) . PHP_EOL;
        $tempPath = tempnam($directory, 'hard_bans_');
        if ($tempPath === false) {
            return @file_put_contents($path, $content, LOCK_EX) !== false;
        }

        $permissions = @fileperms($path);
        $written = @file_put_contents($tempPath, $content, LOCK_EX) !== false;
        if ($written && $permissions !== false) {
            @chmod($tempPath, $permissions & 0777);
        }
        $saved = $written && @rename($tempPath, $path);
        if (!$saved) {
            @unlink($tempPath);
        }

        return $saved;
    }
}

if (!function_exists('fridg3_hard_ban_client_ip')) {
    function fridg3_hard_ban_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            foreach (explode(',', (string)$_SERVER[$header]) as $part) {
                $candidate = trim($part);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return '';
    }
}

if (!function_exists('fridg3_hard_ban_contains')) {
    function fridg3_hard_ban_contains(string $candidate): bool
    {
        $packedCandidate = @inet_pton(trim($candidate));
        if ($packedCandidate === false) {
            return false;
        }

        foreach (fridg3_hard_ban_load() as $ip) {
            $packedIp = @inet_pton((string)$ip);
            if ($packedIp !== false && hash_equals($packedIp, $packedCandidate)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fridg3_hard_ban_identity_path')) {
    function fridg3_hard_ban_identity_path(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'hard-ban-identities.json';
    }
}

if (!function_exists('fridg3_hard_ban_valid_identifier')) {
    function fridg3_hard_ban_valid_identifier(string $identifier): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $identifier) === 1;
    }
}

if (!function_exists('fridg3_hard_ban_ips_equal')) {
    function fridg3_hard_ban_ips_equal(string $left, string $right): bool
    {
        $leftPacked = @inet_pton($left);
        $rightPacked = @inet_pton($right);
        return $leftPacked !== false && $rightPacked !== false && hash_equals($leftPacked, $rightPacked);
    }
}

if (!function_exists('fridg3_hard_ban_list_contains')) {
    function fridg3_hard_ban_list_contains(array $ips, string $candidate): bool
    {
        foreach ($ips as $ip) {
            if (fridg3_hard_ban_ips_equal((string)$ip, $candidate)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('fridg3_hard_ban_load_identities')) {
    function fridg3_hard_ban_load_identities(): array
    {
        $path = fridg3_hard_ban_identity_path();
        if (!is_file($path)) {
            return ['identities' => []];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['identities']) || !is_array($decoded['identities'])) {
            return ['identities' => []];
        }
        return $decoded;
    }
}

if (!function_exists('fridg3_hard_ban_write_identities')) {
    function fridg3_hard_ban_write_identities(array $data): bool
    {
        $path = fridg3_hard_ban_identity_path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return false;
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        return @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
    }
}

if (!function_exists('fridg3_hard_ban_filter_group_ips')) {
    function fridg3_hard_ban_filter_group_ips(array $hardBans, array $groupIps): array
    {
        return array_values(array_filter($hardBans, static function ($hardBan) use ($groupIps): bool {
            foreach ($groupIps as $groupIp) {
                if (fridg3_hard_ban_ips_equal((string)$hardBan, (string)$groupIp)) {
                    return false;
                }
            }
            return true;
        }));
    }
}

if (!function_exists('fridg3_hard_ban_admin_save')) {
    function fridg3_hard_ban_admin_save(array $requestedIps): bool
    {
        $data = fridg3_hard_ban_load_identities();
        $releasedPrimaries = [];
        foreach ($data['identities'] as $record) {
            $primaryIp = is_array($record) ? (string)($record['primaryIp'] ?? '') : '';
            if ($primaryIp !== '' && !fridg3_hard_ban_list_contains($requestedIps, $primaryIp)) {
                $releasedPrimaries[$primaryIp] = true;
            }
        }

        foreach (array_keys($releasedPrimaries) as $primaryIp) {
            $groupIps = [$primaryIp];
            foreach ($data['identities'] as $identifier => $record) {
                if (!is_array($record) || !fridg3_hard_ban_ips_equal((string)($record['primaryIp'] ?? ''), $primaryIp)) {
                    continue;
                }
                $groupIps = array_merge($groupIps, (array)($record['ips'] ?? []));
                unset($data['identities'][$identifier]);
            }
            $requestedIps = fridg3_hard_ban_filter_group_ips($requestedIps, $groupIps);
        }

        if (!fridg3_hard_ban_write($requestedIps)) {
            return false;
        }
        return fridg3_hard_ban_write_identities($data);
    }
}

if (!function_exists('fridg3_hard_ban_register_identifier')) {
    function fridg3_hard_ban_register_identifier(string $ip, string $identifier): bool
    {
        if (!fridg3_hard_ban_valid_identifier($identifier) || !fridg3_hard_ban_contains($ip)) {
            return false;
        }

        $data = fridg3_hard_ban_load_identities();
        $existingRecord = is_array($data['identities'][$identifier] ?? null) ? $data['identities'][$identifier] : [];
        $primaryIp = (string)($existingRecord['primaryIp'] ?? $ip);
        foreach ($data['identities'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach ((array)($record['ips'] ?? []) as $knownIp) {
                if (fridg3_hard_ban_ips_equal((string)$knownIp, $ip)) {
                    $primaryIp = (string)($record['primaryIp'] ?? $ip);
                    break 2;
                }
            }
        }

        $record = $existingRecord;
        $knownIps = array_values(array_filter(array_map('strval', (array)($record['ips'] ?? [])), static fn(string $knownIp): bool => filter_var($knownIp, FILTER_VALIDATE_IP) !== false));
        if (!fridg3_hard_ban_list_contains($knownIps, $ip)) {
            $knownIps[] = $ip;
        }
        $now = gmdate(DATE_ATOM);
        $data['identities'][$identifier] = [
            'primaryIp' => $primaryIp,
            'ips' => $knownIps,
            'firstSeen' => (string)($record['firstSeen'] ?? $now),
            'lastSeen' => $now,
            'userAgentHash' => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];
        return fridg3_hard_ban_write_identities($data);
    }
}

if (!function_exists('fridg3_hard_ban_check_client')) {
    function fridg3_hard_ban_check_client(string $ip, string $identifier): bool
    {
        if (!fridg3_hard_ban_valid_identifier($identifier)) {
            return fridg3_hard_ban_contains($ip);
        }

        $hardBans = fridg3_hard_ban_load();
        $data = fridg3_hard_ban_load_identities();
        $record = $data['identities'][$identifier] ?? null;
        if (!is_array($record)) {
            return fridg3_hard_ban_list_contains($hardBans, $ip);
        }

        $primaryIp = (string)($record['primaryIp'] ?? '');
        if ($primaryIp === '' || !fridg3_hard_ban_list_contains($hardBans, $primaryIp)) {
            fridg3_hard_ban_admin_save($hardBans);
            return fridg3_hard_ban_contains($ip);
        }

        if (!fridg3_hard_ban_list_contains($hardBans, $ip)) {
            $hardBans[] = $ip;
            if (!fridg3_hard_ban_write($hardBans)) {
                return true;
            }
        }
        fridg3_hard_ban_register_identifier($ip, $identifier);
        return true;
    }
}
