#!/usr/bin/env bash
#
# Step 40 — Caddy, from the signed GitHub release package (the Cloudsmith APT
# repository behind Caddy's official install docs now returns 402).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "40-caddy" "Caddy web server"

if command -v caddy >/dev/null 2>&1; then
    ok "Caddy already installed ($(caddy version 2>/dev/null | head -1))."
    install_step_done
    exit 0
fi

info "Installing Caddy..."

CADDY_ARCH="$(dpkg --print-architecture)"
case "$CADDY_ARCH" in
    amd64|arm64|ppc64le|riscv64|s390x) ;;
    armhf) CADDY_ARCH="armv7" ;;
    armel) CADDY_ARCH="armv6" ;;
    *)     fail "Unsupported architecture for Caddy: ${CADDY_ARCH}" ;;
esac

CADDY_TAG="$(curl -fsSLI https://github.com/caddyserver/caddy/releases/latest \
    | tr -d '\r' \
    | awk 'tolower($1) == "location:" {url = $2} END {print url}' \
    | sed 's|.*/tag/||')"
[[ "$CADDY_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] \
    || fail "Could not determine the latest Caddy release tag."

CADDY_VERSION="${CADDY_TAG#v}"
CADDY_DEB="caddy_${CADDY_VERSION}_linux_${CADDY_ARCH}.deb"
CADDY_RELEASE="https://github.com/caddyserver/caddy/releases/download/${CADDY_TAG}"
CADDY_TMP="$(mktemp -d)"
trap 'rm -rf "$CADDY_TMP"' EXIT

curl -fsSL -o "${CADDY_TMP}/${CADDY_DEB}" "${CADDY_RELEASE}/${CADDY_DEB}"
curl -fsSL -o "${CADDY_TMP}/checksums.txt" "${CADDY_RELEASE}/caddy_${CADDY_VERSION}_checksums.txt"

if ! (cd "$CADDY_TMP" \
    && awk -v name="$CADDY_DEB" '$2 == name' checksums.txt | sha512sum -c - >/dev/null); then
    fail "Checksum verification failed for ${CADDY_DEB}."
fi

apt-get install -y -qq "${CADDY_TMP}/${CADDY_DEB}"
rm -rf "$CADDY_TMP"
trap - EXIT

ok "Caddy ${CADDY_VERSION} installed."

install_step_done
