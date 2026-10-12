#!/usr/bin/env bash
#
# Step 80 — administrator account, the /root/.panel-secrets entry and the
# marker the panel's first-run page reads when the account is deferred.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "80-admin" "Administrator account"

cd "$PANEL_DIR"

SETUP_STEPS=()

upsert_secret() {
    local key="$1" value="$2" tmp

    tmp="$(mktemp)"

    if grep -q "^${key}=" "$SECRETS_FILE" 2>/dev/null; then
        awk -v k="$key" -v v="$value" \
            'BEGIN{done=0} $0 ~ "^"k"=" {if(!done){print k"="v; done=1; next}} {print}' \
            "$SECRETS_FILE" > "$tmp"
    else
        cat "$SECRETS_FILE" > "$tmp" 2>/dev/null || true
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi

    install -m 600 -o root -g root "$tmp" "$SECRETS_FILE"
    rm -f "$tmp"
}

if [[ -z "$ADMIN_EMAIL" ]]; then
    ok "Administrator account deferred — it is created on the setup page."
    SETUP_STEPS+=(admin)
else
    info "Creating administrator ${ADMIN_EMAIL}..."
    if ! php artisan panel:admin:create "$ADMIN_EMAIL" \
        --password="$ADMIN_PASSWORD" \
        --name="Admin" \
        --no-interaction; then
        fail "Could not create the administrator account — see the output above."
    fi

    upsert_secret PANEL_ADMIN_EMAIL "$ADMIN_EMAIL"
    upsert_secret PANEL_ADMIN_PASSWORD "$ADMIN_PASSWORD"
    chmod 600 "$SECRETS_FILE"
    ok "Administrator saved to ${SECRETS_FILE}."
fi

if ((${#SETUP_STEPS[@]})); then
    SETUP_MARKER="${PANEL_DIR}/storage/app/setup-pending.json"
    mkdir -p "${PANEL_DIR}/storage/app"
    cat > "$SETUP_MARKER" <<EOF
{
  "steps": [$(printf '"%s",' "${SETUP_STEPS[@]}" | sed 's/,$//')],
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
    chmod 644 "$SETUP_MARKER"
    panel_install_set_derived SETUP_PENDING_STEPS "${SETUP_STEPS[*]}"
else
    rm -f "${PANEL_DIR}/storage/app/setup-pending.json"
    panel_install_set_derived SETUP_PENDING_STEPS ""
fi

install_step_done
