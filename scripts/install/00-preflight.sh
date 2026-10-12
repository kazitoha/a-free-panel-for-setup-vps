#!/usr/bin/env bash
#
# Step 00 — preflight: hostname mapping, OS detection and server IP lookup.
# Split out of install-panel.sh so a failure here is reported on its own.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "00-preflight" "Preflight checks"

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

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || fail "Invalid PHP version: $PHP_VERSION"

# Public IP is used to seed zones and to build the DNS-less panel URLs. It is
# only a value lookup, so a failure stays a warning rather than stopping here.
if [[ -z "${PDNS_SERVER_IP:-}" ]]; then
    PDNS_SERVER_IP="$(curl -fsS --max-time 5 -4 https://api.ipify.org 2>/dev/null || true)"
fi
if [[ -z "${PDNS_SERVER_IP:-}" ]]; then
    PDNS_SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi

if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    panel_install_set_derived PDNS_SERVER_IP "$PDNS_SERVER_IP"
    ok "Server IP is ${PDNS_SERVER_IP}."
else
    warn "Could not detect the server IP — set PDNS_SERVER_IP in .env and re-run to seed zones."
fi

install_step_done
