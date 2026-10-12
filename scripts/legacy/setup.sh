#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib.sh
source "$SCRIPT_DIR/../lib.sh"
panel_init "${BASH_SOURCE[0]}"
require_tty

# ফাংশন: অপশন চেক এবং ইউজারের সিদ্ধান্ত নেওয়ার জন্য
ask_skip_or_overwrite() {
    local item_name="$1" choice=""
    echo -e "\n\e[33m[CHECK]\e[0m '$item_name' is already installed or configured."
    ask choice "Do you want to (s)kip or (o)verwrite/reinstall? [s/o]" "s"
    case "$choice" in
        o|O ) return 0 ;; # Overwrite / Reinstall
        * ) return 1 ;;   # Skip by default
    esac
}

echo "========================================================="
ask_secret MYSQL_ROOT_PASSWORD "Enter MySQL Root Password"
ask_secret MYSQL_ROOT_PASSWORD_CONFIRM "Confirm MySQL Root Password"
echo "========================================================="

if [ "$MYSQL_ROOT_PASSWORD" != "$MYSQL_ROOT_PASSWORD_CONFIRM" ]; then
    fail "Passwords do not match!"
fi

echo -e "\n=== System Update and Installing Essentials ==="

# পুরোনো যেকোনো ভাঙা caddy repo বা list ফাইল সরিয়ে নেওয়া
sudo rm -f /etc/apt/sources.list.d/caddy-*.list

sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl wget zip unzip git ufw

# PHP Repository Add
if ! grep -q "^deb .*ppa:ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null; then
    echo -e "\n=== Adding Ondrej PHP Repository ==="
    sudo add-apt-repository ppa:ondrej/php -y
    sudo apt update
fi

# PHP Versions Check & Install
PHP_VERSIONS=("7.4" "8.1" "8.2" "8.3" "8.4")
for v in "${PHP_VERSIONS[@]}"; do
    if dpkg -l | grep -q "php$v-fpm"; then
        if ask_skip_or_overwrite "PHP $v"; then
            echo "Reinstalling PHP $v..."
            sudo apt install --reinstall -y php$v-fpm php$v-cli php$v-mysql php$v-curl php$v-gd php$v-mbstring php$v-xml php$v-zip php$v-intl php$v-sqlite3
        else
            echo "Skipping PHP $v installation."
        fi
    else
        echo "Installing PHP $v..."
        sudo apt install -y php$v-fpm php$v-cli php$v-mysql php$v-curl php$v-gd php$v-mbstring php$v-xml php$v-zip php$v-intl php$v-sqlite3
    fi
    sudo systemctl enable php$v-fpm
    sudo systemctl start php$v-fpm
done

# Caddy Check & Install via Direct .deb Package (Bypasses Cloudsmith 402 Error)
if command -v caddy &> /dev/null; then
    if ask_skip_or_overwrite "Caddy Web Server"; then
        INSTALL_CADDY=true
    else
        INSTALL_CADDY=false
    fi
else
    INSTALL_CADDY=true
fi

if [ "$INSTALL_CADDY" = true ]; then
    step "Installing/Reinstalling Caddy Web Server (Direct .deb)"

    # পুরোনো ভাঙা রিপোজিটরি ফাইল থাকলে মুছে ফেলা
    sudo rm -f /etc/apt/sources.list.d/caddy-*.list

    # গিটহাব থেকে লেটেস্ট লিনাক্স এএমডি৬৪ ডিবি ফাইল ডাউনলোড করা।
    # API rate-limit বা নেটওয়ার্ক সমস্যায় আগে wget -qi - চুপচাপ কিছু
    # ডাউনলোড করত না, তারপর apt ব্যর্থ হয়ে স্ক্রিপ্ট নীরবে থেমে যেত।
    CADDY_DEB_URL="$(curl -fsSL https://api.github.com/repos/caddyserver/caddy/releases/latest \
        | grep 'browser_download_url.*linux_amd64.deb' \
        | head -1 \
        | cut -d '"' -f 4)"

    if [[ -z "$CADDY_DEB_URL" ]]; then
        fail "Could not find the latest Caddy .deb URL from the GitHub API.
       (Rate limit or network problem — wait a minute and re-run.)"
    fi

    download "$CADDY_DEB_URL" "/tmp/caddy-linux-amd64.deb"
    sudo apt install -y /tmp/caddy-linux-amd64.deb
    rm -f /tmp/caddy-linux-amd64.deb

    sudo systemctl enable caddy
    sudo systemctl start caddy
    ok "Caddy installed and running."
fi

# MySQL Check & Install
if command -v mysql &> /dev/null; then
    if ask_skip_or_overwrite "MySQL Server"; then
        INSTALL_MYSQL=true
    else
        INSTALL_MYSQL=false
    fi
else
    INSTALL_MYSQL=true
fi

if [ "$INSTALL_MYSQL" = true ]; then
    echo -e "\n=== Installing MySQL Server ==="
    sudo apt install -y mysql-server
    sudo systemctl enable mysql
    sudo systemctl start mysql
fi

# MySQL Root Password Config
echo -e "\n=== Configuring MySQL Root User & Password ==="
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PASSWORD}';" || \
sudo mysql -u root -p"${MYSQL_ROOT_PASSWORD}" -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PASSWORD}';" 2>/dev/null || true
sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true

# Composer Check & Install
if command -v composer &> /dev/null; then
    if ask_skip_or_overwrite "Composer"; then
        INSTALL_COMPOSER=true
    else
        INSTALL_COMPOSER=false
    fi
else
    INSTALL_COMPOSER=true
fi

if [ "$INSTALL_COMPOSER" = true ]; then
    step "Installing Composer"
    download "https://getcomposer.org/installer" "/tmp/composer-setup.php"
    php /tmp/composer-setup.php
    sudo mv composer.phar /usr/local/bin/composer
    rm -f /tmp/composer-setup.php
    ok "Composer installed."
fi

# Node.js Check & Install
if command -v node &> /dev/null; then
    if ask_skip_or_overwrite "Node.js & NPM"; then
        INSTALL_NODE=true
    else
        INSTALL_NODE=false
    fi
else
    INSTALL_NODE=true
fi

if [ "$INSTALL_NODE" = true ]; then
    echo -e "\n=== Installing Node.js & NPM ==="
    curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
    sudo apt install -y nodejs
fi

# Web Directory Setup
sudo mkdir -p /var/www/html
sudo chown -R www-data:www-data /var/www

# phpMyAdmin Check & Install
if [ -d "/var/www/phpmyadmin" ]; then
    if ask_skip_or_overwrite "phpMyAdmin Directory"; then
        INSTALL_PMA=true
    else
        INSTALL_PMA=false
    fi
else
    INSTALL_PMA=true
fi

if [ "$INSTALL_PMA" = true ]; then
    step "Installing phpMyAdmin"
    PMA_VER="5.2.1"
    cd /tmp
    download "https://files.phpmyadmin.net/phpMyAdmin/${PMA_VER}/phpMyAdmin-${PMA_VER}-all-languages.tar.gz" \
        "/tmp/phpMyAdmin-${PMA_VER}-all-languages.tar.gz"
    tar -zxf "phpMyAdmin-${PMA_VER}-all-languages.tar.gz"
    sudo rm -rf /var/www/phpmyadmin
    sudo mv "phpMyAdmin-${PMA_VER}-all-languages" /var/www/phpmyadmin
    sudo rm "phpMyAdmin-${PMA_VER}-all-languages.tar.gz"
    sudo mkdir -p /var/www/phpmyadmin/tmp
    sudo chown -R www-data:www-data /var/www/phpmyadmin
    ok "phpMyAdmin installed at /var/www/phpmyadmin"
fi

# =========================================================
# DYNAMIC CADDYFILE WIZARD & PROJECT CREATION
# =========================================================
echo -e "\n========================================================="
echo " CADDYFILE CONFIGURATION WIZARD"
echo "========================================================="

ask HAS_SAAS "Do you want to configure a SaaS project with On-Demand SSL/Custom Domains? [y/N]" "n"

if [[ "$HAS_SAAS" =~ ^[yY]$ ]]; then
    ask SAAS_DOMAIN "Enter SaaS Main Domain (e.g. mysaas.com)"
    ask SAAS_ASK_URL "Enter Domain Check Endpoint URL" "http://localhost/api/caddy-check-domain"

    echo "Select PHP Version for SaaS project ($SAAS_DOMAIN):"
    echo "1) 8.4"
    echo "2) 8.3"
    echo "3) 8.2"
    echo "4) 8.1"
    echo "5) 7.4"
    ask PHP_CHOICE "Choose [1-5]" "1"

    case $PHP_CHOICE in
        2) SAAS_PHP="8.3" ;;
        3) SAAS_PHP="8.2" ;;
        4) SAAS_PHP="8.1" ;;
        5) SAAS_PHP="7.4" ;;
        *) SAAS_PHP="8.4" ;;
    esac

    # Caddyfile ডায়নামিক জেনারেট করা (Caddy v2.8+ সামঞ্জস্যপূর্ণ)
 cat <<EOF | sudo tee /etc/caddy/Caddyfile > /dev/null
{
    on_demand_tls {
        ask ${SAAS_ASK_URL}
    }
}

# কমন স্নিপেট: সব সাইটের জন্য TLS অন-ডিমান্ড এবং কম্প্রেশন গ্লোবালি হ্যান্ডেল করবে
(site_defaults) {
    tls {
        on_demand
    }
    encode zstd br gzip
}

:8080 {
    root * /var/www/phpmyadmin
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}

# SaaS Main Domain & Wildcard Subdomains
${SAAS_DOMAIN}, *.${SAAS_DOMAIN} {
    import site_defaults
    root * /var/www/${SAAS_DOMAIN}/public
    php_fastcgi unix//run/php/php${SAAS_PHP}-fpm.sock
    file_server
}

# On-Demand SSL for Custom Domains
https:// {
    import site_defaults
    root * /var/www/${SAAS_DOMAIN}/public
    php_fastcgi unix//run/php/php${SAAS_PHP}-fpm.sock
    file_server
}
EOF
    # ১. ডোমেইন অনুযায়ী ফোল্ডার তৈরি
    sudo mkdir -p /var/www/${SAAS_DOMAIN}/public

    # ২. সুন্দর ডিজাইনের index.html তৈরি করা
    cat <<'EOF' | sudo tee /var/www/${SAAS_DOMAIN}/public/index.html > /dev/null
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to SaaS System</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
body {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.card {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 40px;
    text-align: center;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
    padding: 8px 16px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 24px;
    border: 1px solid rgba(34, 197, 94, 0.3);
}
.pulse {
    width: 10px;
    height: 10px;
    background-color: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    animation: pulse 1.6s infinite;
}
@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}
h1 {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 12px;
    background: linear-gradient(to right, #38bdf8, #818cf8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
p {
    color: #94a3b8;
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 24px;
}
</style>
</head>
<body>
<div class="card">
    <div class="status-badge">
        <span class="pulse"></span>
        System Active
    </div>
    <h1>You are live now!</h1>
    <p>Your Caddy server & PHP setup is successfully configured and ready for deployment.</p>
</div>
</body>
</html>
EOF

    # ৩. Ownership দেওয়া
    sudo chown -R www-data:www-data /var/www/${SAAS_DOMAIN}
else
    # সাধারণ ডিফল্ট Caddyfile
    cat <<EOF | sudo tee /etc/caddy/Caddyfile > /dev/null
:8080 {
    root * /var/www/phpmyadmin
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
EOF
fi

# Caddy Configuration Validate and Reload
echo -e "\nTesting Caddy Configuration..."
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy

# ডায়নামিক আইপি ডিটেক্ট
SERVER_IP=$(curl -s -4 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

echo "========================================================="
echo " SETUP COMPLETED SUCCESSFULLY!"
echo "========================================================="
echo "Installed PHP versions: 7.4, 8.1, 8.2, 8.3, 8.4"
echo "phpMyAdmin Access: http://${SERVER_IP}:8080"
echo "phpMyAdmin Username: root"
echo "phpMyAdmin Password: ${MYSQL_ROOT_PASSWORD}"

if [[ "$HAS_SAAS" =~ ^[yY]$ ]]; then
    echo "--------------------------------------------------------- "
    echo "SaaS Domain: https://${SAAS_DOMAIN}"
    echo "SaaS Root Dir: /var/www/${SAAS_DOMAIN}/public"
    echo "Test File Created: /var/www/${SAAS_DOMAIN}/public/index.html"
    echo "SaaS PHP Version: ${SAAS_PHP}"
    echo "On-Demand SSL Check URL: ${SAAS_ASK_URL}"
fi
echo "========================================================="
