#!/usr/bin/env bash
#
# Step 20 — PHP ${PHP_VERSION} and the extensions the panel needs.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "20-php" "PHP runtime"

export DEBIAN_FRONTEND=noninteractive

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

ok "PHP ${PHP_VERSION} installed."

install_step_done
