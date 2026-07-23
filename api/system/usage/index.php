<?php
// Returns JSON with current system usage metrics: CPU, memory, and disk usage percentages.
// Uses /proc on Linux and wmic/PowerShell on Windows.

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'debug.php';

header('Content-Type: application/json');

function clamp_percent($value) {
    if (!is_numeric($value)) return null;
    return max(0, min(100, (float)$value));
}

function is_windows_mode() {
    if (isset($_GET['os']) && strtolower((string)$_GET['os']) === 'windows') return true;
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

// Linux helpers (/proc)
function read_proc_stat() {
    $stat = @file('/proc/stat');
    if (!$stat) return null;
    foreach ($stat as $line) {
        if (strpos($line, 'cpu ') === 0) {
            $parts = preg_split('/\s+/', trim($line));
            return array_map('intval', array_slice($parts, 1, 8));
        }
    }
    return null;
}

function cpu_usage_percent_linux() {
    $a = read_proc_stat();
    if (!$a) return null;
    usleep(100000); // 100ms
    $b = read_proc_stat();
    if (!$b) return null;
    $idleA = $a[3] + $a[4];
    $idleB = $b[3] + $b[4];
    $totalA = array_sum($a);
    $totalB = array_sum($b);
    $totald = $totalB - $totalA;
    $idled = $idleB - $idleA;
    if ($totald <= 0) return null;
    return clamp_percent(100 * ($totald - $idled) / $totald);
}

function memory_usage_percent_linux() {
    $meminfo = @file('/proc/meminfo');
    if (!$meminfo) return null;
    $data = [];
    foreach ($meminfo as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $data[$m[1]] = (int)$m[2];
        }
    }
    if (!isset($data['MemTotal'])) return null;
    $total = $data['MemTotal'];
    $avail = $data['MemAvailable'] ?? ($data['MemFree'] ?? 0);
    $used = max(0, $total - $avail);
    if ($total <= 0) return null;
    return clamp_percent(100 * $used / $total);
}

// Windows helpers (wmic)
function cpu_usage_percent_windows() {
    // First try legacy wmic
    $out = @shell_exec('wmic cpu get loadpercentage /value');
    if ($out && preg_match('/LoadPercentage\s*=\s*(\d+)/i', $out, $m)) {
        return clamp_percent($m[1]);
    }

    // Fallback to PowerShell (wmic removed on newer Windows)
    $ps = 'powershell -NoLogo -NoProfile -Command "Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average | Select-Object -ExpandProperty Average"';
    $psOut = @shell_exec($ps);
    if ($psOut && preg_match('/(\d+(?:\.\d+)?)/', $psOut, $pm)) {
        return clamp_percent($pm[1]);
    }

    return null;
}

function memory_usage_percent_windows() {
    // Try legacy wmic first
    $out = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value');
    if ($out &&
        preg_match('/FreePhysicalMemory\s*=\s*(\d+)/i', $out, $freeMatch) &&
        preg_match('/TotalVisibleMemorySize\s*=\s*(\d+)/i', $out, $totalMatch)) {
        $free = (float)$freeMatch[1];
        $total = (float)$totalMatch[1];
        if ($total > 0) {
            $used = max(0, $total - $free);
            return clamp_percent(100 * $used / $total);
        }
    }

    // PowerShell fallback for systems without wmic
    // Returns a single line with the percent to simplify parsing
    $psMemPercent = 'powershell -NoLogo -NoProfile -Command "($os = Get-CimInstance Win32_OperatingSystem) | ForEach-Object { 100 * (1 - ($_.FreePhysicalMemory / $_.TotalVisibleMemorySize)) }"';
    $psOut = @shell_exec($psMemPercent);
    if ($psOut) {
        // Handle decimals and commas
        $clean = str_replace(',', '.', trim($psOut));
        if (preg_match('/(-?\d+(?:\.\d+)?)/', $clean, $m)) {
            return clamp_percent($m[1]);
        }
    }

    return null;
}

function disk_usage_percent_common($path = '/') {
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $total <= 0 || $free === false) return null;
    $used = $total - $free;
    return clamp_percent(100 * $used / $total);
}

$isWindows = is_windows_mode();

if ($isWindows) {
    $cpu = cpu_usage_percent_windows();
    $mem = memory_usage_percent_windows();
    // pick system drive for disk metric
    $drive = getenv('SystemDrive') ?: 'C:';
    $disk = disk_usage_percent_common($drive . DIRECTORY_SEPARATOR);
} else {
    $cpu = cpu_usage_percent_linux();
    $mem = memory_usage_percent_linux();
    $disk = disk_usage_percent_common('/');
}

$response = [
    'cpu' => $cpu !== null ? round($cpu) : null,
    'memory' => $mem !== null ? round($mem) : null,
    'disk' => $disk !== null ? round($disk) : null,
    'timestamp' => date('c'),
    'os' => $isWindows ? 'windows' : 'linux'
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
