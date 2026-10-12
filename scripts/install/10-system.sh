#!/usr/bin/env bash
#
# Step 10 — base system packages and package index.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "10-system" "System packages"

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

ok "Base packages installed."

install_step_done
