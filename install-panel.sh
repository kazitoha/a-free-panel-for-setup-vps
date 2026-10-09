#!/usr/bin/env bash
#
# VPS Panel — bootstrap installer for a fresh Ubuntu server (22.04 / 24.04).
#
#   sudo bash install-panel.sh --domain=panel.example.com --email=you@example.com
#
# What it does:
#   PHP-FPM · MySQL · Composer · Caddy · Node (assets) · panel code ·
#   PowerDNS (HTTP API) · migrations · admin account · HTTPS site ·
#   sudo runner · UFW
#
# Re-running is safe: installed pieces are reused or refreshed in place.

set -euo pipefail

# ---------------------------------------------------------------
# Defaults & helpers
# ---------------------------------------------------------------

REPO_URL=""
BRANCH="main"
PANEL_DIR="/var/www/panel"
PHP_VERSION="8.3"
PANEL_DOMAIN=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
PDNS_NS_DOMAIN=""
MODE=""

# Works both from a checkout and piped (curl … | bash), where BASH_SOURCE is unset.
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" && pwd)"

info()  { echo -e "\033[1;34m[INFO]\033[0m $1"; }
ok()    { echo -e "\033[1;32m[ OK ]\033[0m $1"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m $1"; }
fail()  { echo -e "\033[1;31m[FAIL]\033[0m $1" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Usage: sudo bash install-panel.sh [options]

Options:
  --domain=HOST      Panel hostname (e.g. panel.example.com)
  --email=EMAIL      Administrator email
  --password=PASS    Administrator password (generated when omitted)
  --repo=URL         Git repository to install from
  --branch=NAME      Git branch (default: main)
  --php=VERSION      PHP version to install (default: 8.3)
  --ns-domain=HOST   Nameserver domain for the DNS module (default: panel domain)
  --local            Install from the directory containing this script
  --non-interactive  Never prompt; fail when a required value is missing
  -h, --help         Show this help
USAGE
}

for argument in "$@"; do
    case "$argument" in
        --domain=*)       PANEL_DOMAIN="${argument#*=}" ;;
        --email=*)        ADMIN_EMAIL="${argument#*=}" ;;
        --password=*)     ADMIN_PASSWORD="${argument#*=}" ;;
        --repo=*)         REPO_URL="${argument#*=}" ;;
        --branch=*)       BRANCH="${argument#*=}" ;;
        --php=*)          PHP_VERSION="${argument#*=}" ;;
        --ns-domain=*)    PDNS_NS_DOMAIN="${argument#*=}" ;;
        --local)          MODE="local" ;;
        --non-interactive) NON_INTERACTIVE=1 ;;
        -h|--help)        usage; exit 0 ;;
        *)                usage >&2; fail "Unknown option: $argument" ;;
    esac
done

NON_INTERACTIVE="${NON_INTERACTIVE:-0}"

# Piped installs (curl | sudo bash) have no terminal — prompting would also
# swallow the script itself, so never read stdin in that mode.
if [[ ! -t 0 ]]; then
    NON_INTERACTIVE=1
fi
prompt() {
    # prompt VAR "Question" [default]
    local __var="$1" __question="$2" __default="${3:-}" __answer=""

    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' "$__default"
        return 0
    fi

    if [[ -n "$__default" ]]; then
        read -r -p "$__question [$__default]: " __answer
        __answer="${__answer:-$__default}"
    else
        read -r -p "$__question: " __answer
    fi

    printf -v "$__var" '%s' "$__answer"
}

prompt_secret() {
    local __var="$1" __question="$2" __answer=""

    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' ""
        return 0
    fi

    read -r -s -p "$__question: " __answer
    echo
    printf -v "$__var" '%s' "$__answer"
}

# ---------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------

[[ "${EUID}" -eq 0 ]] || fail "Run this script as root: sudo bash install-panel.sh"

if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ "${ID:-}" == "ubuntu" ]] || warn "This installer targets Ubuntu. Detected: ${ID:-unknown}."
    info "Detected ${PRETTY_NAME:-Ubuntu}."
else
    warn "/etc/os-release missing — cannot detect distribution."
fi

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || fail "Invalid --php value: $PHP_VERSION"
[[ -z "$PANEL_DOMAIN" || "$PANEL_DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid --domain value: $PANEL_DOMAIN"
[[ -z "$PDNS_NS_DOMAIN" || "$PDNS_NS_DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid --ns-domain value: $PDNS_NS_DOMAIN"

if [[ "$NON_INTERACTIVE" == "1" ]]; then
    [[ -n "$PANEL_DOMAIN" ]] || fail "--domain is required in non-interactive mode."
    [[ -n "$ADMIN_EMAIL"  ]] || fail "--email is required in non-interactive mode."
fi

# ---------------------------------------------------------------
# Collect settings
# ---------------------------------------------------------------

echo "========================================================="
echo "         VPS PANEL — INSTALLATION WIZARD"
echo "========================================================="

if [[ -z "$PANEL_DOMAIN" ]]; then
    prompt PANEL_DOMAIN "Panel domain (DNS A record must point here)"
    [[ -n "$PANEL_DOMAIN" ]] || fail "Panel domain is required."
fi

if [[ -z "$ADMIN_EMAIL" ]]; then
    prompt ADMIN_EMAIL "Administrator email"
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || fail "Invalid email address."
fi

if [[ -z "$ADMIN_PASSWORD" && "$NON_INTERACTIVE" != "1" ]]; then
    prompt_secret ADMIN_PASSWORD "Administrator password (blank = generate)"
fi

if [[ -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(openssl rand -hex 12)"
    GENERATED_PASSWORD=1
else
    GENERATED_PASSWORD=0
fi

# Resolve the panel source: local checkout or git repository.
if [[ -z "$MODE" ]]; then
    if [[ -f "$BASE_DIR/artisan" ]]; then
        MODE="local"
    else
        MODE="git"
    fi
fi

if [[ "$MODE" == "git" && -z "$REPO_URL" && -d "$BASE_DIR/.git" ]]; then
    REPO_URL="$(git -C "$BASE_DIR" remote get-url origin 2>/dev/null || true)"
fi

if [[ "$MODE" == "git" && -z "$REPO_URL" ]]; then
    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        fail "--repo is required when this script does not live inside the panel checkout."
    fi
    prompt REPO_URL "Panel git repository URL"
    [[ -n "$REPO_URL" ]] || fail "Repository URL is required."
fi

# ---------------------------------------------------------------
# System packages
# ---------------------------------------------------------------

info "Updating package index..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

info "Installing base packages..."
apt-get install -y -qq \
    ca-certificates curl wget git unzip zip jq dnsutils lsb-release \
    apt-transport-https gnupg ufw openssl rsync mysql-client \
    software-properties-common

# PHP: fall back to the Ondrej PPA when the requested version is not packaged.
if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    info "Adding Ondrej PHP repository for PHP ${PHP_VERSION}..."
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi

info "Installing PHP ${PHP_VERSION} and extensions..."
apt-get install -y -qq \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-sqlite3"

info "Installing MySQL server..."
apt-get install -y -qq mysql-server

if ! command -v composer >/dev/null 2>&1; then
    info "Installing Composer..."
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

if ! command -v caddy >/dev/null 2>&1; then
    info "Installing Caddy..."
    apt-get install -y -qq debian-keyring debian-archive-keyring
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -qq
    apt-get install -y -qq caddy
fi

if ! command -v node >/dev/null 2>&1; then
    info "Installing Node.js (for panel assets)..."
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >/dev/null
    apt-get install -y -qq nodejs
fi

# ---------------------------------------------------------------
# Panel code
# ---------------------------------------------------------------

if [[ "$MODE" == "local" ]]; then
    info "Copying panel code from ${BASE_DIR}..."
    mkdir -p "$PANEL_DIR"
    rsync -a --delete \
        --exclude '.git' \
        --exclude '.env' \
        --exclude 'node_modules' \
        --exclude 'storage/framework/*' \
        --exclude 'storage/logs/*' \
        --exclude 'storage/panel.sqlite' \
        --exclude 'database/*.sqlite*' \
        "$BASE_DIR/" "$PANEL_DIR/"
else
    if [[ -d "$PANEL_DIR/.git" ]]; then
        info "Updating existing checkout (branch ${BRANCH})..."
        git -C "$PANEL_DIR" fetch --quiet origin
        git -C "$PANEL_DIR" checkout --quiet "$BRANCH"
        git -C "$PANEL_DIR" pull --quiet --ff-only origin "$BRANCH" || warn "Fast-forward pull failed; keeping current code."
    else
        info "Cloning ${REPO_URL} (branch ${BRANCH})..."
        rm -rf "$PANEL_DIR"
        git clone --quiet --branch "$BRANCH" "$REPO_URL" "$PANEL_DIR"
    fi
fi

cd "$PANEL_DIR"

# ---------------------------------------------------------------
# Secrets (idempotent)
# ---------------------------------------------------------------

SECRETS_FILE="/root/.panel-secrets"
if [[ ! -f "$SECRETS_FILE" ]]; then
    MYSQL_ROOT_PASSWORD="$(openssl rand -hex 16)"
    PDNS_DB_PASSWORD="$(openssl rand -hex 16)"
    PDNS_API_KEY="$(openssl rand -hex 24)"
    umask 077
    cat > "$SECRETS_FILE" <<EOF
# Generated by install-panel.sh — keep private (chmod 600).
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
PDNS_DB_PASSWORD=${PDNS_DB_PASSWORD}
PDNS_API_KEY=${PDNS_API_KEY}
EOF
    chmod 600 "$SECRETS_FILE"
else
    # shellcheck disable=SC1090
    source "$SECRETS_FILE"
    [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]] || fail "${SECRETS_FILE} does not define MYSQL_ROOT_PASSWORD."

    # Secrets files from older installs predate the DNS module — add what is missing.
    if [[ -z "${PDNS_DB_PASSWORD:-}" ]]; then
        PDNS_DB_PASSWORD="$(openssl rand -hex 16)"
        echo "PDNS_DB_PASSWORD=${PDNS_DB_PASSWORD}" >> "$SECRETS_FILE"
    fi
    if [[ -z "${PDNS_API_KEY:-}" ]]; then
        PDNS_API_KEY="$(openssl rand -hex 24)"
        echo "PDNS_API_KEY=${PDNS_API_KEY}" >> "$SECRETS_FILE"
    fi
fi

if MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "SELECT 1;" >/dev/null 2>&1; then
    ok "MySQL root password already configured."
else
    info "Setting MySQL root password..."
    mysql --protocol=socket -uroot <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PASSWORD}';
FLUSH PRIVILEGES;
SQL
    ok "MySQL root password set (stored in ${SECRETS_FILE})."
fi

# ---------------------------------------------------------------
# PowerDNS (authoritative server + localhost HTTP API)
# ---------------------------------------------------------------

PDNS_NS_DOMAIN="${PDNS_NS_DOMAIN:-${PANEL_DOMAIN}}"
PDNS_API_URL="http://127.0.0.1:8081"

if [[ -z "${PDNS_SERVER_IP:-}" ]]; then
    PDNS_SERVER_IP="$(curl -fsS --max-time 5 -4 https://api.ipify.org 2>/dev/null || true)"
fi
if [[ -z "${PDNS_SERVER_IP:-}" ]]; then
    PDNS_SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi
PDNS_SERVER_IP="${PDNS_SERVER_IP:-}"
[[ -n "$PDNS_SERVER_IP" ]] || warn "Could not detect the server IP — set PDNS_SERVER_IP in .env and re-run to seed zones."

# systemd-resolved squats on 127.0.0.53:53 — free port 53 before pdns starts.
if systemctl is-active --quiet systemd-resolved 2>/dev/null \
    && grep -Eq '^#?DNSStubListener=' /etc/systemd/resolved.conf 2>/dev/null; then
    info "Disabling the systemd-resolved stub listener (port 53 → PowerDNS)..."
    sed -i 's/^#\?DNSStubListener=.*/DNSStubListener=no/' /etc/systemd/resolved.conf
    rm -f /etc/resolv.conf
    ln -s /run/systemd/resolve/resolv.conf /etc/resolv.conf
    systemctl restart systemd-resolved >/dev/null 2>&1 || true
fi

info "Installing PowerDNS authoritative server..."
apt-get install -y -qq pdns-server pdns-backend-mysql

info "Configuring PowerDNS database..."
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS pdns;
CREATE USER IF NOT EXISTS 'pdns_user'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
ALTER USER 'pdns_user'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON pdns.* TO 'pdns_user'@'localhost';
FLUSH PRIVILEGES;
SQL

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot pdns <<'SQL'
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
  UNIQUE KEY name_algo_index (name, algorithm)
) Engine=InnoDB;
SQL

# pdns.conf: write the full config on a fresh install, otherwise only make
# sure the HTTP API block exists on top of whatever is already configured.
if ! grep -Eq '^[[:space:]]*launch=' /etc/powerdns/pdns.conf 2>/dev/null; then
    info "Writing PowerDNS configuration..."
    cat > /etc/powerdns/pdns.conf <<EOF
# PowerDNS — written by install-panel.sh (re-run to refresh).
launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-user=pdns_user
gmysql-password=${PDNS_DB_PASSWORD}
gmysql-dbname=pdns

local-address=0.0.0.0
local-port=53
master=yes
daemon=yes
guardian=yes

# HTTP API — used by the panel (localhost only).
webserver-address=127.0.0.1
webserver-allow-from=127.0.0.1
webserver-port=8081
api=yes
api-key=${PDNS_API_KEY}
EOF
    chmod 644 /etc/powerdns/pdns.conf
elif ! grep -Eq '^[[:space:]]*api[[:space:]]*=' /etc/powerdns/pdns.conf; then
    info "Enabling the PowerDNS HTTP API on the existing configuration..."
    cat >> /etc/powerdns/pdns.conf <<EOF

# HTTP API — appended by install-panel.sh.
webserver-address=127.0.0.1
webserver-allow-from=127.0.0.1
webserver-port=8081
api=yes
api-key=${PDNS_API_KEY}
EOF
fi

info "Starting PowerDNS..."
systemctl enable pdns >/dev/null 2>&1 || true
systemctl restart pdns

PDNS_UP=0
for _ in $(seq 1 30); do
    if curl -fsS --max-time 2 -H "X-API-Key: ${PDNS_API_KEY}" \
        "${PDNS_API_URL}/api/v1/servers/localhost" >/dev/null 2>&1; then
        PDNS_UP=1
        break
    fi
    sleep 1
done

if [[ "$PDNS_UP" == "1" ]]; then
    ok "PowerDNS API is listening on 127.0.0.1:8081."
else
    warn "PowerDNS API did not answer — check 'systemctl status pdns' and 'journalctl -u pdns'."
fi

seed_pdns_zone() {
    local zone="$1"
    local zone_url="${PDNS_API_URL}/api/v1/servers/localhost/zones/${zone}."
    local serial ns1 ns2 payload code

    if curl -fsS --max-time 3 -H "X-API-Key: ${PDNS_API_KEY}" "$zone_url" >/dev/null 2>&1; then
        ok "DNS zone ${zone} already exists."
        return 0
    fi

    if [[ -z "$PDNS_SERVER_IP" ]]; then
        warn "Skipping zone ${zone}: PDNS_SERVER_IP is empty."
        return 0
    fi

    serial="$(date -u +%Y%m%d%H)"
    ns1="ns1.${PDNS_NS_DOMAIN}."
    ns2="ns2.${PDNS_NS_DOMAIN}."

    payload="$(jq -n \
        --arg zone "${zone}." \
        --arg ip "${PDNS_SERVER_IP}" \
        --arg ns1 "$ns1" \
        --arg ns2 "$ns2" \
        --arg soa "${ns1} admin.${PDNS_NS_DOMAIN}. ${serial} 10800 3600 604800 3600" \
        --argjson glue "$([[ "$zone" == "$PDNS_NS_DOMAIN" ]] && echo true || echo false)" \
        '{
            name: $zone, kind: "Native", masters: [],
            rrsets: (
                [
                    {name: $zone, type: "SOA", ttl: 86400, records: [{content: $soa, disabled: false}]},
                    {name: $zone, type: "NS", ttl: 86400, records: [{content: $ns1, disabled: false}, {content: $ns2, disabled: false}]},
                    {name: $zone, type: "A", ttl: 3600, records: [{content: $ip, disabled: false}]},
                    {name: ("*" + $zone), type: "A", ttl: 3600, records: [{content: $ip, disabled: false}]}
                ] + (if $glue then
                    [
                        {name: $ns1, type: "A", ttl: 3600, records: [{content: $ip, disabled: false}]},
                        {name: $ns2, type: "A", ttl: 3600, records: [{content: $ip, disabled: false}]}
                    ]
                else [] end)
            )
        }')"

    code="$(curl -sS --max-time 5 -o /tmp/pdns-seed.json -w '%{http_code}' \
        -H "X-API-Key: ${PDNS_API_KEY}" -H 'Content-Type: application/json' \
        -d "$payload" "$zone_url" || true)"
    code="${code:-000}"

    if [[ "$code" =~ ^(200|201|204)$ ]]; then
        ok "Seeded DNS zone ${zone}."
    else
        warn "Could not seed zone ${zone} (HTTP ${code}): $(jq -r '.errors[0].message // .error // .message // empty' /tmp/pdns-seed.json 2>/dev/null || true)"
    fi
}

if [[ "$PDNS_UP" == "1" ]]; then
    seed_pdns_zone "$PDNS_NS_DOMAIN"
    if [[ -n "$PANEL_DOMAIN" && "$PANEL_DOMAIN" != "$PDNS_NS_DOMAIN" ]]; then
        seed_pdns_zone "$PANEL_DOMAIN"
    fi
fi

# ---------------------------------------------------------------
# Laravel application
# ---------------------------------------------------------------

info "Installing PHP dependencies..."
composer install --quiet --no-dev --optimize-autoloader --no-interaction --working-dir="$PANEL_DIR"

info "Writing .env..."
EXISTING_KEY=""
EXISTING_STALWART=()
if [[ -f "$PANEL_DIR/.env" ]]; then
    EXISTING_KEY="$(grep -E '^APP_KEY=.+' "$PANEL_DIR/.env" | head -1 | cut -d= -f2- || true)"
    # The Email module writes STALWART_* credentials once Stalwart is
    # installed — keep them across re-runs (values may contain $ or
    # backticks, so they are appended after the heredoc instead of
    # being interpolated into it).
    mapfile -t EXISTING_STALWART < <(grep -E '^STALWART_(URL|USER|PASSWORD|HOST)=' "$PANEL_DIR/.env" || true)
fi

rm -f "$PANEL_DIR/.env"
cat > "$PANEL_DIR/.env" <<EOF
APP_NAME="VPS Panel"
APP_ENV=production
APP_KEY=${EXISTING_KEY}
APP_DEBUG=false
APP_URL=https://${PANEL_DOMAIN}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=sqlite
DB_DATABASE=${PANEL_DIR}/storage/panel.sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="panel@${PANEL_DOMAIN}"
MAIL_FROM_NAME="\${APP_NAME}"

PANEL_DOMAIN=${PANEL_DOMAIN}
PANEL_PHP=${PHP_VERSION}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
PDNS_API_URL=${PDNS_API_URL}
PDNS_API_KEY=${PDNS_API_KEY}
PDNS_NS_DOMAIN=${PDNS_NS_DOMAIN}
PDNS_SERVER_IP=${PDNS_SERVER_IP}
EOF
if ((${#EXISTING_STALWART[@]})); then
    printf '%s\n' "${EXISTING_STALWART[@]}" >> "$PANEL_DIR/.env"
fi
chmod 640 "$PANEL_DIR/.env"

info "Generating application key..."
if ! grep -qE '^APP_KEY=.+' "$PANEL_DIR/.env"; then
    php artisan key:generate --force --no-interaction
fi

if [[ ! -f "$PANEL_DIR/storage/panel.sqlite" ]]; then
    install -m 660 -o root -g www-data /dev/null "$PANEL_DIR/storage/panel.sqlite"
fi

info "Running migrations..."
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction >/dev/null 2>&1 || true

info "Creating administrator ${ADMIN_EMAIL}..."
php artisan panel:admin:create "$ADMIN_EMAIL" \
    --password="$ADMIN_PASSWORD" \
    --name="Admin" \
    --no-interaction

# Store the panel login next to the other secrets so a re-run (or a lost
# terminal scrollback) can always recover it.
upsert_secret() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp)"
    if grep -q "^${key}=" "$SECRETS_FILE" 2>/dev/null; then
        awk -v k="$key" -v v="$value" \
            'BEGIN{done=0} $0 ~ "^"k"=" {if(!done){print k"="v; done=1; next}} {print}' \
            "$SECRETS_FILE" > "$tmp"
    else
        cat "$SECRETS_FILE" > "$tmp" 2>/dev/null || true
        echo "${key}=${value}" >> "$tmp"
    fi
    install -m 600 -o root -g root "$tmp" "$SECRETS_FILE"
    rm -f "$tmp"
}

upsert_secret PANEL_ADMIN_EMAIL "$ADMIN_EMAIL"
upsert_secret PANEL_ADMIN_PASSWORD "$ADMIN_PASSWORD"
chmod 600 "$SECRETS_FILE"
ok "Administrator saved to ${SECRETS_FILE}."

# ---------------------------------------------------------------
# Frontend assets
# ---------------------------------------------------------------

if [[ ! -f "$PANEL_DIR/public/build/manifest.json" ]]; then
    info "Building frontend assets..."
    npm install --silent --ignore-scripts
    npm run build --silent
else
    ok "Frontend assets already built."
fi

# ---------------------------------------------------------------
# Ownership: code is root-owned; only runtime paths are writable
# ---------------------------------------------------------------

chown -R root:root "$PANEL_DIR"
chown -R www-data:www-data "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"
chgrp www-data "$PANEL_DIR/.env" "$PANEL_DIR/storage/panel.sqlite"
chmod 640 "$PANEL_DIR/.env" "$PANEL_DIR/storage/panel.sqlite"
chmod 750 "$PANEL_DIR/bootstrap/cache"

# ---------------------------------------------------------------
# Caddy site
# ---------------------------------------------------------------

info "Configuring Caddy for https://${PANEL_DOMAIN}..."

mkdir -p /etc/caddy/sites
cat > "/etc/caddy/sites/panel.caddy" <<EOF
${PANEL_DOMAIN} {
    root * ${PANEL_DIR}/public
    encode zstd gzip
    php_fastcgi unix//run/php/php${PHP_VERSION}-fpm.sock
    file_server
}
EOF
chmod 644 "/etc/caddy/sites/panel.caddy"

if [[ ! -f /etc/caddy/Caddyfile ]]; then
    printf '{}\n\nimport /etc/caddy/sites/*.caddy\n' > /etc/caddy/Caddyfile
elif ! grep -Fq 'import /etc/caddy/sites/*.caddy' /etc/caddy/Caddyfile; then
    printf '\nimport /etc/caddy/sites/*.caddy\n' >> /etc/caddy/Caddyfile
fi

if caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1; then
    ok "Caddyfile is valid."
else
    fail "Caddyfile validation failed — the panel site was not activated."
fi

# ---------------------------------------------------------------
# Sudo runner
# ---------------------------------------------------------------

info "Installing privileged runner..."
install -m 755 -o root -g root "$PANEL_DIR/scripts/run.sh" /usr/local/panel/run

if visudo -cf "$PANEL_DIR/scripts/panel.sudoers" >/dev/null 2>&1; then
    install -m 440 -o root -g root "$PANEL_DIR/scripts/panel.sudoers" /etc/sudoers.d/panel
    ok "Sudo rule installed for www-data → /usr/local/panel/run."
else
    fail "Sudoers file failed validation."
fi

# ---------------------------------------------------------------
# Services & firewall
# ---------------------------------------------------------------

info "Enabling services..."
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true
systemctl enable --now mysql >/dev/null 2>&1 || true
systemctl enable --now caddy >/dev/null 2>&1 || true
systemctl enable --now pdns >/dev/null 2>&1 || true
systemctl restart "php${PHP_VERSION}-fpm" caddy

# Open the panel in UFW without locking the administrator out.
SSH_PORT="$(ss -lntpH 2>/dev/null | awk '/:22 |:22$/ {print $4}' | head -1 | sed 's/.*://' || true)"
SSH_PORT="${SSH_PORT:-22}"

ufw allow "${SSH_PORT}/tcp" comment 'SSH' >/dev/null 2>&1 || true
ufw allow 80/tcp  comment 'HTTP'  >/dev/null 2>&1 || true
ufw allow 443/tcp comment 'HTTPS' >/dev/null 2>&1 || true
ufw allow 53/tcp  comment 'DNS'   >/dev/null 2>&1 || true
ufw allow 53/udp  comment 'DNS'   >/dev/null 2>&1 || true

if ufw status | grep -q 'Status: inactive'; then
    ufw --force enable >/dev/null
fi
ok "Firewall allows SSH (${SSH_PORT}), 80, 443 and DNS (53)."

# ---------------------------------------------------------------
# Summary
# ---------------------------------------------------------------

echo
echo "============================================================"
echo "                 PANEL INSTALLED"
echo "============================================================"
echo
echo "  URL:      https://${PANEL_DOMAIN}"
echo "  Login:    ${ADMIN_EMAIL}"
if [[ "$GENERATED_PASSWORD" == "1" ]]; then
    echo "  Password: ${ADMIN_PASSWORD}   (change it after first login)"
else
    echo "  Password: (the one you entered)"
fi
echo "  Saved:    ${SECRETS_FILE} (PANEL_ADMIN_EMAIL / PANEL_ADMIN_PASSWORD)"
echo
echo "  App dir:  ${PANEL_DIR}"
echo "  Secrets:  ${SECRETS_FILE}"
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "  Nameservers: ns1.${PDNS_NS_DOMAIN} / ns2.${PDNS_NS_DOMAIN} → ${PDNS_SERVER_IP}"
fi
echo
echo "  Next steps:"
echo "  1. Point DNS: ${PANEL_DOMAIN} → this server's public IP"
echo "     (Caddy issues the certificate automatically once DNS resolves)"
echo "  2. Open https://${PANEL_DOMAIN} and sign in"
echo "  3. For the DNS module: register ns1.${PDNS_NS_DOMAIN} / ns2.${PDNS_NS_DOMAIN}"
echo "     with your registrar, pointing both at ${PDNS_SERVER_IP:-unknown}"
echo "  4. Install other modules (Sites, Email, phpMyAdmin) from the panel"
echo
echo "  Re-run this script any time to update the panel."
echo "============================================================"
