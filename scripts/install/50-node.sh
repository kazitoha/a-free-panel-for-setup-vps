#!/usr/bin/env bash
#
# Step 50 — Node.js, used only to build the panel's frontend assets.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "50-node" "Node.js"

if command -v node >/dev/null 2>&1; then
    ok "Node.js already installed ($(node --version 2>/dev/null))."
    install_step_done
    exit 0
fi

info "Installing Node.js (for panel assets)..."
curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >/dev/null
apt-get install -y -qq nodejs

command -v node >/dev/null 2>&1 || fail "Node.js installation failed."
ok "Node.js installed."

install_step_done
