#!/bin/bash

set -e

# ফাংশন: অপশন চেক এবং ইউজারের সিদ্ধান্ত নেওয়ার জন্য
ask_skip_or_overwrite() {
    local item_name="$1"
    echo -e "\n\e[33m[CHECK]\e[0m '$item_name' is already installed or configured."
    read -p "Do you want to (s)kip or (o)verwrite/reinstall? [s/o]: " choice
    case "$choice" in 
        o|O ) return 0 ;; # Overwrite / Reinstall
        * ) return 1 ;;   # Skip by default
    esac
}

echo "========================================================="
read -sp "Enter MySQL Root Password: " MYSQL_ROOT_PASSWORD
echo ""
read -sp "Confirm MySQL Root Password: " MYSQL_ROOT_PASSWORD_CONFIRM
echo ""
echo "========================================================="

if [ "$MYSQL_ROOT_PASSWORD" != "$MYSQL_ROOT_PASSWORD_CONFIRM" ]; then
    echo "Error: Passwords do not match!"
    exit 1
fi

echo -e "\n=== System Update and Installing Essentials ==="
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

# Caddy Check & Install
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
    echo -e "\n=== Installing/Reinstalling Caddy Web Server ==="
    sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg --yes
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
    sudo apt update
    sudo apt install --reinstall caddy -y
    sudo systemctl enable caddy
    sudo systemctl start caddy
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
    echo -e "\n=== Installing Composer ==="
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
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
    echo -e "\n=== Installing phpMyAdmin ==="
    PMA_VER="5.2.1"
    cd /tmp
    wget https://files.phpmyadmin.net/phpMyAdmin/${PMA_VER}/phpMyAdmin-${PMA_VER}-all-languages.tar.gz
    tar -zxvf phpMyAdmin-${PMA_VER}-all-languages.tar.gz
    sudo rm -rf /var/www/phpmyadmin
    sudo mv phpMyAdmin-${PMA_VER}-all-languages /var/www/phpmyadmin
    sudo rm phpMyAdmin-${PMA_VER}-all-languages.tar.gz
    sudo mkdir -p /var/www/phpmyadmin/tmp
    sudo chown -R www-data:www-data /var/www/phpmyadmin
fi

# =========================================================
# DYNAMIC CADDYFILE WIZARD & PROJECT CREATION
# =========================================================

echo -e "\n========================================================="
echo " CADDYFILE CONFIGURATION WIZARD"
echo "========================================================="

read -p "Do you want to configure a SaaS project with On-Demand SSL/Custom Domains? [y/N]: " HAS_SAAS

if [[ "$HAS_SAAS" =~ ^[yY]$ ]]; then
    read -p "Enter SaaS Main Domain (e.g. mysaas.com): " SAAS_DOMAIN
    read -p "Enter Domain Check Endpoint URL [http://localhost/api/caddy-check-domain]: " SAAS_ASK_URL
    SAAS_ASK_URL=${SAAS_ASK_URL:-http://localhost/api/caddy-check-domain}
    
    echo "Select PHP Version for SaaS project ($SAAS_DOMAIN):"
    echo "1) 8.4"
    echo "2) 8.3"
    echo "3) 8.2"
    echo "4) 8.1"
    echo "5) 7.4"
    read -p "Choose [1-5] (default 1): " PHP_CHOICE
    case $PHP_CHOICE in
        2) SAAS_PHP="8.3" ;;
        3) SAAS_PHP="8.2" ;;
        4) SAAS_PHP="8.1" ;;
        5) SAAS_PHP="7.4" ;;
        *) SAAS_PHP="8.4" ;;
    esac

    # Caddyfile ডায়নামিক জেনারেট করা (Caddy v2.8+ সামঞ্জস্যপূর্ণ)
    sudo bash -c "cat <<EOF > /etc/caddy/Caddyfile
{
    on_demand_tls {
        ask ${SAAS_ASK_URL}
    }
}

:8080 {
    root * /var/www/phpmyadmin
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}

# SaaS Main Domain & Wildcard Subdomains
${SAAS_DOMAIN}, *.${SAAS_DOMAIN} {
    root * /var/www/${SAAS_DOMAIN}/public
    php_fastcgi unix//run/php/php${SAAS_PHP}-fpm.sock
    file_server
}

# On-Demand SSL for Custom Domains
https:// {
    tls {
        on_demand
    }
    root * /var/www/${SAAS_DOMAIN}/public
    php_fastcgi unix//run/php/php${SAAS_PHP}-fpm.sock
    file_server
}
EOF"

    # ১. ডোমেইন অনুযায়ী ফোল্ডার তৈরি (/var/www/DOMAIN/public)
    sudo mkdir -p /var/www/${SAAS_DOMAIN}/public

    # ২. সুন্দর ডিজাইনের index.html তৈরি করা
    sudo bash -c "cat <<EOF > /var/www/${SAAS_DOMAIN}/public/index.html
<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Welcome to ${SAAS_DOMAIN}</title>
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
        .domain-tag {
            background: #0f172a;
            color: #e2e8f0;
            padding: 10px 16px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 14px;
            border: 1px solid #334155;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class=\"card\">
        <div class=\"status-badge\">
            <span class=\"pulse\"></span>
            System Active
        </div>
        <h1>You are live now!</h1>
        <p>Your Caddy server & PHP setup is successfully configured and ready for deployment.</p>
        <div class=\"domain-tag\">${SAAS_DOMAIN}</div>
    </div>
</body>
</html>
EOF"

    # ৩. যথাযথ Ownership এবং Permissions দেওয়া
    sudo chown -R www-data:www-data /var/www/${SAAS_DOMAIN}

else
    # সাধারণ ডিফল্ট Caddyfile
    sudo bash -c 'cat <<EOF > /etc/caddy/Caddyfile
:8080 {
    root * /var/www/phpmyadmin
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
EOF'
fi

# Caddy Configuration Validate and Reload
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
