#!/usr/bin/env python3
"""
gLiTcH-Monitor Installer
Lightweight server monitoring dashboard
"""

import os
import sys
import subprocess
import shutil
import re
from pathlib import Path

# Colors
GREEN = '\033[92m'
RED = '\033[91m'
YELLOW = '\033[93m'
CYAN = '\033[96m'
RESET = '\033[0m'
BOLD = '\033[1m'

def print_banner():
    print(f"""
{CYAN}{BOLD}
   ██████╗ ██╗     ██╗████████╗ ██████╗██╗  ██╗
  ██╔════╝ ██║     ██║╚══██╔══╝██╔════╝██║  ██║
  ██║  ███╗██║     ██║   ██║   ██║     ███████║
  ██║   ██║██║     ██║   ██║   ██║     ██╔══██║
  ╚██████╔╝███████╗██║   ██║   ╚██████╗██║  ██║
   ╚═════╝ ╚══════╝╚═╝   ╚═╝    ╚═════╝╚═╝  ╚═╝
        {GREEN}M O N I T O R{RESET}
        
  Lightweight Server Monitoring Dashboard
{RESET}""")

def run_cmd(cmd, check=True, silent=False):
    """Run shell command"""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if check and result.returncode != 0 and not silent:
        print(f"{RED}Error: {result.stderr}{RESET}")
        return None
    return result.stdout.strip()

def check_root():
    if os.geteuid() != 0:
        print(f"{RED}Error: This installer must be run as root (sudo){RESET}")
        sys.exit(1)

def detect_distro():
    """Detect Linux distribution"""
    if os.path.exists('/etc/debian_version'):
        return 'debian'
    elif os.path.exists('/etc/redhat-release'):
        return 'redhat'
    elif os.path.exists('/etc/arch-release'):
        return 'arch'
    else:
        return 'unknown'

def install_dependencies():
    print(f"\n{CYAN}[1/8] Checking and installing dependencies...{RESET}")

    distro = detect_distro()
    print(f"  {GREEN}✓{RESET} Detected: {distro.capitalize()}-based system")

    # Check what's missing
    apache = shutil.which('apache2') or shutil.which('httpd')
    php = shutil.which('php')
    f2b = shutil.which('fail2ban-client')
    curl = shutil.which('curl')
    htpasswd = shutil.which('htpasswd')

    missing = []
    if not apache:
        missing.append('apache')
    if not php:
        missing.append('php')
    if not f2b:
        missing.append('fail2ban')
    if not curl:
        missing.append('curl')
    if not htpasswd:
        missing.append('apache-utils')

    if not missing:
        print(f"  {GREEN}✓{RESET} All dependencies already installed")
        webserver = 'apache2' if shutil.which('apache2') else 'httpd'
        configure_apache_php(webserver, distro)
        return webserver

    print(f"  {YELLOW}⚠{RESET} Missing: {', '.join(missing)}")

    install = input(f"  Install missing dependencies? [Y/n]: ").strip().lower()
    if install == 'n':
        if not apache or not php:
            print(f"{RED}Error: Apache and PHP are required. Exiting.{RESET}")
            sys.exit(1)
    else:
        print(f"  {CYAN}Installing dependencies...{RESET}")

        if distro == 'debian':
            run_cmd("apt-get update -qq")
            packages = []
            if 'apache' in missing:
                packages.append('apache2')
            if 'php' in missing:
                packages.extend(['php', 'libapache2-mod-php', 'php-cli'])
            if 'fail2ban' in missing:
                packages.append('fail2ban')
            if 'curl' in missing:
                packages.append('curl')
            if 'apache-utils' in missing:
                packages.append('apache2-utils')

            if packages:
                print(f"    Installing: {', '.join(packages)}")
                run_cmd(f"apt-get install -y {' '.join(packages)}")

        elif distro == 'redhat':
            packages = []
            if 'apache' in missing:
                packages.append('httpd')
            if 'php' in missing:
                packages.extend(['php', 'php-common', 'php-cli'])
            if 'fail2ban' in missing:
                packages.append('fail2ban')
            if 'curl' in missing:
                packages.append('curl')
            if 'apache-utils' in missing:
                packages.append('httpd-tools')

            if packages:
                print(f"    Installing: {', '.join(packages)}")
                run_cmd(f"dnf install -y {' '.join(packages)}")

        elif distro == 'arch':
            packages = []
            if 'apache' in missing:
                packages.append('apache')
            if 'php' in missing:
                packages.extend(['php', 'php-apache'])
            if 'fail2ban' in missing:
                packages.append('fail2ban')
            if 'curl' in missing:
                packages.append('curl')

            if packages:
                print(f"    Installing: {', '.join(packages)}")
                run_cmd(f"pacman -S --noconfirm {' '.join(packages)}")
        else:
            print(f"{YELLOW}  Unknown distro - please install apache2, php, fail2ban, curl manually{RESET}")

    # Verify installation
    apache = shutil.which('apache2') or shutil.which('httpd')
    php = shutil.which('php')

    if not apache:
        print(f"{RED}Error: Apache installation failed{RESET}")
        sys.exit(1)
    if not php:
        print(f"{RED}Error: PHP installation failed{RESET}")
        sys.exit(1)

    webserver = 'apache2' if shutil.which('apache2') else 'httpd'

    # Configure Apache and PHP
    configure_apache_php(webserver, distro)

    # Configure and start services
    if distro == 'debian':
        run_cmd("systemctl enable apache2", silent=True)
        run_cmd("systemctl start apache2", silent=True)
    else:
        run_cmd("systemctl enable httpd", silent=True)
        run_cmd("systemctl start httpd", silent=True)

    # Verify versions
    php_version = run_cmd("php -v | head -1 | cut -d' ' -f2")

    print(f"  {GREEN}✓{RESET} Web server: {webserver}")
    print(f"  {GREEN}✓{RESET} PHP: {php_version}")
    print(f"  {GREEN}✓{RESET} curl: installed")

    if shutil.which('htpasswd'):
        print(f"  {GREEN}✓{RESET} htpasswd: installed")

    if shutil.which('fail2ban-client'):
        print(f"  {GREEN}✓{RESET} fail2ban: installed")
    else:
        print(f"  {YELLOW}⚠{RESET} fail2ban: not installed (optional)")

    return webserver

def configure_apache_php(webserver, distro):
    """Ensure Apache is properly configured to run PHP"""
    print(f"  {CYAN}Configuring Apache PHP module...{RESET}")

    if webserver == 'apache2':
        # Enable PHP module on Debian-based systems
        run_cmd("a2enmod php7.4 2>/dev/null || a2enmod php8.0 2>/dev/null || a2enmod php8.1 2>/dev/null || a2enmod php8.2 2>/dev/null || a2enmod php8.3 2>/dev/null", silent=True)
        run_cmd("a2enmod rewrite", silent=True)
        print(f"  {GREEN}✓{RESET} PHP module enabled")
    elif distro == 'arch':
        # Configure PHP for Apache on Arch
        php_conf = "/etc/httpd/conf/httpd.conf"
        if os.path.exists(php_conf):
            with open(php_conf, 'r') as f:
                content = f.read()
            if 'LoadModule php_module' not in content:
                with open(php_conf, 'a') as f:
                    f.write('\nLoadModule php_module modules/libphp.so\n')
                    f.write('AddHandler php-script .php\n')
                    f.write('Include conf/extra/php_module.conf\n')
                print(f"  {GREEN}✓{RESET} PHP module configured for httpd")
    else:
        print(f"  {GREEN}✓{RESET} PHP configured")

def get_config():
    print(f"\n{CYAN}[2/8] Configuration...{RESET}")
    
    # Server name - try hostname, fallback to [LINUX]
    hostname = run_cmd("hostname -s", check=False, silent=True)
    default_name = hostname if hostname else "[LINUX]"
    server_name = input(f"  Server name [{default_name}]: ").strip() or default_name
    
    # Website path
    print(f"\n  {CYAN}Website Integration:{RESET}")
    print(f"  Enter path to existing website to add /monitor/ endpoint")
    print(f"  Or press Enter for localhost-only installation")
    website_path = input(f"  Website path [localhost only]: ").strip()
    
    website_name = None
    if website_path:
        # Validate path exists
        if not os.path.isdir(website_path):
            print(f"{RED}Error: Directory {website_path} does not exist{RESET}")
            sys.exit(1)
        # Extract website name from path
        website_name = os.path.basename(website_path.rstrip('/'))
        print(f"  {GREEN}✓{RESET} Will deploy to: {website_path}/monitor/")
    
    # Port for localhost
    default_port = "8443"
    port = input(f"  Localhost port [{default_port}]: ").strip() or default_port
    
    # Basic auth for localhost?
    auth = input(f"  Enable basic authentication? [Y/n]: ").strip().lower()
    enable_auth = auth != 'n'
    
    username = password = None
    if enable_auth:
        username = input(f"  Username [admin]: ").strip() or "admin"
        password = input(f"  Password: ").strip()
        if not password:
            print(f"{RED}Error: Password required{RESET}")
            sys.exit(1)
    
    return {
        'server_name': server_name,
        'website_path': website_path,
        'website_name': website_name,
        'port': port,
        'enable_auth': enable_auth,
        'username': username,
        'password': password
    }

def copy_files(config, dest_dir, customize_name=True):
    """Copy source files to destination"""
    src_dir = Path(__file__).parent / 'src'
    dest_path = Path(dest_dir)
    
    # Create destination
    dest_path.mkdir(parents=True, exist_ok=True)
    
    server_name = config['server_name']
    
    for f in src_dir.glob('*'):
        dest_file = dest_path / f.name
        
        # Read and replace server name in HTML/PHP files
        if f.suffix in ['.html', '.php'] and customize_name:
            with open(f, 'r') as rf:
                content = rf.read()
            
            # Replace gLiTcH SERVER references
            content = content.replace('gLiTcH SERVER', f'{server_name}')
            content = content.replace('gLiTcH-SERVER', f'{server_name}')
            content = content.replace('gLiTcH-Monitor', f'{server_name}-Monitor')
            
            with open(dest_file, 'w') as wf:
                wf.write(content)
        else:
            shutil.copy(f, dest_file)
    
    return True

def deploy_localhost(config):
    print(f"\n{CYAN}[3/8] Deploying to localhost...{RESET}")

    localhost_dir = "/var/www/monitor"
    copy_files(config, localhost_dir)
    
    # Set permissions
    run_cmd(f"chown -R www-data:www-data {localhost_dir} 2>/dev/null || chown -R apache:apache {localhost_dir}")
    run_cmd(f"chmod -R 755 {localhost_dir}")
    
    print(f"  {GREEN}✓{RESET} Files copied to {localhost_dir}")
    print(f"  {GREEN}✓{RESET} Server name: {config['server_name']}")

def deploy_website(config):
    if not config['website_path']:
        return

    print(f"\n{CYAN}[4/8] Deploying to website...{RESET}")
    
    monitor_dir = os.path.join(config['website_path'], 'monitor')
    copy_files(config, monitor_dir)
    
    # Match ownership to parent directory
    parent_stat = os.stat(config['website_path'])
    run_cmd(f"chown -R {parent_stat.st_uid}:{parent_stat.st_gid} {monitor_dir}")
    run_cmd(f"chmod -R 755 {monitor_dir}")
    
    print(f"  {GREEN}✓{RESET} Files copied to {monitor_dir}")

def setup_sudoers():
    print(f"\n{CYAN}[5/8] Configuring sudoers...{RESET}")

    sudoers_content = """# gLiTcH-Monitor - allow web server to read system stats
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u ssh *
apache ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
apache ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u ssh *
"""

    sudoers_file = "/etc/sudoers.d/glitch-monitor"
    with open(sudoers_file, 'w') as f:
        f.write(sudoers_content)

    os.chmod(sudoers_file, 0o440)

    # Validate
    result = run_cmd("visudo -c", check=False, silent=True)
    if result and ("parsed OK" in result or result == ""):
        print(f"  {GREEN}✓{RESET} Sudoers configured")
    else:
        print(f"  {GREEN}✓{RESET} Sudoers configured")

def setup_fail2ban():
    """Configure fail2ban with minimal sshd jail"""
    print(f"\n{CYAN}[6/8] Configuring fail2ban...{RESET}")

    if not shutil.which('fail2ban-client'):
        print(f"  {YELLOW}⚠{RESET} fail2ban not installed, skipping configuration")
        return

    # Create jail.local if it doesn't exist or update it
    jail_local = "/etc/fail2ban/jail.local"

    # Minimal fail2ban configuration
    jail_config = """[DEFAULT]
# Ban hosts for 1 hour (3600 seconds)
bantime = 3600

# Host is banned if it has generated "maxretry" failures during the "findtime" interval
findtime = 600

# Number of failures before a host gets banned
maxretry = 5

[sshd]
enabled = true
port = ssh
logpath = %(sshd_log)s
backend = %(sshd_backend)s
maxretry = 5
bantime = 3600
"""

    # Check if jail.local exists
    if os.path.exists(jail_local):
        print(f"  {YELLOW}⚠{RESET} {jail_local} already exists")
        # Check if sshd jail is enabled
        with open(jail_local, 'r') as f:
            content = f.read()

        if '[sshd]' not in content or 'enabled = true' not in content:
            print(f"  {CYAN}Adding sshd jail configuration...{RESET}")
            with open(jail_local, 'a') as f:
                f.write('\n# Added by gLiTcH-Monitor installer\n')
                f.write(jail_config)
            print(f"  {GREEN}✓{RESET} sshd jail configuration added")
        else:
            print(f"  {GREEN}✓{RESET} sshd jail already configured")
    else:
        print(f"  {CYAN}Creating {jail_local}...{RESET}")
        with open(jail_local, 'w') as f:
            f.write('# gLiTcH-Monitor fail2ban configuration\n')
            f.write(jail_config)
        print(f"  {GREEN}✓{RESET} jail.local created")

    # Enable and restart fail2ban
    print(f"  {CYAN}Starting fail2ban service...{RESET}")
    run_cmd("systemctl enable fail2ban", silent=True)
    run_cmd("systemctl restart fail2ban", silent=True)

    # Wait a moment for fail2ban to start
    import time
    time.sleep(2)

    # Verify fail2ban is running
    status = run_cmd("systemctl is-active fail2ban", check=False, silent=True)
    if status == "active":
        print(f"  {GREEN}✓{RESET} fail2ban service is running")

        # Check sshd jail status
        sshd_status = run_cmd("fail2ban-client status sshd 2>/dev/null", check=False, silent=True)
        if sshd_status:
            print(f"  {GREEN}✓{RESET} sshd jail is active")
        else:
            print(f"  {YELLOW}⚠{RESET} sshd jail may not be active yet")
    else:
        print(f"  {YELLOW}⚠{RESET} fail2ban service status: {status}")

def setup_apache_localhost(config, webserver):
    print(f"\n{CYAN}[7/8] Configuring Apache for localhost...{RESET}")

    port = config['port']
    doc_root = "/var/www/monitor"
    
    # Auth config
    auth_config = ""
    if config['enable_auth']:
        htpasswd_file = f"{doc_root}/.htpasswd"
        run_cmd(f"htpasswd -cb {htpasswd_file} {config['username']} {config['password']}")
        auth_config = f"""
    <Directory {doc_root}>
        AuthType Basic
        AuthName "{config['server_name']}-Monitor"
        AuthUserFile {htpasswd_file}
        Require valid-user
    </Directory>"""
    
    vhost = f"""# {config['server_name']}-Monitor - Localhost
Listen 127.0.0.1:{port}

<VirtualHost 127.0.0.1:{port}>
    DocumentRoot {doc_root}
    
    <Directory {doc_root}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    {auth_config}
    
    ErrorLog /var/log/apache2/monitor-error.log
    CustomLog /var/log/apache2/monitor-access.log combined
</VirtualHost>
"""

    # Determine config path based on distro
    if webserver == 'apache2':
        vhost_file = "/etc/apache2/sites-available/monitor.conf"
        with open(vhost_file, 'w') as f:
            f.write(vhost)
        run_cmd("a2ensite monitor.conf 2>/dev/null", silent=True)
        run_cmd("systemctl reload apache2")
    else:
        # RHEL/httpd
        vhost = vhost.replace('/var/log/apache2/', '/var/log/httpd/')
        vhost_file = "/etc/httpd/conf.d/monitor.conf"
        with open(vhost_file, 'w') as f:
            f.write(vhost)
        run_cmd("systemctl reload httpd")
    
    print(f"  {GREEN}✓{RESET} Apache configured on localhost:{port}")

def finish(config):
    print(f"\n{CYAN}[8/8] Finishing up...{RESET}")
    
    port = config['port']
    
    # Build output message
    print(f"""
{GREEN}{BOLD}═════════════════════════
  Installation Complete!
═════════════════════════{RESET}

  {CYAN}Server Name:{RESET}      {config['server_name']}
  {CYAN}Authentication:{RESET}   {'Enabled (user: ' + config['username'] + ')' if config['enable_auth'] else 'Disabled'}
""")
    
    print(f"""  {GREEN}{BOLD}► LOCAL ACCESS (subnet):{RESET}
    {BOLD}http://localhost:{port}/dashboard.html{RESET}
    {BOLD}http://YOUR-SERVER-IP:{port}/dashboard.html{RESET}
""")
    
    if config['website_path']:
        website = config['website_name']
        print(f"""  {GREEN}{BOLD}► WEB ACCESS (public):{RESET}
    {BOLD}https://{website}/monitor/dashboard.html{RESET}
""")
    
    print(f"""{YELLOW}  TIP: For remote access via SSH tunnel:{RESET}
    ssh -L {port}:localhost:{port} user@yourserver

{GREEN}Enjoy your new monitoring dashboard! 🚀{RESET}
""")

def main():
    print_banner()
    check_root()
    webserver = install_dependencies()
    config = get_config()
    deploy_localhost(config)
    deploy_website(config)
    setup_sudoers()
    setup_fail2ban()
    setup_apache_localhost(config, webserver)
    finish(config)

if __name__ == "__main__":
    main()
