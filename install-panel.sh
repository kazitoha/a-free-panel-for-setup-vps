#!/usr/bin/env bash
#
# VPS Panel — bootstrap installer for a fresh Ubuntu server (22.04 / 24.04).
#
#   sudo bash install-panel.sh --domain=panel.example.com --email=you@example.com
#
# What it does:
#   PHP-FPM · Composer · Caddy (domain + SERVER_IP:PORT) · Node (assets) ·
#   panel code · migrations · admin account · sudo runner · UFW
#
# MySQL, PowerDNS and the mail server are NOT installed here: the panel's
# own data lives in SQLite, and each module installs its server on demand
# from the web interface (Databases / DNS / Email), generating whatever
# credentials it needs along the way.
#
# Re-running is safe: installed pieces are reused or refreshed in place.

set -Eeuo pipefail

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
CODE_ACTION=""
OVERWRITE_BACKUP_DIR=""
PANEL_PORT=""
SETUP_STEPS=()

# Works both from a checkout and piped (curl … | bash), where BASH_SOURCE is unset.
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" && pwd)"

info()  { echo -e "\033[1;34m[INFO]\033[0m $1"; }
ok()    { echo -e "\033[1;32m[ OK ]\033[0m $1"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m $1"; }
fail()  { echo -e "\033[1;31m[FAIL]\033[0m $1" >&2; exit 1; }

# `set -e` alone stops the installer without saying which command died —
# name it, so the log above it finally makes sense.
installer_error() {
    local __status="$?" __line="$1" __cmd="$2"
    echo -e "\n\033[1;31m[FAIL]\033[0m install-panel.sh: line ${__line} — command failed (exit ${__status})" >&2
    echo -e "       ${__cmd}" >&2
    echo -e "       Nothing after this line ran. Fix the error above and re-run the installer." >&2
    exit "$__status"
}
trap 'installer_error "$LINENO" "$BASH_COMMAND"' ERR

usage() {
    cat <<'USAGE'
Usage: sudo bash install-panel.sh [options]

Options:
  --domain=HOST      Panel hostname (e.g. panel.example.com)
  --email=EMAIL      Administrator email (when omitted, the account is created
                     on the setup page the panel shows after the install)
  --password=PASS    Administrator password (min 8 characters; generated when omitted)
  --repo=URL         Git repository to install from
  --branch=NAME      Git branch (default: main)
  --code=ACTION      When a panel already exists: update (default) — keep it and
                     fast-forward; overwrite — delete the project and install
                     fresh (files are backed up first); skip — leave the
                     existing code untouched
  --php=VERSION      PHP version to install (default: 8.3)
  --ns-domain=HOST   Nameserver domain for the DNS module (default: panel domain)
  --port=PORT        Also serve the panel on SERVER_IP:PORT over plain HTTP
                     (default: 6669) — usable before DNS resolves
  --local            Install from the directory containing this script
  --non-interactive  Never prompt; fail when a required value is missing
  -h, --help         Show this help
USAGE
}

# Copy-paste from docs, chat apps or Word replaces real spaces with
# non-breaking (U+00A0) or zero-width (U+200B) spaces. The shell does not
# treat those as separators, so a whole pasted command line arrives glued
# together and dies as an "Unknown option:" that shows nothing on screen.
# Split those back into real arguments; a value that already contains a
# real space (--password='a b') is never touched.
split_invisible_spaces() {
    local __arg __v __part
    local -a __parts

    NORMALIZED_ARGS=()

    for __arg in "$@"; do
        __v="${__arg//$'\xc2\xa0'/ }"
        __v="${__v//$'\xe2\x80\x8b'/ }"

        if [[ "$__v" != "$__arg" && "$__arg" != *" "* ]]; then
            __parts=()
            read -r -a __parts <<< "$__v"

            for __part in ${__parts[@]+"${__parts[@]}"}; do
                if [[ -n "$__part" ]]; then
                    NORMALIZED_ARGS+=("$__part")
                fi
            done
        else
            NORMALIZED_ARGS+=("$__arg")
        fi
    done
}

# Show invisible characters instead of printing them to a blank-looking slot.
describe_argument() {
    local __shown="$1"
    __shown="${__shown//$'\xc2\xa0'/[NBSP]}"
    __shown="${__shown//$'\xe2\x80\x8b'/[ZWSP]}"
    printf '%q' "$__shown"
}

split_invisible_spaces "$@"
set -- ${NORMALIZED_ARGS[@]+"${NORMALIZED_ARGS[@]}"}

for argument in "$@"; do
    # Copy-paste from docs or chat often collapses "\" line breaks into plain
    # spaces, which turn into whitespace-only arguments — ignore those instead
    # of failing on an option nobody typed.
    if [[ "$argument" =~ ^[[:space:]]*$ ]]; then
        continue
    fi
    case "$argument" in
        --domain=*)       PANEL_DOMAIN="${argument#*=}" ;;
        --email=*)        ADMIN_EMAIL="${argument#*=}" ;;
        --password=*)     ADMIN_PASSWORD="${argument#*=}" ;;
        --repo=*)         REPO_URL="${argument#*=}" ;;
        --branch=*)       BRANCH="${argument#*=}" ;;
        --code=*)         CODE_ACTION="${argument#*=}" ;;
        --php=*)          PHP_VERSION="${argument#*=}" ;;
        --ns-domain=*)    PDNS_NS_DOMAIN="${argument#*=}" ;;
        --port=*)         PANEL_PORT="${argument#*=}" ;;
        --local)          MODE="local" ;;
        --non-interactive) NON_INTERACTIVE=1 ;;
        -h|--help)        usage; exit 0 ;;
        *)                usage >&2
                          fail "Unknown option: $(describe_argument "$argument")
       If this came from a copy-pasted command, invisible spaces (shown as
       [NBSP]/[ZWSP] above) or a broken line break are the usual cause —
       retype the command instead of pasting it." ;;
    esac
done

NON_INTERACTIVE="${NON_INTERACTIVE:-0}"
PANEL_PORT="${PANEL_PORT:-6669}"
[[ "$PANEL_PORT" =~ ^[0-9]+$ && "$PANEL_PORT" -ge 1 && "$PANEL_PORT" -le 65535 ]] \
    || fail "Invalid --port value: ${PANEL_PORT} (1-65535)."

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
        read -r -p "$__question [$__default]: " __answer || __answer=""
        __answer="${__answer:-$__default}"
    else
        read -r -p "$__question: " __answer || __answer=""
    fi

    printf -v "$__var" '%s' "$__answer"
}

prompt_secret() {
    local __var="$1" __question="$2" __answer=""

    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' ""
        return 0
    fi

    read -r -s -p "$__question: " __answer || __answer=""
    echo
    printf -v "$__var" '%s' "$__answer"
}

# ---------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------

[[ "${EUID}" -eq 0 ]] || fail "Run this script as root: sudo bash install-panel.sh"

# Every sudo call prints "unable to resolve host …" when the machine's
# hostname is missing from /etc/hosts (common on fresh VPS images).
HOST_SHORT="$(hostname 2>/dev/null || true)"
if [[ -n "$HOST_SHORT" ]] \
    && ! getent hosts "$HOST_SHORT" >/dev/null 2>&1 \
    && ! grep -qE "(^|[[:space:]])${HOST_SHORT}([[:space:]]|$)" /etc/hosts; then
    printf '127.0.0.1 %s\n' "$HOST_SHORT" >> /etc/hosts
    info "Mapped ${HOST_SHORT} to 127.0.0.1 in /etc/hosts."
fi

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
[[ -z "$CODE_ACTION" || "$CODE_ACTION" =~ ^(update|overwrite|skip)$ ]] \
    || fail "Invalid --code value: $CODE_ACTION (use update, overwrite or skip)."

if [[ "$NON_INTERACTIVE" == "1" ]]; then
    [[ -n "$PANEL_DOMAIN" ]] || fail "--domain is required in non-interactive mode."
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
    prompt ADMIN_EMAIL "Administrator email (blank = create the account on the setup page)"
fi

if [[ -n "$ADMIN_EMAIL" && -z "$ADMIN_PASSWORD" && "$NON_INTERACTIVE" != "1" ]]; then
    while :; do
        prompt_secret ADMIN_PASSWORD "Administrator password (blank = generate)"
        [[ -z "$ADMIN_PASSWORD" || ${#ADMIN_PASSWORD} -ge 8 ]] && break
        echo "  Password must be at least 8 characters."
    done
fi

if [[ -n "$ADMIN_EMAIL" && -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(openssl rand -hex 12)"
    GENERATED_PASSWORD=1
else
    GENERATED_PASSWORD=0
fi

# Credential rules enforced later by `panel:admin:create` — check them here,
# before any package is installed, instead of failing after ten minutes.
if [[ -n "$ADMIN_EMAIL" ]]; then
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] \
        || fail "Invalid --email value: ${ADMIN_EMAIL}"
    if [[ "$GENERATED_PASSWORD" -eq 0 && ${#ADMIN_PASSWORD} -lt 8 ]]; then
        fail "--password must be at least 8 characters (got ${#ADMIN_PASSWORD}) — pick a longer one and re-run."
    fi
else
    info "No administrator email given — the account is created on the setup page after this install."
fi
# --- end credential checks ---------------------------------------------------

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
# Existing installation
# ---------------------------------------------------------------

# An earlier install leaves a populated /var/www/panel. The administrator
# decides up front — before any package is touched — whether this run keeps it
# (update), wipes it (overwrite) or leaves the code alone (skip).
PANEL_EXISTS=0
if [[ -d "$PANEL_DIR" && -n "$(ls -A "$PANEL_DIR" 2>/dev/null || true)" ]]; then
    PANEL_EXISTS=1
fi

if [[ "$PANEL_EXISTS" -eq 1 && -z "$CODE_ACTION" ]]; then
    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        CODE_ACTION="update"
        info "Existing panel found at ${PANEL_DIR} — updating it in place (use --code=overwrite or --code=skip to change that)."
    else
        info "Existing panel installation found at ${PANEL_DIR}."
        echo "  update    keep everything and fast-forward the code (default)"
        echo "  overwrite delete the project and install fresh (backed up first)"
        echo "  skip      leave the existing code untouched"
        __code="" __answer=""
        while [[ -z "$__code" ]]; do
            read -r -p "What should I do with it? [update]: " __answer || true
            __answer="${__answer,,}"
            __answer="${__answer//[[:space:]]/}"
            __answer="${__answer:-update}"
            case "$__answer" in
                u|up|update)    __code="update" ;;
                o|ov|overwrite) __code="overwrite" ;;
                s|sk|skip)      __code="skip" ;;
                *)              echo "Please answer update, overwrite or skip." ;;
            esac
        done
        CODE_ACTION="$__code"
        unset __code __answer
    fi
fi

# Nothing to keep or wipe: a first install behaves like an update.
CODE_ACTION="${CODE_ACTION:-update}"

if [[ "$CODE_ACTION" == "skip" && "$PANEL_EXISTS" -eq 0 ]]; then
    warn "--code=skip: there is no panel at ${PANEL_DIR} — installing it instead."
    CODE_ACTION="update"
fi

# A shorthand such as owner/repo.git is not something git can clone. Catch it
# here, before the run gets halfway through, and say what a full URL looks like.
if [[ "$MODE" == "git" && -n "$REPO_URL" ]] \
    && ! [[ "$REPO_URL" =~ ^[a-zA-Z][a-zA-Z0-9+.-]*:// ]] \
    && ! [[ "$REPO_URL" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:.+ ]] \
    && ! [[ "$REPO_URL" =~ ^(\.?/|~) ]]; then
    if [[ -d "$PANEL_DIR/.git" && "$CODE_ACTION" != "overwrite" ]]; then
        warn "--repo '${REPO_URL}' is not a repository URL — ignoring it, ${PANEL_DIR} is an existing checkout."
        REPO_URL=""
    elif [[ "$REPO_URL" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+(\.git)?$ ]]; then
        fail "--repo must be a full URL — try --repo=https://github.com/${REPO_URL}"
    else
        fail "--repo must be a repository URL (https://…, git@host:path or a local path) — got: ${REPO_URL}"
    fi
fi

# ---------------------------------------------------------------
# System packages
# ---------------------------------------------------------------

# An earlier run may have added the Caddy Cloudsmith repository. Cloudsmith
# now answers HTTP 402 for it, which makes every `apt-get update` fail.
if [[ -f /etc/apt/sources.list.d/caddy-stable.list ]] \
    && grep -qF "dl.cloudsmith.io/public/caddy" /etc/apt/sources.list.d/caddy-stable.list; then
    info "Removing broken Caddy Cloudsmith repository..."
    rm -f /etc/apt/sources.list.d/caddy-stable.list \
        /usr/share/keyrings/caddy-stable-archive-keyring.gpg
fi

info "Updating package index..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

info "Installing base packages..."
apt-get install -y -qq \
    ca-certificates curl wget git unzip zip dnsutils lsb-release \
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

if ! command -v composer >/dev/null 2>&1; then
    info "Installing Composer..."
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

if ! command -v caddy >/dev/null 2>&1; then
    info "Installing Caddy..."

    # The Cloudsmith APT repository behind Caddy's official install docs now
    # returns 402 Payment Required, so fetch the signed release package from
    # GitHub instead.
    CADDY_ARCH="$(dpkg --print-architecture)"
    case "$CADDY_ARCH" in
        amd64|arm64|ppc64le|riscv64|s390x) ;;
        armhf) CADDY_ARCH="armv7" ;;
        armel) CADDY_ARCH="armv6" ;;
        *)     fail "Unsupported architecture for Caddy: ${CADDY_ARCH}" ;;
    esac

    CADDY_TAG="$(curl -fsSLI https://github.com/caddyserver/caddy/releases/latest \
        | tr -d '\r' \
        | awk 'tolower($1) == "location:" {url = $2} END {print url}' \
        | sed 's|.*/tag/||')"
    [[ "$CADDY_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] \
        || fail "Could not determine the latest Caddy release tag."

    CADDY_VERSION="${CADDY_TAG#v}"
    CADDY_DEB="caddy_${CADDY_VERSION}_linux_${CADDY_ARCH}.deb"
    CADDY_RELEASE="https://github.com/caddyserver/caddy/releases/download/${CADDY_TAG}"
    CADDY_TMP="$(mktemp -d)"
    trap 'rm -rf "$CADDY_TMP"' EXIT

    curl -fsSL -o "${CADDY_TMP}/${CADDY_DEB}" "${CADDY_RELEASE}/${CADDY_DEB}"
    curl -fsSL -o "${CADDY_TMP}/checksums.txt" "${CADDY_RELEASE}/caddy_${CADDY_VERSION}_checksums.txt"

    if ! (cd "$CADDY_TMP" \
        && awk -v name="$CADDY_DEB" '$2 == name' checksums.txt | sha512sum -c - >/dev/null); then
        fail "Checksum verification failed for ${CADDY_DEB}."
    fi

    apt-get install -y -qq "${CADDY_TMP}/${CADDY_DEB}"
    rm -rf "$CADDY_TMP"
    trap - EXIT
fi

if ! command -v node >/dev/null 2>&1; then
    info "Installing Node.js (for panel assets)..."
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >/dev/null
    apt-get install -y -qq nodejs
fi

# ---------------------------------------------------------------
# Panel code
# ---------------------------------------------------------------

# Back up what an overwrite cannot recreate: the environment (APP_KEY, module
# credentials) and the panel database. Everything else comes back from the repo.
backup_existing_checkout() {
    local stamp backup
    [[ -d "$PANEL_DIR" ]] || return 0
    stamp="$(date +%Y%m%d-%H%M%S)"
    backup="/root/panel-backups/overwrite-${stamp}"
    mkdir -p "$backup"
    if [[ -f "$PANEL_DIR/.env" ]]; then
        cp -a "$PANEL_DIR/.env" "$backup/.env"
    fi
    if [[ -f "$PANEL_DIR/storage/panel.sqlite" ]]; then
        cp -a "$PANEL_DIR/storage/panel.sqlite" "$backup/panel.sqlite"
    fi
    chmod 700 "$backup"
    OVERWRITE_BACKUP_DIR="$backup"
    warn "Backed up .env and panel database to ${backup} — restore them manually if you need the old data."
}

copy_local_code() {
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
}

if [[ "$MODE" == "local" ]]; then
    case "$CODE_ACTION" in
        skip)
            info "Keeping the existing panel code (--code=skip)."
            ;;
        overwrite)
            backup_existing_checkout
            if [[ "$BASE_DIR" == "$PANEL_DIR" ]]; then
                warn "The installer runs from ${PANEL_DIR} itself — keeping the code instead of deleting it."
            else
                info "Overwriting ${PANEL_DIR} with the code from ${BASE_DIR}..."
                rm -rf "$PANEL_DIR"
                copy_local_code
            fi
            ;;
        *)
            info "Copying panel code from ${BASE_DIR}..."
            copy_local_code
            ;;
    esac
else
    case "$CODE_ACTION" in
        skip)
            info "Keeping the existing panel code (--code=skip)."
            ;;
        overwrite)
            backup_existing_checkout
            info "Cloning ${REPO_URL} (branch ${BRANCH}) into a clean ${PANEL_DIR}..."
            rm -rf "$PANEL_DIR"
            git clone --quiet --branch "$BRANCH" "$REPO_URL" "$PANEL_DIR"
            ;;
        *)
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
            ;;
    esac
fi

# --code=skip (and a half-finished earlier run) may leave no application here;
# fail early instead of dying on the first artisan call below.
if [[ ! -f "$PANEL_DIR/artisan" ]]; then
    fail "${PANEL_DIR} does not contain a panel application (artisan missing) — re-run with --code=overwrite."
fi

cd "$PANEL_DIR"

# ---------------------------------------------------------------
# PowerDNS defaults (the server itself is installed from the panel)
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

# ---------------------------------------------------------------
# Laravel application
# ---------------------------------------------------------------

info "Installing PHP dependencies..."
# Scripts are skipped so a dependency's post-install hook cannot run as root
# here; the Laravel package manifest is rebuilt explicitly instead.
if ! composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --working-dir="$PANEL_DIR"; then
    fail "composer install failed in ${PANEL_DIR} — see the output above; if the lock file is out of sync, run 'composer update' there and re-run this installer."
fi

info "Discovering Laravel packages..."
if ! php artisan package:discover --ansi --no-interaction; then
    fail "php artisan package:discover failed — the panel cannot boot without its package manifest."
fi

info "Writing .env..."
EXISTING_KEY=""
EXISTING_STALWART=()
EXISTING_GOOGLE=()
if [[ -f "$PANEL_DIR/.env" ]]; then
    EXISTING_KEY="$(grep -E '^APP_KEY=.+' "$PANEL_DIR/.env" | head -1 | cut -d= -f2- || true)"
    # The Email module writes STALWART_* credentials once Stalwart is
    # installed — keep them across re-runs (values may contain $ or
    # backticks, so they are appended after the heredoc instead of
    # being interpolated into it).
    mapfile -t EXISTING_STALWART < <(grep -E '^STALWART_(URL|USER|PASSWORD|HOST)=' "$PANEL_DIR/.env" || true)
    # Optional Google sign-in credentials configured by the admin.
    mapfile -t EXISTING_GOOGLE < <(grep -E '^GOOGLE_(CLIENT_ID|CLIENT_SECRET)=' "$PANEL_DIR/.env" || true)
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
PANEL_UPDATE_BRANCH=${BRANCH}
MYSQL_ROOT_PASSWORD=
PDNS_API_URL=${PDNS_API_URL}
PDNS_API_KEY=
PDNS_NS_DOMAIN=${PDNS_NS_DOMAIN}
PDNS_SERVER_IP=${PDNS_SERVER_IP}
EOF
if ((${#EXISTING_STALWART[@]})); then
    printf '%s\n' "${EXISTING_STALWART[@]}" >> "$PANEL_DIR/.env"
fi
if ((${#EXISTING_GOOGLE[@]})); then
    printf '%s\n' "${EXISTING_GOOGLE[@]}" >> "$PANEL_DIR/.env"
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
if ! php artisan migrate --force --no-interaction; then
    fail "Database migration failed — check the output above (database: ${PANEL_DIR}/storage/panel.sqlite)."
fi
php artisan storage:link --no-interaction >/dev/null 2>&1 || true

# Backup storage for panel:backup:* plus the scheduler tick in
# /etc/cron.d/panel (runs due backup schedules every minute).
install -d -m 700 -o root -g root /var/backups/panel
if ! php artisan panel:cron:sync --no-interaction; then
    warn "Could not write /etc/cron.d/panel — backup schedules will not run until 'php artisan panel:cron:sync' succeeds."
fi

# Seed the update status file so the dashboard knows which commit is running
# (the hourly cron line takes over from here).
if ! php artisan panel:update:check --no-interaction; then
    warn "Could not check for panel updates — the hourly cron check will retry."
fi

if [[ -z "$ADMIN_EMAIL" ]]; then
    ok "Administrator account deferred — it is created on the setup page."
    SETUP_STEPS+=(admin)
else
    info "Creating administrator ${ADMIN_EMAIL}..."
    if ! php artisan panel:admin:create "$ADMIN_EMAIL" \
        --password="$ADMIN_PASSWORD" \
        --name="Admin" \
        --no-interaction; then
        fail "Could not create the administrator account — see the output above."
    fi
fi

# Store the panel login next to the other secrets so a re-run (or a lost
# terminal scrollback) can always recover it. The MySQL and PowerDNS commands
# append their own keys to the same file.
SECRETS_FILE="/root/.panel-secrets"

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

if [[ -n "$ADMIN_EMAIL" ]]; then
    upsert_secret PANEL_ADMIN_EMAIL "$ADMIN_EMAIL"
    upsert_secret PANEL_ADMIN_PASSWORD "$ADMIN_PASSWORD"
    chmod 600 "$SECRETS_FILE"
    ok "Administrator saved to ${SECRETS_FILE}."
fi

# ---------------------------------------------------------------
# What still has to be filled in on the setup page
# ---------------------------------------------------------------

SETUP_MARKER="${PANEL_DIR}/storage/app/setup-pending.json"
if ((${#SETUP_STEPS[@]})); then
    mkdir -p "${PANEL_DIR}/storage/app"
    cat > "$SETUP_MARKER" <<EOF
{
  "steps": [$(printf '"%s",' "${SETUP_STEPS[@]}" | sed 's/,$//')],
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
    chmod 644 "$SETUP_MARKER"
else
    rm -f "$SETUP_MARKER"
fi

# ---------------------------------------------------------------
# Frontend assets
# ---------------------------------------------------------------

if [[ ! -f "$PANEL_DIR/public/build/manifest.json" ]]; then
    info "Building frontend assets..."
    if ! npm install --ignore-scripts || ! npm run build; then
        fail "Frontend build failed — check the npm/vite output above, then re-run this installer."
    fi
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

# Plain HTTP on any host at PANEL_PORT: works before DNS resolves, without a
# certificate, and without taking 80 away from anything else.
:${PANEL_PORT} {
    root * ${PANEL_DIR}/public
    encode zstd gzip
    php_fastcgi unix//run/php/php${PHP_VERSION}-fpm.sock
    file_server
}
EOF

# Reachable before DNS for PANEL_DOMAIN resolves: a nip.io hostname that
# always points at this IP (Caddy gets a real certificate for it), plus the
# bare IP over plain HTTP for initial setup. PANEL_DOMAIN stays the main site.
if [[ -n "$PDNS_SERVER_IP" ]]; then
    cat >> "/etc/caddy/sites/panel.caddy" <<EOF

panel.${PDNS_SERVER_IP}.nip.io {
    root * ${PANEL_DIR}/public
    encode zstd gzip
    php_fastcgi unix//run/php/php${PHP_VERSION}-fpm.sock
    file_server
}

http://${PDNS_SERVER_IP} {
    root * ${PANEL_DIR}/public
    encode zstd gzip
    php_fastcgi unix//run/php/php${PHP_VERSION}-fpm.sock
    file_server
}
EOF
    ok "Panel also served at https://panel.${PDNS_SERVER_IP}.nip.io, http://${PDNS_SERVER_IP} and http://${PDNS_SERVER_IP}:${PANEL_PORT}."
else
    warn "Server IP unknown — only ${PANEL_DOMAIN} and port ${PANEL_PORT} were configured in Caddy."
fi

chmod 644 "/etc/caddy/sites/panel.caddy"

if [[ ! -f /etc/caddy/Caddyfile ]]; then
    # "{}" would be parsed as a site address and break validation — an
    # import-only file is what a fresh Caddy actually accepts.
    printf 'import /etc/caddy/sites/*.caddy\n' > /etc/caddy/Caddyfile
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
install -d -m 755 -o root -g root /usr/local/panel

if ! install -m 755 -o root -g root "$PANEL_DIR/scripts/run.sh" /usr/local/panel/run; then
    fail "Could not install /usr/local/panel/run."
fi

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
systemctl enable --now caddy >/dev/null 2>&1 || true
systemctl restart "php${PHP_VERSION}-fpm" caddy \
    || warn "php${PHP_VERSION}-fpm / caddy did not restart cleanly — check their systemctl status."

# Open the panel in UFW without locking the administrator out.
SSH_PORT="$(ss -lntpH 2>/dev/null | awk '/:22 |:22$/ {print $4}' | head -1 | sed 's/.*://' || true)"
SSH_PORT="${SSH_PORT:-22}"

ufw allow "${SSH_PORT}/tcp" comment 'SSH' >/dev/null 2>&1 || true
ufw allow 80/tcp  comment 'HTTP'  >/dev/null 2>&1 || true
ufw allow 443/tcp comment 'HTTPS' >/dev/null 2>&1 || true
ufw allow 53/tcp  comment 'DNS'   >/dev/null 2>&1 || true
ufw allow 53/udp  comment 'DNS'   >/dev/null 2>&1 || true
ufw allow "${PANEL_PORT}/tcp" comment 'Panel' >/dev/null 2>&1 || true

if ufw status | grep -q 'Status: inactive'; then
    ufw --force enable >/dev/null \
        || warn "UFW could not be enabled — open 80/443 yourself if the panel is unreachable."
fi
ok "Firewall allows SSH (${SSH_PORT}), 80, 443, DNS (53) and the panel port (${PANEL_PORT})."

# ---------------------------------------------------------------
# Summary
# ---------------------------------------------------------------

case "$CODE_ACTION" in
    overwrite) CODE_SUMMARY="overwritten with a clean install" ;;
    skip)      CODE_SUMMARY="left untouched (--code=skip)" ;;
    *)         CODE_SUMMARY="kept and updated in place" ;;
esac

echo
echo "============================================================"
echo "                 PANEL INSTALLED"
echo "============================================================"
echo
echo "  App dir:  ${PANEL_DIR}"
echo "  Code:     ${CODE_SUMMARY}"
echo "  Secrets:  ${SECRETS_FILE}"
if [[ -n "$OVERWRITE_BACKUP_DIR" ]]; then
    echo "  Backup:   ${OVERWRITE_BACKUP_DIR} (.env + panel database)"
fi
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "  Nameservers: ns1.${PDNS_NS_DOMAIN} / ns2.${PDNS_NS_DOMAIN} → ${PDNS_SERVER_IP}"
fi
echo
echo "  Next steps:"
echo "  1. Open the panel right now — these work before DNS points here:"
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "       http://${PDNS_SERVER_IP}:${PANEL_PORT}      (plain HTTP — ready to use)"
    echo "       https://panel.${PDNS_SERVER_IP}.nip.io  (trusted certificate)"
else
    echo "       http://SERVER_IP:${PANEL_PORT}         (once the IP is known)"
fi
echo "  2. Point DNS: ${PANEL_DOMAIN} → this server's public IP"
echo "     (Caddy issues the certificate automatically once DNS resolves)"
if [[ -n "$ADMIN_EMAIL" ]]; then
    echo "  3. Sign in with the credentials below"
else
    echo "  3. Finish setup in the browser (the panel asks for it first)"
fi
echo "  4. Add the rest from the panel whenever you need it:"
echo "       Databases → installs MySQL (it generates and stores the root password)"
echo "       DNS       → installs PowerDNS, then register ns1./ns2.${PDNS_NS_DOMAIN}"
echo "       Email     → installs the mail server · Sites and phpMyAdmin are one click"
echo
echo "  Re-run this script any time to update the panel."
echo
if ((${#SETUP_STEPS[@]})); then
    echo "============================================================"
    echo "            ONE STEP LEFT — FINISH IN THE BROWSER"
    echo "============================================================"
    echo "  Open the panel and the setup page collects what is missing"
    echo "  (${SETUP_STEPS[*]}) — nothing else has to be installed:"
    if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
        echo "       http://${PDNS_SERVER_IP}:${PANEL_PORT}         (plain HTTP — setup)"
        echo "       https://panel.${PDNS_SERVER_IP}.nip.io    (trusted certificate)"
    fi
    echo "       https://${PANEL_DOMAIN}"
    echo "============================================================"
    echo
fi
echo "============================================================"
echo "              PANEL IS LIVE — SAVE THESE"
echo "============================================================"
echo "  Login URL:  https://${PANEL_DOMAIN}/login"
echo "  Panel URL:  https://${PANEL_DOMAIN}"
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "              http://${PDNS_SERVER_IP}:${PANEL_PORT}  (no DNS needed)"
fi
if [[ -n "$ADMIN_EMAIL" ]]; then
    echo "  Email:      ${ADMIN_EMAIL}"
    echo "  Password:   ${ADMIN_PASSWORD}"
    if [[ "$GENERATED_PASSWORD" == "1" ]]; then
        echo "              (generated — change it after your first login)"
    fi
    echo "  Stored in:  ${SECRETS_FILE} (PANEL_ADMIN_EMAIL / PANEL_ADMIN_PASSWORD)"
else
    echo "  Email:      (set on the setup page)"
    echo "  Password:   (set on the setup page)"
fi
echo "============================================================"
