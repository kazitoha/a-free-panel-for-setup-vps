#!/usr/bin/env bash
#
# Step 95 — the Caddy site: the panel domain over HTTPS plus DNS-less
# addresses, an import line for /etc/caddy/sites/*.caddy, and validation.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "95-caddy-site" "Caddy site"

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

install_step_done
