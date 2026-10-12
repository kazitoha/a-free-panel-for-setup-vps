#!/usr/bin/env bash
#
# Step 98 — enable and restart the services, then open the firewall without
# locking the administrator out.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "98-services" "Services & firewall"

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

install_step_done
