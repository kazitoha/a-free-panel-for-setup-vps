#!/usr/bin/env bash

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib.sh
source "$SCRIPT_DIR/../lib.sh"
panel_init "${BASH_SOURCE[0]}"
require_tty

echo "========================================================="
echo "        NEW PROJECT AUTOMATED DEPLOYMENT WIZARD"
echo "========================================================="

# ১. GitHub SSH Connection Check & Setup
step "[1/6] Checking GitHub SSH connection..."

if ! github_ssh_ok; then
    warn "No GitHub SSH authentication found."

    if [ -f ~/.ssh/id_rsa.pub ]; then
        ok "Existing SSH key found — this key will be reused, not deleted."
    else
        ask SSH_EMAIL "Enter your email for a new SSH key"
        ssh-keygen -t rsa -b 4096 -C "$SSH_EMAIL" -N "" -f ~/.ssh/id_rsa
    fi

    echo "---------------------------------------------------------"
    echo "Add this Public Key to GitHub (Settings -> SSH keys):"
    echo "---------------------------------------------------------"
    cat ~/.ssh/id_rsa.pub
    echo "---------------------------------------------------------"

    while true; do
        pause "Press [Enter] once the key is added to GitHub to test the connection..."
        github_ssh_ok && break
        warn "Still not authenticated — make sure the key above is saved on GitHub."
    done
fi

ok "GitHub SSH connection established."

# ২. Domain and Project Details Input
step "[2/6] Project Configuration..."
ask PROJECT_DOMAIN "Enter Project Domain (e.g., myproject.com)"
ask REPO_URL "Enter GitHub Repository SSH URL (e.g., git@github.com:user/repo.git)"

# Web Server Engine Choice
ask USE_FRANKENPHP "Do you want to use FrankenPHP (Worker Mode) for this project? [y/N]" "n"

if [[ ! "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    echo
    echo "Available PHP-FPM Versions on System:"
    PHP_SOCKETS="$(ls /run/php/php*-fpm.sock 2>/dev/null | sed 's|/run/php/php||g; s|-fpm.sock||g' || true)"
    if [[ -n "$PHP_SOCKETS" ]]; then
        echo "$PHP_SOCKETS"
    else
        warn "No PHP-FPM socket found in /run/php — install a PHP version first if the one you need is missing."
    fi
    ask PHP_VER "Enter required PHP version for this project" "8.4"
fi

# ৩. Clone Repository
PROJECT_DIR="/var/www/$PROJECT_DOMAIN"
step "[3/6] Cloning repository into $PROJECT_DIR..."
if [ -d "$PROJECT_DIR" ]; then
    warn "Directory $PROJECT_DOMAIN already exists."
    ask OVERWRITE_REPO "Do you want to overwrite/re-clone? [y/N]" "n"
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
ok "Project files in place at $PROJECT_DIR"

sudo chown -R www-data:www-data "$PROJECT_DIR"

# ৩.১. Environment Setup
step "[3.1/6] Setting up .env file..."
cd "$PROJECT_DIR"
if [ ! -f .env ] && [ -f .env.example ]; then
    sudo -u www-data cp .env.example .env
    ok ".env file created from .env.example"
fi

echo "---------------------------------------------------------"
echo "IMPORTANT: Please configure your Database credentials in .env now!"
echo "File path: $PROJECT_DIR/.env"
echo "---------------------------------------------------------"
pause "Press [Enter] after you have updated your .env file with correct database info... "

# ৪. Web Server & Caddy Configuration
step "[4/6] Configuring Web Server and Caddy..."

if [ ! -f /etc/caddy/Caddyfile ]; then
    sudo touch /etc/caddy/Caddyfile
fi

# Ensure global on_demand_tls block exists at top
if ! grep -q "on_demand_tls" /etc/caddy/Caddyfile; then
    echo "Adding global on_demand_tls configuration to Caddyfile..."
    TEMP_CADDY=$(mktemp)
    cat <<EOF > "$TEMP_CADDY"
{
    on_demand_tls {
        ask http://127.0.0.1:8000/api/caddy-check-domain
    }
}
EOF
    cat /etc/caddy/Caddyfile >> "$TEMP_CADDY"
    sudo mv "$TEMP_CADDY" /etc/caddy/Caddyfile
fi

# The site block below imports the `site_defaults` snippet. Without this
# definition `caddy validate` fails and the whole script stops.
if ! grep -q "(site_defaults)" /etc/caddy/Caddyfile; then
    step "Adding the shared site_defaults snippet to Caddyfile..."
    sudo tee -a /etc/caddy/Caddyfile > /dev/null <<'EOF'

(site_defaults) {
    tls {
        on_demand
    }
    encode zstd br gzip
}
EOF
fi

# Clean existing domain blocks safely
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
    if skip and line.strip() == '}':
        skip = False
        continue
    if not skip:
        new_lines.append(line)

with open('/etc/caddy/Caddyfile', 'w') as f:
    f.writelines(new_lines)
"
fi

# Append domain configuration
if [[ "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    if [ ! -f /usr/local/bin/frankenphp ]; then
        FRANKEN_TMP="$(mktemp)"
        download "https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64" "$FRANKEN_TMP"
        # A failed download used to be chmod'ed +x and installed as a broken
        # binary — refuse anything that is not an ELF executable.
        if [[ "$(head -c 4 "$FRANKEN_TMP")" != $'\x7fELF' ]]; then
            rm -f "$FRANKEN_TMP"
            fail "Downloaded FrankenPHP file is not a Linux executable."
        fi
        sudo install -m 755 "$FRANKEN_TMP" /usr/local/bin/frankenphp
        rm -f "$FRANKEN_TMP"
        ok "FrankenPHP installed at /usr/local/bin/frankenphp"
    fi

    PORT=8002
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
    tls {
        on_demand
    }
    reverse_proxy http://127.0.0.1:$PORT {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }
}
EOF"

else
    SOCKET_FILE="/run/php/php${PHP_VER}-fpm.sock"
    if [ ! -S "$SOCKET_FILE" ]; then
        warn "Socket $SOCKET_FILE was not found — make sure php${PHP_VER}-fpm is running."
    fi

    # স্ট্যান্ডার্ড PHP-FPM মোডে প্রজেক্ট যোগ করার সময় স্নিপেট ইমপোর্ট করবে
    sudo bash -c "cat <<EOF >> /etc/caddy/Caddyfile

$PROJECT_DOMAIN, *.$PROJECT_DOMAIN {
    import site_defaults
    root * $PROJECT_DIR/public
    php_fastcgi unix//$SOCKET_FILE
    file_server
}
EOF"
fi

sudo caddy fmt --overwrite /etc/caddy/Caddyfile 2>/dev/null || true
step "Validating Caddy configuration..."
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
ok "Caddy configuration applied and reloaded."

# ৫. Composer Install, Key Generate & Migrations
step "[5/6] Setting up Laravel dependencies, key generation, and migrations..."
if [ -f "$PROJECT_DIR/composer.json" ]; then
    cd "$PROJECT_DIR"
    sudo -u www-data composer install --ignore-platform-reqs --no-dev --optimize-autoloader

    if ! grep -q "APP_KEY=base64" .env; then
        sudo -u www-data php artisan key:generate
    fi

    sudo -u www-data php artisan migrate --force

    sudo chown -R www-data:www-data "$PROJECT_DIR"
    sudo chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    ok "Composer install, application key and migrations done."
else
    warn "No composer.json in $PROJECT_DIR — skipping dependency install."
fi

# ৬. Laravel Queue Worker Setup
step "[6/6] Setting up Laravel Queue Worker..."
QUEUE_SERVICE_NAME="laravel-queue-${PROJECT_DOMAIN//./-}"
sudo bash -c "cat <<EOF > /etc/systemd/system/${QUEUE_SERVICE_NAME}.service
[Unit]
Description=Laravel Queue Worker for $PROJECT_DOMAIN
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php $PROJECT_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF"

sudo systemctl daemon-reload
sudo systemctl enable --now "$QUEUE_SERVICE_NAME"

echo "========================================================="
echo "        PROJECT DEPLOYED SUCCESSFULLY!"
echo "========================================================="
echo "Domain: https://$PROJECT_DOMAIN"
echo "Directory: $PROJECT_DIR"
if [[ "$USE_FRANKENPHP" =~ ^[yY]$ ]]; then
    echo "Mode: FrankenPHP (Running on Port $PORT)"
else
    echo "Mode: Standard PHP-FPM (PHP $PHP_VER)"
fi
echo "Queue Service: ${QUEUE_SERVICE_NAME} (Active & Running)"
echo "========================================================="
