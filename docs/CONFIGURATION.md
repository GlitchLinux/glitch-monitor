# Configuration Guide

## Customizing the Dashboard

### Change Refresh Rate

Edit `src/dashboard.html`, find:
```javascript
setInterval(updateStats, 5000);
```
Change `5000` (milliseconds) to your preferred interval.

### Add Custom Services to Monitor

Edit `src/stats-extended.php`, find the `getServicesStatus()` function:
```php
$services = ['apache2', 'ssh', 'cron', 'rsyslog', 'systemd-timesyncd'];
```
Add your services to the array.

### Add SSL Domains to Monitor

Edit `src/stats-extended.php`, find the `getSSLStatus()` function:
```php
$domains = ['example.com', 'www.example.com'];
```

## Security Hardening

### Enable Basic Authentication

1. Create password file:
```bash
sudo htpasswd -c /var/www/glitch-monitor/.htpasswd admin
```

2. Add to Apache config:
```apache
<Directory /var/www/glitch-monitor>
    AuthType Basic
    AuthName "gLiTcH-Monitor"
    AuthUserFile /var/www/glitch-monitor/.htpasswd
    Require valid-user
</Directory>
```

### Enable HTTPS

1. Generate self-signed cert:
```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/ssl/private/glitch-monitor.key \
  -out /etc/ssl/certs/glitch-monitor.crt
```

2. Update Apache config to use SSL.

## Troubleshooting

### Stats not loading?
- Check PHP errors: `tail -f /var/log/apache2/error.log`
- Verify sudoers: `sudo visudo -c`

### Fail2ban stats empty?
- Ensure fail2ban is running: `systemctl status fail2ban`
- Check jail exists: `sudo fail2ban-client status`
