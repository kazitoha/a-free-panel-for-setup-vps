#!/bin/bash

set -e

# Function: Skip or Overwrite Check
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
echo "        POWERDNS + MYSQL BACKEND SETUP WIZARD            "
echo "========================================================="
read -sp "Enter MySQL Root Password: " MYSQL_ROOT_PASSWORD
echo ""
read -sp "Confirm MySQL Root Password: " MYSQL_ROOT_PASSWORD_CONFIRM
echo ""
echo "========================================================="

if [ "$MYSQL_ROOT_PASSWORD" != "$MYSQL_ROOT_PASSWORD_CONFIRM" ]; then
    echo -e "\e[31mError: Passwords do not match!\e[0m"
    exit 1
fi

# MySQL Connection Test
if ! mysql -u root -p"${MYSQL_ROOT_PASSWORD}" -e "STATUS;" &>/dev/null; then
    echo -e "\e[31mError: Incorrect MySQL Root Password or MySQL service is not running!\e[0m"
    exit 1
fi

# PowerDNS Pre-installation Check
if command -v pdns_server &> /dev/null || [ -d "/etc/powerdns" ]; then
    if ! ask_skip_or_overwrite "PowerDNS Server & MySQL Setup"; then
        echo -e "\n\e[32mSkipping PowerDNS core installation and database reconfiguration.\e[0m"
        SKIP_CORE_INSTALL=true
    else
        SKIP_CORE_INSTALL=false
    fi
else
    SKIP_CORE_INSTALL=false
fi

# Detect VPS Public IP automatically
SERVER_IP=$(curl -s -4 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

if [ "$SKIP_CORE_INSTALL" = false ]; then

    echo -e "\n=== 1. Interactive Nameserver Configuration ==="
    read -p "Do you want to configure a primary domain for VPS Nameservers (e.g. ns1/ns2)? [Y/n]: " SETUP_NS
    SETUP_NS=${SETUP_NS:-Y}

    if [[ "$SETUP_NS" =~ ^[yY]$ ]]; then
        read -p "Enter your Primary Nameserver Domain (e.g. hptechbd.com): " NS_DOMAIN
        read -p "Enter VPS Public IP for Nameserver [$SERVER_IP]: " CUSTOM_IP
        NS_IP=${CUSTOM_IP:-$SERVER_IP}
    fi

    echo -e "\n=== 2. Fixing System Hostname & Port 53 Conflicts ==="

    CURRENT_HOST=$(hostname)
    if ! grep -q "$CURRENT_HOST" /etc/hosts; then
        echo "Adding $CURRENT_HOST to /etc/hosts..."
        sudo sed -i "s/127.0.0.1\s\+localhost/127.0.0.1 localhost $CURRENT_HOST/" /etc/hosts
    fi

    if grep -q "#DNSStubListener=yes\|DNSStubListener=yes" /etc/systemd/resolved.conf 2>/dev/null; then
        echo "Disabling DNSStubListener in systemd-resolved..."
        sudo sed -i 's/#DNSStubListener=yes/DNSStubListener=no/' /etc/systemd/resolved.conf
        sudo sed -i 's/DNSStubListener=yes/DNSStubListener=no/' /etc/systemd/resolved.conf
        
        sudo rm -f /etc/resolv.conf
        sudo ln -s /run/systemd/resolve/resolv.conf /etc/resolv.conf
        sudo systemctl restart systemd-resolved
    fi

    echo -e "\n=== 3. Installing PowerDNS & MySQL Backend ==="
    sudo apt update
    sudo apt install -y pdns-server pdns-backend-mysql

    echo -e "\n=== 4. Creating PowerDNS Database, Users & Tables ==="
    PDNS_DB_PASS=$(openssl rand -hex 12)

    mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<EOF
CREATE DATABASE IF NOT EXISTS pdns;
CREATE USER IF NOT EXISTS 'pdns_user'@'localhost' IDENTIFIED BY '${PDNS_DB_PASS}';
ALTER USER 'pdns_user'@'localhost' IDENTIFIED BY '${PDNS_DB_PASS}';
GRANT ALL PRIVILEGES ON pdns.* TO 'pdns_user'@'localhost';
FLUSH PRIVILEGES;
EOF

    mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pdns <<'EOF'
CREATE TABLE IF NOT EXISTS domains (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255) NOT NULL,
  master                VARCHAR(128) DEFAULT NULL,
  last_check            INT DEFAULT NULL,
  type                  VARCHAR(6) NOT NULL,
  notified_serial       INT UNSIGNED DEFAULT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY name_index (name)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS records (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT DEFAULT NULL,
  name                  VARCHAR(255) DEFAULT NULL,
  type                  VARCHAR(10) DEFAULT NULL,
  content               TEXT DEFAULT NULL,
  ttl                   INT DEFAULT 3600,
  prio                  INT DEFAULT 0,
  disabled              TINYINT(1) DEFAULT 0,
  ordername             VARCHAR(255) BINARY DEFAULT NULL,
  auth                  TINYINT(1) DEFAULT 1,
  PRIMARY KEY (id),
  KEY rec_name_time_index (name(255), type),
  KEY domain_id (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS supermasters (
  ip                    VARCHAR(64) NOT NULL,
  nameserver            VARCHAR(255) NOT NULL,
  account               VARCHAR(40) NOT NULL,
  PRIMARY KEY (ip, nameserver)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  name                  VARCHAR(255) NOT NULL,
  type                  VARCHAR(10) NOT NULL,
  modified_at           INT NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  comment               TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY name_index (name(255), type),
  KEY domain_id (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS domainmetadata (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  kind                  VARCHAR(32),
  content               TEXT,
  PRIMARY KEY (id),
  KEY domainmetadata_idx (domain_id, kind)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS cryptokeys (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  flags                 INT NOT NULL,
  active                BOOL,
  published             BOOL DEFAULT 1,
  content               TEXT,
  PRIMARY KEY (id),
  KEY domainidindex (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS tsigkeys (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255),
  algorithm             VARCHAR(50),
  secret                VARCHAR(255),
  PRIMARY KEY (id),
  UNIQUE KEY name_algo_index (name, algorithm)
) Engine=InnoDB;
EOF

    if [[ "$SETUP_NS" =~ ^[yY]$ ]] && [ -n "$NS_DOMAIN" ]; then
        echo -e "\n=== Creating Primary Nameserver Records for ${NS_DOMAIN} ==="
        
        mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pdns <<EOF
        DELETE FROM records WHERE name LIKE '%${NS_DOMAIN}';
        DELETE FROM domains WHERE name='${NS_DOMAIN}';

        INSERT INTO domains (name, type) VALUES ('${NS_DOMAIN}', 'NATIVE');
        SET @domain_id = LAST_INSERT_ID();

        INSERT INTO records (domain_id, name, type, content, ttl) VALUES 
        (@domain_id, '${NS_DOMAIN}', 'SOA', 'ns1.${NS_DOMAIN}. admin.${NS_DOMAIN}. 2026100701 10800 3600 604800 3600', 86400),
        (@domain_id, '${NS_DOMAIN}', 'NS', 'ns1.${NS_DOMAIN}', 86400),
        (@domain_id, '${NS_DOMAIN}', 'NS', 'ns2.${NS_DOMAIN}', 86400);

        INSERT INTO records (domain_id, name, type, content, ttl) VALUES 
        (@domain_id, '${NS_DOMAIN}', 'A', '${NS_IP}', 3600),
        (@domain_id, '*.${NS_DOMAIN}', 'A', '${NS_IP}', 3600),
        (@domain_id, 'ns1.${NS_DOMAIN}', 'A', '${NS_IP}', 3600),
        (@domain_id, 'ns2.${NS_DOMAIN}', 'A', '${NS_IP}', 3600);
EOF
    fi

    echo -e "\n=== 5. Configuring PowerDNS (/etc/powerdns/pdns.conf) ==="
    sudo bash -c "cat <<EOF > /etc/powerdns/pdns.conf
# PowerDNS MySQL Backend Configuration
launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-user=pdns_user
gmysql-password=${PDNS_DB_PASS}
gmysql-dbname=pdns

# Network & Server Settings
local-address=0.0.0.0
local-port=53
master=yes
daemon=yes
guardian=yes
EOF"

    echo -e "\n=== 6. Restarting PowerDNS Service ==="
    sudo systemctl restart pdns
    sudo systemctl enable pdns

fi

# =========================================================
# INTERACTIVE CUSTOMER DOMAIN IMPORT (AT THE END)
# =========================================================
echo -e "\n========================================================="
read -p "Do you want to add customer domain(s) right now? [y/N]: " ADD_CUSTOMER_DOMAINS

if [[ "$ADD_CUSTOMER_DOMAINS" =~ ^[yY]$ ]]; then
    read -p "Enter domain(s) separated by comma (e.g. domain1.com,domain2.com): " INPUT_DOMAINS
    read -p "Enter Primary NS Domain used for these clients [hptechbd.com]: " TARGET_NS_DOMAIN
    TARGET_NS_DOMAIN=${TARGET_NS_DOMAIN:-hptechbd.com}
    
    read -p "Enter Target IP for these domains [$SERVER_IP]: " TARGET_DOMAIN_IP
    TARGET_DOMAIN_IP=${TARGET_DOMAIN_IP:-$SERVER_IP}

    # Split comma-separated string into array
    IFS=',' read -ra DOMAIN_ARRAY <<< "$INPUT_DOMAINS"

    echo -e "\n=== Adding Customer Domains to Database ==="
    for d in "${DOMAIN_ARRAY[@]}"; do
        # Trim leading/trailing spaces
        DOMAIN_NAME=$(echo "$d" | xargs)
        
        if [ -n "$DOMAIN_NAME" ]; then
            echo "Processing: $DOMAIN_NAME..."
            
            mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pdns <<EOF
            DELETE FROM records WHERE name LIKE '%${DOMAIN_NAME}';
            DELETE FROM domains WHERE name='${DOMAIN_NAME}';

            INSERT INTO domains (name, type) VALUES ('${DOMAIN_NAME}', 'NATIVE');
            SET @domain_id = LAST_INSERT_ID();

            INSERT INTO records (domain_id, name, type, content, ttl) VALUES 
            (@domain_id, '${DOMAIN_NAME}', 'SOA', 'ns1.${TARGET_NS_DOMAIN}. admin.${TARGET_NS_DOMAIN}. 2026100701 10800 3600 604800 3600', 86400),
            (@domain_id, '${DOMAIN_NAME}', 'NS', 'ns1.${TARGET_NS_DOMAIN}', 86400),
            (@domain_id, '${DOMAIN_NAME}', 'NS', 'ns2.${TARGET_NS_DOMAIN}', 86400);

            INSERT INTO records (domain_id, name, type, content, ttl) VALUES 
            (@domain_id, '${DOMAIN_NAME}', 'A', '${TARGET_DOMAIN_IP}', 3600),
            (@domain_id, '*.${DOMAIN_NAME}', 'A', '${TARGET_DOMAIN_IP}', 3600);
EOF
            echo -e " \e[32m✔ $DOMAIN_NAME successfully added!\e[0m"
        fi
    done
    
    sudo systemctl restart pdns
fi

echo -e "\n========================================================="
echo "        POWERDNS WIZARD COMPLETED SUCCESSFULLY!          "
echo "========================================================="
