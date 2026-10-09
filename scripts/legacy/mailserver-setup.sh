#!/bin/bash

set -euo pipefail

# ============================================================
# Stalwart Mail Server + PowerDNS + Caddy
# Ubuntu 24.04
# ============================================================

SCRIPT_NAME="$(basename "$0")"
BACKUP_DIR="/root/stalwart-backups"
INFO_FILE="/root/stalwart-install-info.txt"

# ------------------------------------------------------------
# Colors / Output Helpers
# ------------------------------------------------------------

print_info() {
    echo -e "\033[1;34m[INFO]\033[0m $1"
}

print_success() {
    echo -e "\033[1;32m[OK]\033[0m $1"
}

print_warning() {
    echo -e "\033[1;33m[WARNING]\033[0m $1"
}

print_error() {
    echo -e "\033[1;31m[ERROR]\033[0m $1"
}

print_step() {
    echo -e "\n\033[1;36m==> $1\033[0m"
}

die() {
    print_error "$1"
    exit 1
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

pause_screen() {
    echo
    read -r -p "Press ENTER to continue..."
}

# ------------------------------------------------------------
# Root Check
# ------------------------------------------------------------

if [[ "${EUID}" -ne 0 ]]; then
    print_error "This script must be run as root."
    echo "Run:"
    echo "sudo ./$SCRIPT_NAME"
    exit 1
fi

# ------------------------------------------------------------
# OS Check
# ------------------------------------------------------------

print_step "Checking operating system"

if [[ ! -f /etc/os-release ]]; then
    die "/etc/os-release not found."
fi

source /etc/os-release

if [[ "${ID:-}" != "ubuntu" ]]; then
    die "This script is designed for Ubuntu."
fi

if [[ "${VERSION_ID:-}" != "24.04" ]]; then
    print_warning "This script was tested for Ubuntu 24.04."
    print_warning "Detected Ubuntu version: ${VERSION_ID:-unknown}"
fi

print_success "Ubuntu ${VERSION_ID:-unknown} detected."

# ------------------------------------------------------------
# Create Backup Directory
# ------------------------------------------------------------

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# ------------------------------------------------------------
# Basic Dependency Installation
# ------------------------------------------------------------

print_step "Installing required packages"

export DEBIAN_FRONTEND=noninteractive

apt-get update

apt-get install -y \
    curl \
    wget \
    ca-certificates \
    gnupg \
    lsb-release \
    dnsutils \
    net-tools \
    ufw \
    mysql-client \
    openssl

print_success "Required packages installed."

# ------------------------------------------------------------
# Collect Mail Configuration
# ------------------------------------------------------------

print_step "Mail server configuration"

echo
echo "Example:"
echo "  Mail hostname: mail.example.com"
echo "  Base domain:   example.com"
echo

read -r -p "Enter mail hostname [mail.example.com]: " MAIL_HOSTNAME
MAIL_HOSTNAME="${MAIL_HOSTNAME:-mail.example.com}"

# Remove trailing dot if entered
MAIL_HOSTNAME="${MAIL_HOSTNAME%.}"

if [[ ! "$MAIL_HOSTNAME" =~ ^[A-Za-z0-9.-]+$ ]]; then
    die "Invalid mail hostname: $MAIL_HOSTNAME"
fi

# Derive base domain
BASE_DOMAIN="${MAIL_HOSTNAME#*.}"

if [[ "$BASE_DOMAIN" == "$MAIL_HOSTNAME" ]]; then
    die "Could not determine base domain from $MAIL_HOSTNAME"
fi

print_info "Mail hostname: $MAIL_HOSTNAME"
print_info "Base domain:   $BASE_DOMAIN"

# ------------------------------------------------------------
# Detect Public IPv4
# ------------------------------------------------------------

print_step "Detecting VPS public IPv4"

DETECTED_IP=""

if command_exists curl; then
    DETECTED_IP="$(curl -4 -s --max-time 10 https://api.ipify.org || true)"
fi

if [[ -z "$DETECTED_IP" ]]; then
    DETECTED_IP="$(curl -4 -s --max-time 10 https://ifconfig.me || true)"
fi

if [[ -n "$DETECTED_IP" ]]; then
    print_info "Detected public IPv4: $DETECTED_IP"
else
    print_warning "Could not automatically detect public IPv4."
fi

read -r -p "Enter VPS public IPv4 [${DETECTED_IP:-}]: " SERVER_IP
SERVER_IP="${SERVER_IP:-$DETECTED_IP}"

if [[ -z "$SERVER_IP" ]]; then
    die "Public IPv4 is required."
fi

if [[ ! "$SERVER_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    die "Invalid IPv4 address: $SERVER_IP"
fi

print_success "Using public IPv4: $SERVER_IP"

# ------------------------------------------------------------
# PowerDNS Configuration
# ------------------------------------------------------------

print_step "PowerDNS configuration"

read -r -p "PowerDNS database name [pdns]: " PDNS_DB
PDNS_DB="${PDNS_DB:-pdns}"

read -r -p "PowerDNS database user [root]: " PDNS_DB_USER
PDNS_DB_USER="${PDNS_DB_USER:-root}"

echo
read -r -s -p "MySQL password for PowerDNS user '$PDNS_DB_USER': " MYSQL_PASSWORD
echo

# ------------------------------------------------------------
# MySQL Connection Helper
# ------------------------------------------------------------

mysql_cmd() {
    MYSQL_PWD="$MYSQL_PASSWORD" mysql \
        --protocol=socket \
        -u "$PDNS_DB_USER" \
        "$@"
}

mysql_query() {
    MYSQL_PWD="$MYSQL_PASSWORD" mysql \
        --protocol=socket \
        -u "$PDNS_DB_USER" \
        -N -B \
        "$@"
}

print_step "Testing MySQL connection"

if ! mysql_cmd -e "SELECT 1;" >/dev/null 2>&1; then
    print_error "Could not connect to MySQL."
    print_error "Check PowerDNS database username/password."
    exit 1
fi

print_success "MySQL connection successful."

# ------------------------------------------------------------
# Verify PowerDNS Database
# ------------------------------------------------------------

print_step "Checking PowerDNS database"

if ! mysql_cmd -e "USE \`$PDNS_DB\`;" >/dev/null 2>&1; then
    die "PowerDNS database '$PDNS_DB' does not exist or cannot be accessed."
fi

if ! mysql_cmd -e "USE \`$PDNS_DB\`; SELECT 1 FROM domains LIMIT 1;" >/dev/null 2>&1; then
    die "PowerDNS 'domains' table was not found."
fi

if ! mysql_cmd -e "USE \`$PDNS_DB\`; SELECT 1 FROM records LIMIT 1;" >/dev/null 2>&1; then
    die "PowerDNS 'records' table was not found."
fi

print_success "PowerDNS database structure looks valid."

# ------------------------------------------------------------
# PowerDNS Domain ID
# ------------------------------------------------------------

print_step "Checking PowerDNS zone"

DOMAIN_ID="$(
    mysql_query "$PDNS_DB" \
    -e "SELECT id FROM domains WHERE name='${BASE_DOMAIN}' LIMIT 1;" \
    2>/dev/null || true
)"

if [[ -z "$DOMAIN_ID" ]]; then
    print_warning "Zone '$BASE_DOMAIN' was not found in PowerDNS."

    read -r -p "Create PowerDNS zone '$BASE_DOMAIN' now? [Y/n]: " CREATE_ZONE
    CREATE_ZONE="${CREATE_ZONE:-Y}"

    if [[ "$CREATE_ZONE" =~ ^[Yy]$ ]]; then

        mysql_cmd "$PDNS_DB" <<SQL
INSERT INTO domains (name, type)
VALUES ('${BASE_DOMAIN}', 'NATIVE');
SQL

        DOMAIN_ID="$(
            mysql_query "$PDNS_DB" \
            -e "SELECT id FROM domains WHERE name='${BASE_DOMAIN}' LIMIT 1;"
        )"

        print_success "PowerDNS zone created."
    else
        die "PowerDNS zone is required."
    fi
else
    print_success "PowerDNS zone found. ID: $DOMAIN_ID"
fi

# ------------------------------------------------------------
# PowerDNS Record Functions
# ------------------------------------------------------------

pdns_delete_record() {
    local name="$1"
    local type="$2"

    mysql_cmd "$PDNS_DB" <<SQL
DELETE FROM records
WHERE domain_id='${DOMAIN_ID}'
  AND name='${name}'
  AND type='${type}';
SQL
}

pdns_add_record() {
    local name="$1"
    local type="$2"
    local content="$3"
    local ttl="${4:-300}"
    local priority="${5:-0}"

    mysql_cmd "$PDNS_DB" <<SQL
INSERT INTO records
(domain_id, name, type, content, ttl, prio, disabled)
VALUES
('${DOMAIN_ID}', '${name}', '${type}', '${content}', '${ttl}', '${priority}', 0);
SQL
}

# ------------------------------------------------------------
# Install Stalwart
# ------------------------------------------------------------

print_step "Checking Stalwart installation"

STALWART_SERVICE=""

if systemctl cat stalwart.service >/dev/null 2>&1; then
    STALWART_SERVICE="stalwart"
elif systemctl cat stalwart-mail.service >/dev/null 2>&1; then
    STALWART_SERVICE="stalwart-mail"
fi

if [[ -n "$STALWART_SERVICE" ]]; then

    print_success "Stalwart service already exists: $STALWART_SERVICE"

else

    print_info "Stalwart is not installed."
    print_info "Installing latest Stalwart Mail Server..."

    INSTALLER="/tmp/stalwart-install.sh"

    curl \
        --proto '=https' \
        --tlsv1.2 \
        -sSf \
        https://get.stalw.art/install.sh \
        -o "$INSTALLER"

    chmod 700 "$INSTALLER"

    sh "$INSTALLER"

    rm -f "$INSTALLER"

    # --------------------------------------------------------
    # Detect service after installation
    # --------------------------------------------------------

    sleep 3

    if systemctl cat stalwart.service >/dev/null 2>&1; then
        STALWART_SERVICE="stalwart"

    elif systemctl cat stalwart-mail.service >/dev/null 2>&1; then
        STALWART_SERVICE="stalwart-mail"

    else

        print_warning "Could not find expected Stalwart service."

        echo
        echo "Available Stalwart-related services:"
        systemctl list-unit-files \
            --type=service \
            --all \
            --no-legend 2>/dev/null \
            | grep -i stalwart || true

        echo
        echo "Running Stalwart processes:"
        ps aux | grep -i '[s]talwart' || true

        echo
        print_error "Stalwart installation completed, but service detection failed."
        print_error "DO NOT continue with DNS/Caddy configuration yet."
        exit 1
    fi

    print_success "Stalwart service detected: $STALWART_SERVICE"
fi

# ------------------------------------------------------------
# Verify Stalwart Files
# ------------------------------------------------------------

print_step "Checking Stalwart installation files"

if [[ ! -d /etc/stalwart ]]; then
    die "/etc/stalwart directory not found."
fi

if [[ ! -f /etc/stalwart/stalwart.env ]]; then
    print_warning "/etc/stalwart/stalwart.env not found."
fi

if [[ -f /etc/stalwart/config.json ]]; then
    print_success "Stalwart config.json exists."
    BOOTSTRAP_MODE="no"
else
    print_warning "Stalwart config.json does not exist."
    print_warning "Stalwart is currently expected to be in bootstrap mode."
    BOOTSTRAP_MODE="yes"
fi

# ------------------------------------------------------------
# Enable / Start Stalwart
# ------------------------------------------------------------

print_step "Starting Stalwart service"

systemctl daemon-reload

systemctl enable "$STALWART_SERVICE" >/dev/null 2>&1 || true

systemctl restart "$STALWART_SERVICE"

sleep 3

if systemctl is-active --quiet "$STALWART_SERVICE"; then
    print_success "Stalwart service is running."
else
    print_error "Stalwart service is not running."

    echo
    systemctl status "$STALWART_SERVICE" --no-pager || true

    echo
    echo "Recent logs:"
    journalctl -u "$STALWART_SERVICE" -n 100 --no-pager || true

    exit 1
fi

# ------------------------------------------------------------
# Bootstrap Information
# ------------------------------------------------------------

if [[ "$BOOTSTRAP_MODE" == "yes" ]]; then

    print_step "Stalwart bootstrap mode detected"

    echo
    print_warning "IMPORTANT"
    echo
    echo "Stalwart is currently in BOOTSTRAP MODE."
    echo
    echo "According to the current Stalwart installation:"
    echo
    echo "  Web UI:"
    echo "  http://SERVER-IP:8080/admin"
    echo
    echo "The temporary administrator password is shown in the"
    echo "Stalwart service log."
    echo

    echo "Run:"
    echo
    echo "  journalctl -u $STALWART_SERVICE -n 200 --no-pager | grep -A8 'bootstrap mode'"
    echo

    echo "Or:"
    echo
    echo "  journalctl -u $STALWART_SERVICE --no-pager"
    echo

    print_warning "Complete the Stalwart setup wizard before production mail configuration."
    echo

    # Save useful information
    cat > "$INFO_FILE" <<EOF
Stalwart Installation Information
=================================

Mail hostname:
$MAIL_HOSTNAME

Base domain:
$BASE_DOMAIN

VPS public IPv4:
$SERVER_IP

Stalwart service:
$STALWART_SERVICE

Stalwart bootstrap URL:
http://$SERVER_IP:8080/admin

Stalwart local URL:
http://127.0.0.1:8080/admin

Bootstrap password command:
journalctl -u $STALWART_SERVICE -n 200 --no-pager | grep -A8 'bootstrap mode'

Installed paths:
/etc/stalwart/
/etc/stalwart/config.json
/etc/stalwart/stalwart.env
/var/lib/stalwart/
/var/log/stalwart/

IMPORTANT:
Complete the Stalwart bootstrap wizard first.
Do not expose the bootstrap listener permanently.
EOF

    chmod 600 "$INFO_FILE"

    print_success "Information saved to:"
    echo "$INFO_FILE"

    echo
    print_warning "The script will STOP here."
    print_warning "Complete the Stalwart bootstrap wizard first."
    echo

    exit 0
fi

# ------------------------------------------------------------
# DNS Records
# ------------------------------------------------------------

print_step "Configuring PowerDNS records"

# A record
pdns_delete_record "$MAIL_HOSTNAME" "A"
pdns_add_record "$MAIL_HOSTNAME" "A" "$SERVER_IP" 300 0

print_success "A record added:"
echo "  $MAIL_HOSTNAME -> $SERVER_IP"

# MX record
pdns_delete_record "$BASE_DOMAIN" "MX"
pdns_add_record "$BASE_DOMAIN" "MX" "$MAIL_HOSTNAME." 300 10

print_success "MX record added:"
echo "  $BASE_DOMAIN -> $MAIL_HOSTNAME"

# SPF
SPF_VALUE="v=spf1 mx a ip4:${SERVER_IP} ~all"

pdns_delete_record "$BASE_DOMAIN" "TXT"
pdns_add_record "$BASE_DOMAIN" "TXT" "$SPF_VALUE" 300 0

print_success "SPF record added."

# DMARC
DMARC_NAME="_dmarc.${BASE_DOMAIN}"
DMARC_VALUE="v=DMARC1; p=none;"

pdns_delete_record "$DMARC_NAME" "TXT"
pdns_add_record "$DMARC_NAME" "TXT" "$DMARC_VALUE" 300 0

print_success "DMARC record added."

# ------------------------------------------------------------
# DNS Verification
# ------------------------------------------------------------

print_step "Checking DNS records"

echo
echo "A record:"
dig +short A "$MAIL_HOSTNAME" || true

echo
echo "MX record:"
dig +short MX "$BASE_DOMAIN" || true

echo
echo "SPF:"
dig +short TXT "$BASE_DOMAIN" || true

echo
echo "DMARC:"
dig +short TXT "$DMARC_NAME" || true

# ------------------------------------------------------------
# Caddy Installation
# ------------------------------------------------------------

print_step "Checking Caddy"

if command_exists caddy; then

    print_success "Caddy is already installed."

else

    print_info "Caddy is not installed."
    print_info "Installing Caddy..."

    apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl

    curl -1sLf \
        'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor \
        -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg

    curl -1sLf \
        'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        > /etc/apt/sources.list.d/caddy-stable.list

    apt-get update

    apt-get install -y caddy

    print_success "Caddy installed."
fi

# ------------------------------------------------------------
# Caddy Configuration
# ------------------------------------------------------------

print_step "Configuring Caddy"

CADDY_MAIN="/etc/caddy/Caddyfile"
CADDY_DIR="/etc/caddy/sites"
CADDY_SITE="$CADDY_DIR/stalwart.caddy"

mkdir -p "$CADDY_DIR"

# Backup existing Caddyfile
if [[ -f "$CADDY_MAIN" ]]; then
    cp "$CADDY_MAIN" \
       "$BACKUP_DIR/Caddyfile.$(date +%Y%m%d-%H%M%S).bak"
fi

# Create Stalwart site config
cat > "$CADDY_SITE" <<EOF
$MAIL_HOSTNAME {
    reverse_proxy 127.0.0.1:8080
}
EOF

chmod 644 "$CADDY_SITE"

# Make sure import exists
if [[ -f "$CADDY_MAIN" ]]; then

    if ! grep -Fq 'import /etc/caddy/sites/*.caddy' "$CADDY_MAIN"; then
        printf '\nimport /etc/caddy/sites/*.caddy\n' >> "$CADDY_MAIN"
    fi

else

    cat > "$CADDY_MAIN" <<EOF
{
}

import /etc/caddy/sites/*.caddy
EOF

fi

# Validate Caddy configuration
if caddy validate --config "$CADDY_MAIN"; then
    print_success "Caddy configuration is valid."
else
    print_error "Caddy configuration validation failed."

    echo
    print_warning "Your original Caddyfile backup is available in:"
    echo "$BACKUP_DIR"

    exit 1
fi

# ------------------------------------------------------------
# Caddy Restart
# ------------------------------------------------------------

systemctl enable caddy >/dev/null 2>&1 || true

systemctl restart caddy

sleep 2

if systemctl is-active --quiet caddy; then
    print_success "Caddy is running."
else
    print_error "Caddy failed to start."

    systemctl status caddy --no-pager || true

    exit 1
fi

# ------------------------------------------------------------
# UFW Firewall
# ------------------------------------------------------------

print_step "Configuring UFW firewall"

# SSH
if ss -lntp 2>/dev/null | grep -q ':2222 '; then
    ufw allow 2222/tcp comment 'SSH' >/dev/null 2>&1 || true
    print_success "SSH port 2222 allowed."
else
    ufw allow 22/tcp comment 'SSH' >/dev/null 2>&1 || true
    print_success "SSH port 22 allowed."
fi

# Web
ufw allow 80/tcp comment 'HTTP' >/dev/null 2>&1 || true
ufw allow 443/tcp comment 'HTTPS' >/dev/null 2>&1 || true

# SMTP
ufw allow 25/tcp comment 'SMTP' >/dev/null 2>&1 || true
ufw allow 465/tcp comment 'SMTPS' >/dev/null 2>&1 || true
ufw allow 587/tcp comment 'Submission' >/dev/null 2>&1 || true

# IMAP
ufw allow 143/tcp comment 'IMAP' >/dev/null 2>&1 || true
ufw allow 993/tcp comment 'IMAPS' >/dev/null 2>&1 || true

# POP3
ufw allow 110/tcp comment 'POP3' >/dev/null 2>&1 || true
ufw allow 995/tcp comment 'POP3S' >/dev/null 2>&1 || true

# ManageSieve
ufw allow 4190/tcp comment 'ManageSieve' >/dev/null 2>&1 || true

# IMPORTANT:
# Do not expose Stalwart bootstrap port 8080 publicly.
# Caddy handles public HTTPS.
ufw delete allow 8080/tcp >/dev/null 2>&1 || true

# Enable firewall if inactive
if ufw status | grep -q "Status: inactive"; then
    ufw --force enable
fi

print_success "UFW firewall configured."

# ------------------------------------------------------------
# Service Status
# ------------------------------------------------------------

print_step "Final service checks"

echo
echo "----------------------------------------"
echo "Stalwart"
echo "----------------------------------------"

systemctl status "$STALWART_SERVICE" \
    --no-pager \
    --lines=10 || true

echo
echo "----------------------------------------"
echo "Caddy"
echo "----------------------------------------"

systemctl status caddy \
    --no-pager \
    --lines=10 || true

echo
echo "----------------------------------------"
echo "Listening Ports"
echo "----------------------------------------"

ss -lntp | grep -E \
    ':(25|80|110|143|443|465|587|993|995|4190|8080)\b' \
    || true

# ------------------------------------------------------------
# DNS / Port Checks
# ------------------------------------------------------------

print_step "Checking local HTTP listener"

if curl -fsS \
    --max-time 5 \
    http://127.0.0.1:8080/ \
    >/dev/null 2>&1; then

    print_success "Stalwart HTTP listener is responding on 127.0.0.1:8080."

else

    print_warning "Could not verify Stalwart HTTP listener on port 8080."
    print_warning "Check:"
    echo "  journalctl -u $STALWART_SERVICE -n 100 --no-pager"
fi

# ------------------------------------------------------------
# Save Installation Information
# ------------------------------------------------------------

cat > "$INFO_FILE" <<EOF
Stalwart Mail Server Installation
=================================

Date:
$(date)

Mail hostname:
$MAIL_HOSTNAME

Base domain:
$BASE_DOMAIN

VPS public IPv4:
$SERVER_IP

Stalwart service:
$STALWART_SERVICE

Stalwart local HTTP:
http://127.0.0.1:8080

Public HTTPS:
https://$MAIL_HOSTNAME

Stalwart config:
/etc/stalwart/config.json

Stalwart environment:
/etc/stalwart/stalwart.env

Stalwart data:
/var/lib/stalwart/

Stalwart logs:
/var/log/stalwart/

Caddy config:
/etc/caddy/Caddyfile

Stalwart Caddy site:
/etc/caddy/sites/stalwart.caddy

PowerDNS database:
$PDNS_DB

PowerDNS database user:
$PDNS_DB_USER

DNS:
A      $MAIL_HOSTNAME -> $SERVER_IP
MX     $BASE_DOMAIN -> $MAIL_HOSTNAME
SPF    $BASE_DOMAIN
DMARC  $DMARC_NAME

IMPORTANT:
1. Set PTR/reverse DNS at your VPS provider.
2. Make sure port 25 is not blocked by the VPS provider.
3. Configure DKIM from Stalwart after domain setup.
4. Keep DMARC at p=none initially.
5. For production, move to stricter DMARC after verification.
EOF

chmod 600 "$INFO_FILE"

# ------------------------------------------------------------
# Final Output
# ------------------------------------------------------------

echo
echo "============================================================"
echo "                 INSTALLATION COMPLETE"
echo "============================================================"
echo

print_success "Stalwart Mail Server is installed."
print_success "Caddy is configured."
print_success "PowerDNS records were configured."
print_success "UFW firewall was configured."

echo
echo "Mail hostname:"
echo "  https://$MAIL_HOSTNAME"

echo
echo "Stalwart admin:"
echo "  https://$MAIL_HOSTNAME/admin"

echo
echo "Information file:"
echo "  $INFO_FILE"

echo
echo "IMPORTANT NEXT STEPS:"
echo
echo "1. Configure PTR / Reverse DNS:"
echo "   $SERVER_IP -> $MAIL_HOSTNAME"
echo
echo "2. Verify port 25 is open."
echo
echo "3. Open:"
echo "   https://$MAIL_HOSTNAME/admin"
echo
echo "4. Configure your mail domain."
echo
echo "5. Create your first mailbox."
echo
echo "6. Get the DKIM DNS record from Stalwart."
echo
echo "7. Add DKIM record to PowerDNS."
echo
echo "8. Test sending and receiving email."
echo

print_success "Setup finished successfully."
