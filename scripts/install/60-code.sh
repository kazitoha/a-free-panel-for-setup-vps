#!/usr/bin/env bash
#
# Step 60 — put the panel code at ${PANEL_DIR}, honouring --code:
# update (default), overwrite (backup + fresh) or skip (leave it).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/install/lib.sh
source "${SCRIPT_DIR}/lib.sh"

install_step_init "60-code" "Panel code"

# Back up what an overwrite cannot recreate: the environment (APP_KEY, module
# credentials) and the panel database. Everything else comes back from the repo.
backup_existing_checkout() {
    local stamp backup

    [[ -d "$PANEL_DIR" ]] || return 0

    stamp="$(date +%Y%m%d-%H%M%S)"
    backup="/root/panel-backups/overwrite-${stamp}"
    mkdir -p "$backup"

    if [[ -f "$PANEL_DIR/.env" ]]; then
        cp -a "$PANEL_DIR/.env" "$backup/.env"
    fi

    if [[ -f "$PANEL_DIR/storage/panel.sqlite" ]]; then
        cp -a "$PANEL_DIR/storage/panel.sqlite" "$backup/panel.sqlite"
    fi

    chmod 700 "$backup"
    panel_install_set_derived OVERWRITE_BACKUP_DIR "$backup"
    warn "Backed up .env and panel database to ${backup} — restore them manually if you need the old data."
}

copy_local_code() {
    mkdir -p "$PANEL_DIR"
    rsync -a --delete \
        --exclude '.git' \
        --exclude '.env' \
        --exclude 'node_modules' \
        --exclude 'storage/framework/*' \
        --exclude 'storage/logs/*' \
        --exclude 'storage/panel.sqlite' \
        --exclude 'database/*.sqlite*' \
        "$BASE_DIR/" "$PANEL_DIR/"
}

if [[ "$MODE" == "local" ]]; then
    case "$CODE_ACTION" in
        skip)
            info "Keeping the existing panel code (--code=skip)."
            ;;
        overwrite)
            backup_existing_checkout
            if [[ "$BASE_DIR" == "$PANEL_DIR" ]]; then
                warn "The installer runs from ${PANEL_DIR} itself — keeping the code instead of deleting it."
            else
                info "Overwriting ${PANEL_DIR} with the code from ${BASE_DIR}..."
                rm -rf "$PANEL_DIR"
                copy_local_code
            fi
            ;;
        *)
            info "Copying panel code from ${BASE_DIR}..."
            copy_local_code
            ;;
    esac
else
    case "$CODE_ACTION" in
        skip)
            info "Keeping the existing panel code (--code=skip)."
            ;;
        overwrite)
            backup_existing_checkout
            info "Cloning ${REPO_URL} (branch ${BRANCH}) into a clean ${PANEL_DIR}..."
            rm -rf "$PANEL_DIR"
            git clone --quiet --branch "$BRANCH" "$REPO_URL" "$PANEL_DIR"
            ;;
        *)
            if [[ -d "$PANEL_DIR/.git" ]]; then
                info "Updating existing checkout (branch ${BRANCH})..."
                git -C "$PANEL_DIR" fetch --quiet origin
                git -C "$PANEL_DIR" checkout --quiet "$BRANCH"
                git -C "$PANEL_DIR" pull --quiet --ff-only origin "$BRANCH" || warn "Fast-forward pull failed; keeping current code."
            else
                info "Cloning ${REPO_URL} (branch ${BRANCH})..."
                rm -rf "$PANEL_DIR"
                git clone --quiet --branch "$BRANCH" "$REPO_URL" "$PANEL_DIR"
            fi
            ;;
    esac
fi

# --code=skip (and a half-finished earlier run) may leave no application here;
# fail early instead of dying on the first artisan call later.
if [[ ! -f "$PANEL_DIR/artisan" ]]; then
    fail "${PANEL_DIR} does not contain a panel application (artisan missing) — re-run with --code=overwrite."
fi

ok "Panel code is in place at ${PANEL_DIR}."

install_step_done
