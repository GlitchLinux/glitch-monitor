<?php
/**
 * Server Statistics API
 * Returns real-time server stats in JSON format
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Function to execute shell commands safely
function execCommand($command) {
    $output = shell_exec($command);
    return trim($output);
}

// Get disk usage
function getDiskStats() {
    $df = execCommand("df -h / | awk 'NR==2 {print $3,$2,$5}'");
    $parts = explode(' ', $df);
    
    return [
        'used' => $parts[0] ?? 'N/A',
        'total' => $parts[1] ?? 'N/A',
        'percent' => (int)str_replace('%', '', $parts[2] ?? '0')
    ];
}

// Get memory usage
function getMemoryStats() {
    $free = execCommand("free -m | awk 'NR==2 {printf \"%s %s %.0f\", $3,$2,($3/$2)*100}'");
    $parts = explode(' ', $free);
    
    return [
        'used' => ($parts[0] ?? '0') . ' MB',
        'total' => ($parts[1] ?? '0') . ' MB',
        'percent' => (int)($parts[2] ?? 0)
    ];
}

// Get CPU load average
function getCPUStats() {
    $load = execCommand("cat /proc/loadavg | awk '{print $1,$2,$3}'");
    $parts = explode(' ', $load);
    
    // Get number of CPU cores
    $cores = (int)execCommand("nproc");
    $loadAvg = (float)($parts[0] ?? 0);
    
    // Calculate percentage based on first load average and core count
    $percent = min(100, ($loadAvg / $cores) * 100);
    
    return [
        'load' => $load,
        'percent' => round($percent, 1)
    ];
}

// Get system uptime
function getUptimeStats() {
    $uptime = execCommand("uptime -p");
    $uptimeSince = execCommand("uptime -s");
    
    return [
        'formatted' => str_replace('up ', '', $uptime),
        'detailed' => 'Since ' . $uptimeSince
    ];
}

// Get network information
function getNetworkStats() {
    // Get private IP
    $privateIP = execCommand("hostname -I | awk '{print $1}'");
    
    // Get public IP (with timeout to avoid hanging)
    $publicIP = execCommand("timeout 2 curl -s ifconfig.me 2>/dev/null || echo 'N/A'");
    
    // Get network traffic (RX/TX in MB)
    $rx = execCommand("cat /sys/class/net/enp0s3/statistics/rx_bytes 2>/dev/null || echo 0");
    $tx = execCommand("cat /sys/class/net/enp0s3/statistics/tx_bytes 2>/dev/null || echo 0");
    
    $rxMB = round((int)$rx / 1024 / 1024, 2);
    $txMB = round((int)$tx / 1024 / 1024, 2);
    
    return [
        'private_ip' => $privateIP ?: 'N/A',
        'public_ip' => $publicIP ?: 'N/A',
        'rx' => $rxMB . ' MB',
        'tx' => $txMB . ' MB'
    ];
}

// Get active services count
function getServicesStats() {
    $count = execCommand("systemctl list-units --type=service --state=running --no-pager --no-legend | wc -l");
    
    return [
        'count' => (int)$count
    ];
}

// Get system information
function getSystemInfo() {
    $os = execCommand("cat /etc/os-release | grep PRETTY_NAME | cut -d'\"' -f2");
    $kernel = execCommand("uname -r");
    
    return [
        'os' => $os ?: 'Debian',
        'kernel' => $kernel ?: 'Unknown'
    ];
}

// Get process count
function getProcessStats() {
    $total = execCommand("ps aux | wc -l");
    
    return [
        'total' => (int)$total - 1 // Subtract header line
    ];
}

// Get temperature (if available)
function getTemperature() {
    // Try to get CPU temperature from different sources
    $temp = execCommand("cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null");
    
    if ($temp && is_numeric($temp)) {
        $tempC = round($temp / 1000, 1);
        return [
            'value' => $tempC . '°C',
            'celsius' => $tempC
        ];
    }
    
    // Try sensors command if available
    $sensors = execCommand("sensors 2>/dev/null | grep -i 'Core 0' | awk '{print $3}' | tr -d '+°C'");
    if ($sensors && is_numeric($sensors)) {
        return [
            'value' => $sensors . '°C',
            'celsius' => (float)$sensors
        ];
    }
    
    return [
        'value' => 'N/A',
        'celsius' => null
    ];
}

// Collect all stats
$stats = [
    'disk' => getDiskStats(),
    'memory' => getMemoryStats(),
    'cpu' => getCPUStats(),
    'uptime' => getUptimeStats(),
    'network' => getNetworkStats(),
    'services' => getServicesStats(),
    'system' => getSystemInfo(),
    'processes' => getProcessStats(),
    'temperature' => getTemperature(),
    'timestamp' => time()
];

// Output JSON
echo json_encode($stats, JSON_PRETTY_PRINT);
?>
