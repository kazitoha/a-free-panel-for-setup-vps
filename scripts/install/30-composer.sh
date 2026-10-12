#!/usr/bin/env bash
#
# Step 30 — Composer, when it is not already on the PATH.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "30-composer" "Composer"

if command -v composer >/dev/null 2>&1; then
    ok "Composer already installed ($(composer --version 2>/dev/null | head -1))."
    install_step_done
    exit 0
fi

info "Installing Composer..."
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
rm -f /tmp/composer-setup.php

command -v composer >/dev/null 2>&1 || fail "Composer installation did not produce /usr/local/bin/composer."
ok "Composer installed."

install_step_done
