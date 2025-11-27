<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

function execCommand($command) {
    $output = shell_exec($command . " 2>/dev/null");
    return trim($output);
}

// Security: Failed SSH attempts (using journalctl + fail2ban)
function getSSHSecurity() {
    // Get failed attempts from journalctl (more reliable)
    $failedTotal = (int)execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep -c 'Failed password' || echo 0");
    $failedToday = (int)execCommand("sudo journalctl -u ssh --since today 2>/dev/null | grep -c 'Failed password' || echo 0");
    
    // Get fail2ban banned IPs
    $f2bStatus = execCommand("sudo fail2ban-client status sshd 2>/dev/null");
    $bannedCount = 0;
    $bannedIPs = '';
    
    if ($f2bStatus) {
        preg_match('/Currently banned:\s+(\d+)/', $f2bStatus, $m);
        $bannedCount = (int)($m[1] ?? 0);
        preg_match('/Banned IP list:\s+(.*)/', $f2bStatus, $m);
        $bannedIPs = trim($m[1] ?? '');
    }
    
    $activeSessions = execCommand("who | wc -l");
    $lastLogins = execCommand("last -n 5 -a | head -5");
    
    return [
        'failed_total' => $failedTotal,
        'failed_today' => $failedToday,
        'banned_count' => $bannedCount,
        'top_attackers' => $bannedIPs ?: 'None currently banned',
        'active_sessions' => (int)$activeSessions,
        'last_logins' => $lastLogins
    ];
}

// Apache stats
function getApacheStats() {
    $connections80 = execCommand("ss -tn 2>/dev/null | grep ':80 ' | wc -l");
    $connections443 = execCommand("ss -tn 2>/dev/null | grep ':443 ' | wc -l");
    $apacheProcs = execCommand("pgrep -c apache2");
    
    // Recent errors (last 10)
    $recentErrors = execCommand("tail -10 /var/log/apache2/error.log 2>/dev/null | tail -5");
    
    // Requests today from access logs
    $requestsToday = execCommand("grep '".date('d/M/Y')."' /var/log/apache2/access.log 2>/dev/null | wc -l");
    
    // Top IPs today
    $topIPs = execCommand("grep '".date('d/M/Y')."' /var/log/apache2/access.log 2>/dev/null | awk '{print $1}' | sort | uniq -c | sort -rn | head -5");
    
    // HTTP errors today
    $errors4xx = execCommand("grep '".date('d/M/Y')."' /var/log/apache2/access.log 2>/dev/null | grep '\" 4[0-9][0-9] ' | wc -l");
    $errors5xx = execCommand("grep '".date('d/M/Y')."' /var/log/apache2/access.log 2>/dev/null | grep '\" 5[0-9][0-9] ' | wc -l");
    
    return [
        'connections_http' => (int)$connections80,
        'connections_https' => (int)$connections443,
        'processes' => (int)$apacheProcs,
        'requests_today' => (int)$requestsToday,
        'errors_4xx' => (int)$errors4xx,
        'errors_5xx' => (int)$errors5xx,
        'top_ips' => $topIPs,
        'recent_errors' => $recentErrors
    ];
}

// Network details
function getNetworkDetails() {
    $openPorts = execCommand("ss -tuln | grep LISTEN | awk '{print $5}' | sed 's/.*://' | sort -n | uniq | tr '\n' ' '");
    $connStates = execCommand("ss -s | grep -E 'TCP:|estab|closed|timewait'");
    $established = execCommand("ss -tn state established | wc -l");
    $timeWait = execCommand("ss -tn state time-wait | wc -l");
    
    // Get interface and bandwidth - auto-detect primary interface
    $interface = trim(execCommand("ip route | grep default | awk '{print \$5}' | head -1"));
    if (empty($interface)) $interface = 'eth0';
    $rxBytes = execCommand("cat /sys/class/net/" . $interface . "/statistics/rx_bytes 2>/dev/null");
    $txBytes = execCommand("cat /sys/class/net/" . $interface . "/statistics/tx_bytes 2>/dev/null");
    
    return [
        'open_ports' => $openPorts,
        'established' => (int)$established,
        'time_wait' => (int)$timeWait,
        'interface' => $interface,
        'rx_bytes' => (int)$rxBytes,
        'tx_bytes' => (int)$txBytes,
        'rx_gb' => round((int)$rxBytes / 1024 / 1024 / 1024, 2),
        'tx_gb' => round((int)$txBytes / 1024 / 1024 / 1024, 2)
    ];
}

// Storage details
function getStorageDetails() {
    $dfOutput = execCommand("df -h | grep -E '^/dev/' | awk '{print $1,$2,$3,$4,$5,$6}'");
    $inodes = execCommand("df -i / | awk 'NR==2 {print $5}'");
    
    // Directory sizes
    $wwwSize = execCommand("du -sh /var/www 2>/dev/null | awk '{print $1}'");
    $logSize = execCommand("du -sh /var/log 2>/dev/null | awk '{print $1}'");
    $claudeLogSize = execCommand("du -sh /CLAUDE-LOG 2>/dev/null | awk '{print $1}'");
    
    // Largest files
    $largestFiles = execCommand("find /var/www -type f -size +50M -exec ls -lh {} \; 2>/dev/null | awk '{print $5,$9}' | head -5");
    
    return [
        'filesystems' => $dfOutput,
        'inode_usage' => $inodes,
        'www_size' => $wwwSize ?: 'N/A',
        'log_size' => $logSize ?: 'N/A',
        'claude_log_size' => $claudeLogSize ?: 'N/A',
        'largest_files' => $largestFiles
    ];
}

// Services status
function getServicesStatus() {
    $services = ['apache2', 'ssh', 'cron', 'rsyslog', 'systemd-timesyncd'];
    $status = [];
    
    foreach ($services as $svc) {
        $isActive = execCommand("systemctl is-active $svc");
        $uptime = execCommand("systemctl show $svc --property=ActiveEnterTimestamp | cut -d= -f2");
        $status[$svc] = [
            'active' => ($isActive === 'active'),
            'status' => $isActive,
            'since' => $uptime
        ];
    }
    
    // Failed services
    $failed = execCommand("systemctl --failed --no-pager --no-legend | head -5");
    
    return [
        'services' => $status,
        'failed' => $failed ?: 'None'
    ];
}

// SSL certificates
function getSSLStatus() {
    $domains = ['glitchlinux.wtf', 'glitchlinux.com', 'glitchserver.com', 'bonsailinux.com'];
    $certs = [];
    
    foreach ($domains as $domain) {
        $expiry = execCommand("echo | openssl s_client -servername $domain -connect $domain:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2");
        if ($expiry) {
            $expiryTime = strtotime($expiry);
            $daysLeft = round(($expiryTime - time()) / 86400);
            $certs[$domain] = [
                'expiry' => $expiry,
                'days_left' => $daysLeft,
                'status' => $daysLeft > 30 ? 'ok' : ($daysLeft > 7 ? 'warning' : 'danger')
            ];
        } else {
            $certs[$domain] = ['expiry' => 'Unknown', 'days_left' => null, 'status' => 'unknown'];
        }
    }
    
    return $certs;
}

// Site response times
function getSiteResponseTimes() {
    $sites = [
        'glitchlinux.wtf' => 'https://glitchlinux.wtf',
        'glitchlinux.com' => 'https://glitchlinux.com',
        'glitchserver.com' => 'https://glitchserver.com',
        'bonsailinux.com' => 'https://bonsailinux.com'
    ];
    $times = [];
    
    foreach ($sites as $name => $url) {
        $time = execCommand("curl -o /dev/null -s -w '%{time_total}' --max-time 5 $url");
        $times[$name] = $time ? round((float)$time * 1000) . 'ms' : 'timeout';
    }
    
    return $times;
}

// System alerts
function getAlerts() {
    $alerts = [];
    
    // Disk alert
    $diskPercent = (int)execCommand("df / | awk 'NR==2 {print $5}' | tr -d '%'");
    if ($diskPercent >= 90) {
        $alerts[] = ['type' => 'danger', 'message' => "Disk usage critical: {$diskPercent}%"];
    } elseif ($diskPercent >= 75) {
        $alerts[] = ['type' => 'warning', 'message' => "Disk usage high: {$diskPercent}%"];
    }
    
    // RAM alert
    $ramPercent = (int)execCommand("free | awk 'NR==2 {printf \"%.0f\", ($3/$2)*100}'");
    if ($ramPercent >= 90) {
        $alerts[] = ['type' => 'danger', 'message' => "RAM usage critical: {$ramPercent}%"];
    } elseif ($ramPercent >= 80) {
        $alerts[] = ['type' => 'warning', 'message' => "RAM usage high: {$ramPercent}%"];
    }
    
    // Load alert
    $load = (float)execCommand("cat /proc/loadavg | awk '{print $1}'");
    $cores = (int)execCommand("nproc");
    if ($load > $cores * 2) {
        $alerts[] = ['type' => 'danger', 'message' => "CPU load critical: {$load}"];
    } elseif ($load > $cores) {
        $alerts[] = ['type' => 'warning', 'message' => "CPU load high: {$load}"];
    }
    
    // Failed services
    $failedCount = (int)execCommand("systemctl --failed --no-pager --no-legend | wc -l");
    if ($failedCount > 0) {
        $alerts[] = ['type' => 'danger', 'message' => "{$failedCount} failed systemd service(s)"];
    }
    
    return $alerts;
}

// Clogger stats
function getCloggerStats() {
    $totalEntries = execCommand("wc -l < /CLAUDE-LOG/clogger.log 2>/dev/null");
    $todayEntries = execCommand("grep '".date('d-m-Y')."' /CLAUDE-LOG/clogger.log 2>/dev/null | wc -l");
    $lastEntry = execCommand("tail -1 /CLAUDE-LOG/clogger.log 2>/dev/null");
    
    return [
        'total' => (int)$totalEntries,
        'today' => (int)$todayEntries,
        'last_entry' => $lastEntry
    ];
}


// Fail2ban detailed stats
function getFail2banStats() {
    $f2bStatus = execCommand("sudo fail2ban-client status sshd 2>/dev/null");
    
    $currentlyFailed = 0;
    $totalFailed = 0;
    $currentlyBanned = 0;
    $totalBanned = 0;
    $bannedList = [];
    
    if ($f2bStatus) {
        preg_match('/Currently failed:\s+(\d+)/', $f2bStatus, $m);
        $currentlyFailed = (int)($m[1] ?? 0);
        preg_match('/Total failed:\s+(\d+)/', $f2bStatus, $m);
        $totalFailed = (int)($m[1] ?? 0);
        preg_match('/Currently banned:\s+(\d+)/', $f2bStatus, $m);
        $currentlyBanned = (int)($m[1] ?? 0);
        preg_match('/Total banned:\s+(\d+)/', $f2bStatus, $m);
        $totalBanned = (int)($m[1] ?? 0);
        preg_match('/Banned IP list:\s+(.*)/', $f2bStatus, $m);
        $bannedListStr = trim($m[1] ?? '');
        if ($bannedListStr) {
            $bannedList = explode(' ', $bannedListStr);
        }
    }
    
    $topAttackers = execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep 'Failed password' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | sort | uniq -c | sort -rn | head -10");
    $uniqueAttackers = (int)execCommand("sudo journalctl -u ssh --since today 2>/dev/null | grep 'Failed password' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | sort -u | wc -l");
    $banTime = execCommand("grep -E '^bantime' /etc/fail2ban/jail.local 2>/dev/null | awk '{print \$3}'") ?: '3600';
    
    return [
        'currently_failed' => $currentlyFailed,
        'total_failed' => $totalFailed,
        'currently_banned' => $currentlyBanned,
        'total_banned' => $totalBanned,
        'banned_ips' => $bannedList,
        'top_attackers' => $topAttackers,
        'unique_attackers_today' => $uniqueAttackers,
        'ban_duration' => (int)$banTime,
        'jail_status' => 'active'
    ];
}

// Collect all extended stats
$stats = [
    'security' => getSSHSecurity(),
    'apache' => getApacheStats(),
    'network' => getNetworkDetails(),
    'storage' => getStorageDetails(),
    'services' => getServicesStatus(),
    'ssl' => getSSLStatus(),
    'response_times' => getSiteResponseTimes(),
    'alerts' => getAlerts(),
    'clogger' => getCloggerStats(),
    'fail2ban' => getFail2banStats(),
    'timestamp' => time(),
    'generated' => date('Y-m-d H:i:s')
];

echo json_encode($stats, JSON_PRETTY_PRINT);
