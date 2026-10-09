#!/bin/bash

set -e

echo "========================================================="
echo "       NEW PROJECT AUTOMATED DEPLOYMENT WIZARD"
echo "========================================================="

# ১. GitHub SSH Connection Check & Setup
echo -e "\n[1/5] Checking GitHub SSH connection..."
if ssh -T git@github.com 2>&1 | grep -q "successfully authenticated"; then
    echo "✔ GitHub is already connected via SSH!"
else
    echo "✖ No active GitHub SSH connection found."
    
    if [ ! -f ~/.ssh/id_rsa.pub ]; then
        echo "Generating new SSH key..."
        read -p "Enter your email for SSH key: " SSH_EMAIL
        ssh-keygen -t rsa -b 4096 -C "$SSH_EMAIL" -N "" -f ~/.ssh/id_rsa
    fi

    echo -e "\n--------------------------------------------------------- "
    echo "Copy the following Public Key and add it to GitHub (Settings -> SSH keys):"
    echo "--------------------------------------------------------- "
    cat ~/.ssh/id_rsa.pub
    echo "--------------------------------------------------------- "

    read -p "Press [Enter] once you have added the public key to GitHub..."
    
    while ! ssh -T git@github.com 2>&1 | grep -q "successfully authenticated"; do
        echo "Authentication failed or not completed yet."
        read -p "Press [Enter] to test the connection again..."
    done
    echo "✔ GitHub SSH connection established successfully!"
fi

# ২. Domain and Project Details Input
echo -e "\n[2/5] Project Configuration..."
read -p "Enter Project Domain (e.g., myproject.com): " PROJECT_DOMAIN
read -p "Enter GitHub Repository SSH URL (e.g., git@github.com:user/repo.git): " REPO_URL

# Web Server Engine Choice
read -p "Do you want to use FrankenPHP (Worker Mode) for this project? [y/N]: " USE_FRANKENPHP

if [[ ! "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    echo -e "\nAvailable PHP-FPM Versions on System:"
    ls /run/php/php*-fpm.sock 2>/dev/null | sed 's/\/run\/php\/php//g' | sed 's/-fpm.sock//g' || echo "8.4"
    read -p "Enter required PHP version for this project (default: 8.4): " PHP_VER
    PHP_VER=${PHP_VER:-8.4}
fi

# ৩. Clone Repository
PROJECT_DIR="/var/www/$PROJECT_DOMAIN"
echo -e "\n[3/5] Cloning repository into $PROJECT_DIR..."
if [ -d "$PROJECT_DIR" ]; then
    echo "Directory $PROJECT_DOMAIN already exists."
    read -p "Do you want to overwrite/re-clone? [y/N]: " OVERWRITE_REPO
    if [[ "$OVERWRITE_REPO" =~ ^[yY]$ ]]; then
        sudo rm -rf "$PROJECT_DIR"
        sudo git clone "$REPO_URL" "$PROJECT_DIR"
    else
        echo "Skipping git clone."
    fi
else
    sudo mkdir -p /var/www
    sudo git clone "$REPO_URL" "$PROJECT_DIR"
fi

sudo chown -R www-data:www-data "$PROJECT_DIR"

# ৪. Web Server & Caddy Configuration
echo -e "\n[4/5] Configuring Web Server and Caddy..."

# Ensure Caddyfile exists
if [ ! -f /etc/caddy/Caddyfile ]; then
    sudo touch /etc/caddy/Caddyfile
fi

# Make sure global on_demand_tls block exists at top of Caddyfile
if ! grep -q "on_demand_tls" /etc/caddy/Caddyfile; then
    echo "Adding global on_demand_tls configuration to Caddyfile..."
    TEMP_CADDY=$(mktemp)
    cat <<EOF > "$TEMP_CADDY"
{
    on_demand_tls {
        ask http://127.0.0.1:8001/api/caddy-check-domain
    }
}

https:// {
    tls {
        on_demand
    }
    root * /var/www/$PROJECT_DOMAIN/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}

EOF
    cat /etc/caddy/Caddyfile >> "$TEMP_CADDY"
    sudo mv "$TEMP_CADDY" /etc/caddy/Caddyfile
fi

# Caddyfile cleanup for existing duplicate domain blocks
if grep -q "$PROJECT_DOMAIN" /etc/caddy/Caddyfile 2>/dev/null; then
    echo "Cleaning up existing entry for $PROJECT_DOMAIN in Caddyfile..."
    sudo python3 -c "
import sys
domain = '$PROJECT_DOMAIN'
with open('/etc/caddy/Caddyfile', 'r') as f:
    lines = f.readlines()

new_lines = []
skip = False
for line in lines:
    if domain in line and '{' in line:
        skip = True
        continue
    if skip and '}' in line:
        skip = False
        continue
    if not skip:
        new_lines.append(line)

with open('/etc/caddy/Caddyfile', 'w') as f:
    f.writelines(new_lines)
"
fi

if [[ "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    if [ ! -f /usr/local/bin/frankenphp ]; then
        echo "Installing FrankenPHP binary..."
        sudo curl -o /usr/local/bin/frankenphp -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64
        sudo chmod +x /usr/local/bin/frankenphp
    fi

    # Unique Port Finder
    PORT=8001
    while lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null ; do
        PORT=$((PORT+1))
    done

    SERVICE_NAME="frankenphp-${PROJECT_DOMAIN//./-}"
    sudo bash -c "cat <<EOF > /etc/systemd/system/${SERVICE_NAME}.service
[Unit]
Description=FrankenPHP for $PROJECT_DOMAIN
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/local/bin/frankenphp php-server --listen :$PORT --root $PROJECT_DIR/public
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF"

    sudo systemctl daemon-reload
    sudo systemctl enable --now "$SERVICE_NAME"

    sudo bash -c "cat <<EOF >> /etc/caddy/Caddyfile

$PROJECT_DOMAIN, *.$PROJECT_DOMAIN {
    reverse_proxy http://127.0.0.1:$PORT {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }
}
EOF"

else
    # Standard Caddy + PHP-FPM Configuration
    SOCKET_FILE="/run/php/php${PHP_VER}-fpm.sock"
    if [ ! -S "$SOCKET_FILE" ]; then
        echo "Warning: Socket $SOCKET_FILE was not found. Please ensure php${PHP_VER}-fpm service is running."
    fi

    sudo bash -c "cat <<EOF >> /etc/caddy/Caddyfile

$PROJECT_DOMAIN, *.$PROJECT_DOMAIN {
    root * $PROJECT_DIR/public
    php_fastcgi unix//$SOCKET_FILE
    file_server
}
EOF"
fi

# Format and Validate Caddy Configuration
sudo caddy fmt --overwrite /etc/caddy/Caddyfile 2>/dev/null || true
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy

# ৫. Composer Install, Permissions & Laravel Setup
echo -e "\n[5/5] Setting up Laravel dependencies and permissions..."
if [ -f "$PROJECT_DIR/composer.json" ]; then
    cd "$PROJECT_DIR"
    sudo -u www-data composer install --ignore-platform-reqs --no-dev --optimize-autoloader
    if [ ! -f .env ] && [ -f .env.example ]; then
        sudo -u www-data cp .env.example .env
        sudo -u www-data php artisan key:generate
    fi
    
    # Complete Ownership and Permission fix for storage & cache
    sudo chown -R www-data:www-data "$PROJECT_DIR"
    sudo chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
fi

echo "========================================================="
echo "       PROJECT DEPLOYED SUCCESSFULLY!"
echo "========================================================="
echo "Domain: https://$PROJECT_DOMAIN"
echo "Directory: $PROJECT_DIR"
if [[ "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    echo "Mode: FrankenPHP (Running on Port $PORT)"
else
    echo "Mode: Standard PHP-FPM (PHP $PHP_VER)"
fi
echo "========================================================="
