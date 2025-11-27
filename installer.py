#!/usr/bin/env python3
"""
gLiTcH-Monitor Installer
Lightweight server monitoring dashboard
"""

import os
import sys
import subprocess
import shutil
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

def run_cmd(cmd, check=True):
    """Run shell command"""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if check and result.returncode != 0:
        print(f"{RED}Error: {result.stderr}{RESET}")
        return None
    return result.stdout.strip()

def check_root():
    if os.geteuid() != 0:
        print(f"{RED}Error: This installer must be run as root (sudo){RESET}")
        sys.exit(1)

def check_requirements():
    print(f"\n{CYAN}[1/6] Checking requirements...{RESET}")
    
    # Check for web server
    apache = shutil.which('apache2') or shutil.which('httpd')
    nginx = shutil.which('nginx')
    
    if not apache and not nginx:
        print(f"{RED}Error: No web server found. Install apache2 or nginx first.{RESET}")
        sys.exit(1)
    
    webserver = 'apache2' if apache else 'nginx'
    print(f"  {GREEN}✓{RESET} Web server: {webserver}")
    
    # Check PHP
    php = shutil.which('php')
    if not php:
        print(f"{RED}Error: PHP not found. Install php first.{RESET}")
        sys.exit(1)
    
    php_version = run_cmd("php -v | head -1 | cut -d' ' -f2")
    print(f"  {GREEN}✓{RESET} PHP: {php_version}")
    
    # Check fail2ban (optional)
    f2b = shutil.which('fail2ban-client')
    if f2b:
        print(f"  {GREEN}✓{RESET} fail2ban: installed")
    else:
        print(f"  {YELLOW}⚠{RESET} fail2ban: not found (optional)")
    
    return webserver

def get_config():
    print(f"\n{CYAN}[2/6] Configuration...{RESET}")
    
    # Install directory
    default_dir = "/var/www/glitch-monitor"
    install_dir = input(f"  Install directory [{default_dir}]: ").strip() or default_dir
    
    # Port
    default_port = "8443"
    port = input(f"  Port [{default_port}]: ").strip() or default_port
    
    # Localhost only?
    localhost = input(f"  Bind to localhost only? [Y/n]: ").strip().lower()
    localhost_only = localhost != 'n'
    
    # Basic auth?
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
        'install_dir': install_dir,
        'port': port,
        'localhost_only': localhost_only,
        'enable_auth': enable_auth,
        'username': username,
        'password': password
    }

def copy_files(config):
    print(f"\n{CYAN}[3/6] Copying files...{RESET}")
    
    src_dir = Path(__file__).parent / 'src'
    dest_dir = Path(config['install_dir'])
    
    # Create destination
    dest_dir.mkdir(parents=True, exist_ok=True)
    
    # Copy files
    for f in src_dir.glob('*'):
        shutil.copy(f, dest_dir)
        print(f"  {GREEN}✓{RESET} Copied {f.name}")
    
    # Set permissions
    run_cmd(f"chown -R www-data:www-data {dest_dir}")
    run_cmd(f"chmod -R 755 {dest_dir}")
    
    print(f"  {GREEN}✓{RESET} Permissions set")

def setup_sudoers():
    print(f"\n{CYAN}[4/6] Configuring sudoers...{RESET}")
    
    sudoers_content = """# gLiTcH-Monitor - allow www-data to read system stats
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u ssh *
"""
    
    sudoers_file = "/etc/sudoers.d/glitch-monitor"
    with open(sudoers_file, 'w') as f:
        f.write(sudoers_content)
    
    os.chmod(sudoers_file, 0o440)
    
    # Validate
    result = run_cmd("visudo -c", check=False)
    if "parsed OK" in result or result == "":
        print(f"  {GREEN}✓{RESET} Sudoers configured")
    else:
        print(f"  {RED}✗{RESET} Sudoers validation failed")

def setup_apache(config):
    print(f"\n{CYAN}[5/6] Configuring Apache...{RESET}")
    
    listen_addr = "127.0.0.1" if config['localhost_only'] else "*"
    port = config['port']
    doc_root = config['install_dir']
    
    # Auth config
    auth_config = ""
    if config['enable_auth']:
        htpasswd_file = f"{doc_root}/.htpasswd"
        run_cmd(f"htpasswd -cb {htpasswd_file} {config['username']} {config['password']}")
        auth_config = f"""
    <Directory {doc_root}>
        AuthType Basic
        AuthName "gLiTcH-Monitor"
        AuthUserFile {htpasswd_file}
        Require valid-user
    </Directory>"""
    
    vhost = f"""# gLiTcH-Monitor
Listen {listen_addr}:{port}

<VirtualHost {listen_addr}:{port}>
    DocumentRoot {doc_root}
    
    <Directory {doc_root}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    {auth_config}
    
    ErrorLog ${{APACHE_LOG_DIR}}/glitch-monitor-error.log
    CustomLog ${{APACHE_LOG_DIR}}/glitch-monitor-access.log combined
</VirtualHost>
"""
    
    vhost_file = "/etc/apache2/sites-available/glitch-monitor.conf"
    with open(vhost_file, 'w') as f:
        f.write(vhost)
    
    # Enable site
    run_cmd("a2ensite glitch-monitor.conf")
    run_cmd("systemctl reload apache2")
    
    print(f"  {GREEN}✓{RESET} Apache configured on port {port}")

def setup_nginx(config):
    print(f"\n{CYAN}[5/6] Configuring Nginx...{RESET}")
    
    listen_addr = "127.0.0.1" if config['localhost_only'] else ""
    port = config['port']
    doc_root = config['install_dir']
    
    # Auth config
    auth_config = ""
    if config['enable_auth']:
        htpasswd_file = f"{doc_root}/.htpasswd"
        run_cmd(f"htpasswd -cb {htpasswd_file} {config['username']} {config['password']}")
        auth_config = f"""
        auth_basic "gLiTcH-Monitor";
        auth_basic_user_file {htpasswd_file};"""
    
    server_block = f"""# gLiTcH-Monitor
server {{
    listen {listen_addr}:{port};
    root {doc_root};
    index dashboard.html;
    {auth_config}
    
    location ~ \\.php$ {{
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
    }}
}}
"""
    
    conf_file = "/etc/nginx/sites-available/glitch-monitor"
    with open(conf_file, 'w') as f:
        f.write(server_block)
    
    # Enable site
    run_cmd(f"ln -sf {conf_file} /etc/nginx/sites-enabled/")
    run_cmd("systemctl reload nginx")
    
    print(f"  {GREEN}✓{RESET} Nginx configured on port {port}")

def finish(config):
    print(f"\n{CYAN}[6/6] Finishing up...{RESET}")
    
    addr = "localhost" if config['localhost_only'] else "0.0.0.0"
    port = config['port']
    
    print(f"""
{GREEN}{BOLD}═══════════════════════════════════════════════════════════════
  Installation Complete!
═══════════════════════════════════════════════════════════════{RESET}

  {CYAN}Access URL:{RESET}    http://{addr}:{port}/dashboard.html
  {CYAN}Install Dir:{RESET}   {config['install_dir']}
  {CYAN}Auth:{RESET}          {'Enabled' if config['enable_auth'] else 'Disabled'}

{YELLOW}If binding to localhost, use SSH tunnel for remote access:{RESET}
  ssh -L {port}:localhost:{port} user@yourserver

{GREEN}Enjoy your new monitoring dashboard! 🚀{RESET}
""")

def main():
    print_banner()
    check_root()
    webserver = check_requirements()
    config = get_config()
    copy_files(config)
    setup_sudoers()
    
    if webserver == 'apache2':
        setup_apache(config)
    else:
        setup_nginx(config)
    
    finish(config)

if __name__ == "__main__":
    main()
