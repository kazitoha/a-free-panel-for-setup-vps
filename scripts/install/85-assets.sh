#!/usr/bin/env bash
#
# Step 85 — frontend assets (npm install + vite build), skipped when the
# build manifest already exists.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "85-assets" "Frontend assets"

cd "$PANEL_DIR"

if [[ -f "$PANEL_DIR/public/build/manifest.json" ]]; then
    ok "Frontend assets already built."
    install_step_done
    exit 0
fi

info "Building frontend assets..."
if ! npm install --ignore-scripts || ! npm run build; then
    fail "Frontend build failed — check the npm/vite output above, then re-run this installer."
fi

ok "Frontend assets built."

install_step_done
