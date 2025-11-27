# Installation Guide

## Quick Install (Recommended)

```bash
git clone https://github.com/glitchlinux/glitch-monitor.git
cd glitch-monitor
sudo python3 installer.py
```

## Manual Installation

### 1. Install Dependencies

**Debian/Ubuntu:**
```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php
```

**RHEL/CentOS:**
```bash
sudo dnf install httpd php php-common
sudo systemctl enable --now httpd
```

### 2. Copy Files

```bash
sudo mkdir -p /var/www/glitch-monitor
sudo cp src/* /var/www/glitch-monitor/
sudo chown -R www-data:www-data /var/www/glitch-monitor
```

### 3. Configure Apache

Create `/etc/apache2/sites-available/glitch-monitor.conf`:

```apache
Listen 127.0.0.1:8443

<VirtualHost 127.0.0.1:8443>
    DocumentRoot /var/www/glitch-monitor
    
    <Directory /var/www/glitch-monitor>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```

Enable the site:
```bash
sudo a2ensite glitch-monitor.conf
sudo systemctl reload apache2
```

### 4. Configure Sudoers

Create `/etc/sudoers.d/glitch-monitor`:
```
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u ssh *
```

### 5. Access

Open `http://localhost:8443/dashboard.html`

For remote access via SSH tunnel:
```bash
ssh -L 8443:localhost:8443 user@yourserver
```
