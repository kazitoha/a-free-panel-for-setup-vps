#!/usr/bin/env bash
#
# Step 70 — the Laravel application: dependencies, .env, key, database and
# migrations. This is the heart of the install; if it fails the panel cannot
# boot and the reason is shown on the Setup page.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "70-app" "Laravel application"

cd "$PANEL_DIR"

info "Installing PHP dependencies..."
# Scripts are skipped so a dependency's post-install hook cannot run as root
# here; the Laravel package manifest is rebuilt explicitly instead.
if ! composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --working-dir="$PANEL_DIR"; then
    fail "composer install failed in ${PANEL_DIR} — see the output above; if the lock file is out of sync, run 'composer update' there and re-run."
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
    # installed — keep them across re-runs.
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

ok "Application migrated and configured."

install_step_done
