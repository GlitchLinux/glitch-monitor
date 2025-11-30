<?php
function execCommand($cmd) {
    return trim(shell_exec($cmd . " 2>/dev/null"));
}

// Get server name (try hostname, fallback to system info)
$serverName = execCommand("hostname");
if (empty($serverName)) {
    $serverName = "SERVER";
}
$serverName = strtoupper($serverName);

// Set download filename with server name and datetime
$filename = $serverName . "-status-" . date('Y-m-d-H-i') . ".txt";
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Generate timestamp
$timestamp = gmdate('Y-m-d H:i:s') . ' UTC';

// Output report
echo "$serverName STATUS REPORT\n";
echo "Generated: $timestamp\n\n";

// SYSTEM OVERVIEW
echo "=================\n";
echo " SYSTEM OVERVIEW\n";
echo "=================\n\n";

echo "Hostname:        " . execCommand("hostname") . "\n";
$os = execCommand("cat /etc/os-release | grep PRETTY_NAME | cut -d'\"' -f2");
if (empty($os)) $os = execCommand("lsb_release -d | cut -f2");
echo "OS:              " . $os . "\n";
echo "Kernel:          " . execCommand("uname -r") . "\n";
echo "Uptime:          " . execCommand("uptime -p") . "\n";
echo "Boot Time:       " . execCommand("uptime -s") . "\n";
echo "Private IP:      " . execCommand("hostname -I | awk '{print \$1}'") . "\n";
$publicIP = execCommand("timeout 3 curl -s ifconfig.me");
echo "Public IP:       " . ($publicIP ?: "N/A") . "\n";

// CPU & LOAD
echo "\n============\n";
echo " CPU & LOAD\n";
echo "============\n\n";

$cores = execCommand("nproc");
$load = execCommand("cat /proc/loadavg");
echo "CPU Cores:       $cores\n";
echo "Load Average:    $load\n";
$temp = execCommand("cat /sys/class/thermal/thermal_zone0/temp");
if ($temp && is_numeric($temp)) {
    echo "Temperature:     " . round($temp/1000, 1) . "°C\n";
}

// MEMORY
echo "\n========\n";
echo " MEMORY\n";
echo "========\n\n";

echo execCommand("free -h") . "\n";

// DISK USAGE
echo "\n============\n";
echo " DISK USAGE\n";
echo "============\n\n";

echo execCommand("df -h | grep -E '^/dev|Filesystem'") . "\n";
echo "\nInode Usage:     " . execCommand("df -i / | awk 'NR==2 {print \$5}'") . "\n";

// NETWORK
echo "\n=================\n";
echo " NETWORK\n";
echo "=================\n\n";

$iface = execCommand("ip route | grep default | awk '{print \$5}' | head -1");
if (empty($iface)) {
    $iface = execCommand("ls /sys/class/net/ | grep -v lo | head -1");
}
$rx = execCommand("cat /sys/class/net/$iface/statistics/rx_bytes");
$tx = execCommand("cat /sys/class/net/$iface/statistics/tx_bytes");
echo "Interface:       $iface\n";
echo "Data Received:   " . round($rx/1024/1024/1024, 2) . " GB\n";
echo "Data Served:     " . round($tx/1024/1024/1024, 2) . " GB\n";
echo "Established:     " . execCommand("ss -tn state established | wc -l") . " connections\n";
echo "Open Ports:      " . execCommand("ss -tuln | grep LISTEN | awk '{print \$5}' | sed 's/.*://' | sort -n | uniq | tr '\n' ' '") . "\n";

// SECURITY
echo "\n==========\n";
echo " SECURITY\n";
echo "==========\n\n";

$rootLogin = execCommand("grep '^PermitRootLogin' /etc/ssh/sshd_config | awk '{print \$2}'");
echo "SSH Root Login:  " . ($rootLogin ?: "not configured") . "\n";
echo "Failed SSH 24h:  " . execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep -c 'Failed password'") . "\n";
echo "Failed SSH Today:" . execCommand("sudo journalctl -u ssh --since today 2>/dev/null | grep -c 'Failed password'") . "\n";
echo "Active Sessions: " . execCommand("who | wc -l") . "\n";

echo "\nRecent Logins:\n";
echo execCommand("last -n 5 -a");
echo "\n";

// FAIL2BAN STATUS
echo "\n=================\n";
echo " FAIL2BAN STATUS\n";
echo "=================\n\n";

$f2b = execCommand("sudo fail2ban-client status sshd");
if ($f2b) {
    echo $f2b . "\n";

    echo "\nTop Attackers (24h):\n";
    $attackers = execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep 'Failed password' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | sort | uniq -c | sort -rn | head -10");
    if ($attackers) {
        echo $attackers . "\n";
    } else {
        echo "No attacks detected\n";
    }
} else {
    echo "Fail2ban not running or sshd jail not configured\n";
}

// SERVICES
echo "\n==========\n";
echo " SERVICES\n";
echo "==========\n\n";

$services = ['apache2', 'httpd', 'ssh', 'fail2ban', 'cron'];
foreach ($services as $svc) {
    $status = execCommand("systemctl is-active $svc");
    if ($status && $status !== 'inactive') {
        echo sprintf("%-15s %s\n", $svc . ":", strtoupper($status));
    }
}

echo "\nFailed Services:\n";
$failed = execCommand("systemctl --failed --no-pager --no-legend");
echo ($failed ?: "None") . "\n";

// APACHE WEB SERVER
echo "\n===================\n";
echo " APACHE WEB SERVER\n";
echo "===================\n\n";

$httpsConns = execCommand("ss -tn | grep ':443 ' | wc -l");
$httpConns = execCommand("ss -tn | grep ':80 ' | wc -l");
$apacheProcs = execCommand("pgrep -c 'apache2|httpd'");
$requestsToday = execCommand("grep '" . date('d/M/Y') . "' /var/log/apache2/access.log /var/log/httpd/access_log 2>/dev/null | wc -l");

echo "HTTPS Conns:     $httpsConns\n";
echo "HTTP Conns:      $httpConns\n";
echo "Processes:       $apacheProcs\n";
echo "Requests Today:  $requestsToday\n";

// SSL CERTIFICATES
echo "\n==================\n";
echo " SSL CERTIFICATES\n";
echo "==================\n\n";

// Auto-detect domains from Apache config
$apacheConf = execCommand("grep -r 'ServerName' /etc/apache2/sites-enabled/ /etc/httpd/conf.d/ 2>/dev/null | grep -v '#' | awk '{print \$2}' | sort -u");
if ($apacheConf) {
    $domains = array_filter(explode("\n", $apacheConf));
    $domains = array_slice($domains, 0, 5); // Limit to 5

    $hasCerts = false;
    foreach ($domains as $domain) {
        if (empty($domain) || $domain === 'localhost') continue;

        $expiry = execCommand("echo | openssl s_client -servername $domain -connect $domain:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2");
        if ($expiry) {
            $days = round((strtotime($expiry) - time()) / 86400);
            echo sprintf("%-25s %d days remaining\n", $domain . ":", $days);
            $hasCerts = true;
        }
    }

    if (!$hasCerts) {
        echo "No SSL certificates found or all checks timed out\n";
    }
} else {
    echo "No domains configured or unable to read Apache configuration\n";
}

// STORAGE BREAKDOWN
echo "\n===================\n";
echo " STORAGE BREAKDOWN\n";
echo "===================\n\n";

$rootDf = execCommand("df -h / | awk 'NR==2 {print \$3, \$2}'");
$parts = explode(' ', $rootDf);
$used = $parts[0] ?? 'N/A';
$total = $parts[1] ?? 'N/A';
echo "/:               $used of $total\n";

$varwww = execCommand("du -sh /var/www 2>/dev/null | awk '{print \$1}'");
if ($varwww) echo "/var/www:        $varwww\n";

$varlog = execCommand("du -sh /var/log 2>/dev/null | awk '{print \$1}'");
if ($varlog) echo "/var/log:        $varlog\n";

// END
echo "\n===============\n";
echo " END OF REPORT\n";
echo "===============\n";
?>
