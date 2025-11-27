<?php
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="glitch-server-status-' . date('Y-m-d-H-i') . '.txt"');

function execCommand($cmd) {
    return trim(shell_exec($cmd . " 2>/dev/null"));
}

$line = str_repeat("=", 70);
$dash = str_repeat("-", 70);

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║           gLiTcH-SERVER STATUS REPORT                                ║\n";
echo "║           Generated: " . date('Y-m-d H:i:s T') . str_repeat(" ", 30) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

// SYSTEM OVERVIEW
echo "$line\n";
echo " SYSTEM OVERVIEW\n";
echo "$line\n\n";

echo "Hostname:        " . execCommand("hostname") . "\n";
echo "OS:              " . execCommand("lsb_release -d | cut -f2") . "\n";
echo "Kernel:          " . execCommand("uname -r") . "\n";
echo "Uptime:          " . execCommand("uptime -p") . "\n";
echo "Boot Time:       " . execCommand("uptime -s") . "\n";
echo "Private IP:      " . execCommand("hostname -I | awk '{print \$1}'") . "\n";
echo "Public IP:       " . execCommand("curl -s --max-time 3 ifconfig.me") . "\n";

// CPU & LOAD
echo "\n$dash\n";
echo " CPU & LOAD\n";
echo "$dash\n\n";

$load = execCommand("cat /proc/loadavg");
$cores = execCommand("nproc");
echo "CPU Cores:       $cores\n";
echo "Load Average:    $load\n";
$temp = execCommand("cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null");
if ($temp) echo "Temperature:     " . round($temp/1000, 1) . "°C\n";

// MEMORY
echo "\n$dash\n";
echo " MEMORY\n";
echo "$dash\n\n";

echo execCommand("free -h") . "\n";

// DISK
echo "\n$dash\n";
echo " DISK USAGE\n";
echo "$dash\n\n";

echo execCommand("df -h | grep -E '^/dev|Filesystem'") . "\n";
echo "\nInode Usage:     " . execCommand("df -i / | awk 'NR==2 {print \$5}'") . "\n";

// NETWORK
echo "\n$dash\n";
echo " NETWORK\n";
echo "$dash\n\n";

$iface = execCommand("ip route | grep default | awk '{print \$5}' | head -1");
$rx = execCommand("cat /sys/class/net/$iface/statistics/rx_bytes");
$tx = execCommand("cat /sys/class/net/$iface/statistics/tx_bytes");
echo "Interface:       $iface\n";
echo "Data Received:   " . round($rx/1024/1024/1024, 2) . " GB\n";
echo "Data Served:     " . round($tx/1024/1024/1024, 2) . " GB\n";
echo "Established:     " . execCommand("ss -tn state established | wc -l") . " connections\n";
echo "Open Ports:      " . execCommand("ss -tuln | grep LISTEN | awk '{print \$5}' | sed 's/.*://' | sort -n | uniq | tr '\n' ' '") . "\n";

// SECURITY
echo "\n$line\n";
echo " SECURITY\n";
echo "$line\n\n";

echo "SSH Root Login:  " . execCommand("grep '^PermitRootLogin' /etc/ssh/sshd_config | awk '{print \$2}'") . "\n";
echo "Failed SSH 24h:  " . execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep -c 'Failed password'") . "\n";
echo "Failed SSH Today:" . execCommand("sudo journalctl -u ssh --since today 2>/dev/null | grep -c 'Failed password'") . "\n";
echo "Active Sessions: " . execCommand("who | wc -l") . "\n";
echo "\nRecent Logins:\n" . execCommand("last -n 5 -a") . "\n";

// FAIL2BAN
echo "\n$dash\n";
echo " FAIL2BAN STATUS\n";
echo "$dash\n\n";

$f2b = execCommand("sudo fail2ban-client status sshd 2>/dev/null");
if ($f2b) {
    echo $f2b . "\n";
} else {
    echo "Fail2ban not running or sshd jail not configured\n";
}

echo "\nTop Attackers (24h):\n";
echo execCommand("sudo journalctl -u ssh --since '24 hours ago' 2>/dev/null | grep 'Failed password' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | sort | uniq -c | sort -rn | head -10") . "\n";

// SERVICES
echo "\n$line\n";
echo " SERVICES\n";
echo "$line\n\n";

$services = ['apache2', 'ssh', 'fail2ban', 'cron'];
foreach ($services as $svc) {
    $status = execCommand("systemctl is-active $svc");
    echo sprintf("%-15s %s\n", $svc . ":", strtoupper($status));
}

echo "\nFailed Services:\n";
$failed = execCommand("systemctl --failed --no-pager --no-legend");
echo ($failed ?: "None") . "\n";

// APACHE
echo "\n$dash\n";
echo " APACHE WEB SERVER\n";
echo "$dash\n\n";

echo "HTTPS Conns:     " . execCommand("ss -tn | grep ':443 ' | wc -l") . "\n";
echo "HTTP Conns:      " . execCommand("ss -tn | grep ':80 ' | wc -l") . "\n";
echo "Processes:       " . execCommand("pgrep -c apache2") . "\n";
echo "Requests Today:  " . execCommand("grep '" . date('d/M/Y') . "' /var/log/apache2/access.log 2>/dev/null | wc -l") . "\n";

// SSL CERTIFICATES
echo "\n$dash\n";
echo " SSL CERTIFICATES\n";
echo "$dash\n\n";

$domains = ['glitchlinux.wtf', 'glitchlinux.com', 'glitchserver.com', 'bonsailinux.com'];
foreach ($domains as $domain) {
    $expiry = execCommand("echo | openssl s_client -servername $domain -connect $domain:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2");
    if ($expiry) {
        $days = round((strtotime($expiry) - time()) / 86400);
        echo sprintf("%-20s %d days remaining\n", $domain . ":", $days);
    }
}

// STORAGE BREAKDOWN
echo "\n$dash\n";
echo " STORAGE BREAKDOWN\n";
echo "$dash\n\n";

echo "/var/www:        " . execCommand("du -sh /var/www 2>/dev/null | awk '{print \$1}'") . "\n";
echo "/var/log:        " . execCommand("du -sh /var/log 2>/dev/null | awk '{print \$1}'") . "\n";
echo "/CLAUDE-LOG:     " . execCommand("du -sh /CLAUDE-LOG 2>/dev/null | awk '{print \$1}'") . "\n";

// CLOGGER
echo "\n$dash\n";
echo " CLOGGER AUDIT LOG\n";
echo "$dash\n\n";

echo "Total Entries:   " . execCommand("wc -l < /CLAUDE-LOG/clogger.log") . "\n";
echo "Today's Entries: " . execCommand("grep '" . date('d-m-Y') . "' /CLAUDE-LOG/clogger.log | wc -l") . "\n";
echo "\nLast 5 Entries:\n";
echo execCommand("tail -5 /CLAUDE-LOG/clogger.log") . "\n";

echo "\n$line\n";
echo " END OF REPORT\n";
echo "$line\n";
