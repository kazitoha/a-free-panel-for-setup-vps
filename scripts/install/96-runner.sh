#!/usr/bin/env bash
#
# Step 96 — the privileged runner: /usr/local/panel/run and the sudoers rule
# that lets www-data execute only allowlisted panel:* commands as root.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "96-runner" "Privileged runner"

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

install_step_done
