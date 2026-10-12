#!/usr/bin/env bash
#
# Step 90 — ownership: code is root-owned; only runtime paths are writable by
# the web user.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "90-ownership" "File ownership"

chown -R root:root "$PANEL_DIR"
chown -R www-data:www-data "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"
chgrp www-data "$PANEL_DIR/.env" "$PANEL_DIR/storage/panel.sqlite"
chmod 640 "$PANEL_DIR/.env" "$PANEL_DIR/storage/panel.sqlite"
chmod 750 "$PANEL_DIR/bootstrap/cache"

ok "Ownership set."

install_step_done
