#!/usr/bin/env bash
#
# VPS Panel — bootstrap installer for a fresh Ubuntu server (22.04 / 24.04).
#
#   sudo bash install-panel.sh --domain=panel.example.com --email=you@example.com
#
# This file is only the orchestrator: it collects the settings, then runs the
# small, single-purpose step scripts in scripts/install/ one by one. Each step
# can be run on its own, writes its own log and records a pass/fail result in
# /var/lib/panel/install/steps/<key>.json — which the panel's Setup page shows,
# naming the step that failed and the reason.
#
# Steps:
#   00-preflight  host mapping, OS detection, server IP
#   10-system     apt index + base packages
#   20-php        PHP + extensions
#   30-composer   Composer
#   40-caddy      Caddy (signed GitHub release)
#   50-node       Node.js (assets)
#   60-code       panel code (clone/copy/backup)
#   70-app        Laravel: deps, .env, key, migrations
#   80-admin      administrator account + secrets
#   85-assets     npm install / vite build
#   90-ownership  file ownership
#   95-caddy-site Caddy site + validation
#   96-runner     privileged sudo runner
#   98-services   services + UFW
#
# MySQL, PowerDNS and the mail server are NOT installed here: the panel's own
# data lives in SQLite, and each module installs its server on demand from the
# web interface (Databases / DNS / Email).
#
# Re-running is safe: installed pieces are reused or refreshed in place.

set -Eeuo pipefail

# ---------------------------------------------------------------
# Defaults & helpers
# ---------------------------------------------------------------

REPO_URL=""
BRANCH="main"
PANEL_DIR="/var/www/panel"
PHP_VERSION="8.3"
PANEL_DOMAIN=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
PDNS_NS_DOMAIN=""
MODE=""
CODE_ACTION=""
PANEL_PORT=""
ONLY_STEPS=""
PANEL_INSTALL_STATE="${PANEL_INSTALL_STATE:-/var/lib/panel/install}"
MODULES_DIR=""
STATE_DIR="$PANEL_INSTALL_STATE"
CONFIG_FILE=""
NON_INTERACTIVE="${NON_INTERACTIVE:-0}"
SECRETS_FILE="/root/.panel-secrets"

# Works both from a checkout and piped (curl … | bash), where BASH_SOURCE is unset.
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" && pwd)"

info()  { printf '\033[1;34m[INFO]\033[0m %s\n' "$1"; }
ok()    { printf '\033[1;32m[ OK ]\033[0m %s\n' "$1"; }
warn()  { printf '\033[1;33m[WARN]\033[0m %s\n' "$1"; }
fail()  { printf '\033[1;31m[FAIL]\033[0m %s\n' "$1" >&2; exit 1; }

# `set -e` alone stops the installer without saying which command died —
# name it, so the log above it finally makes sense.
installer_error() {
    local __status="$?" __line="$1" __cmd="$2"
    printf '\n\033[1;31m[FAIL]\033[0m install-panel.sh: line %s — command failed (exit %s)\n' "$__line" "$__status" >&2
    printf '       %s\n' "$__cmd" >&2
    printf '       Nothing after this line ran. Fix the error above and re-run the installer.\n' >&2
    exit "$__status"
}
trap 'installer_error "$LINENO" "$BASH_COMMAND"' ERR

usage() {
    cat <<'USAGE'
Usage: sudo bash install-panel.sh [options]

Options:
  --domain=HOST      Panel hostname (e.g. panel.example.com)
  --email=EMAIL      Administrator email (when omitted, the account is created
                     on the setup page the panel shows after the install)
  --password=PASS    Administrator password (min 8 characters; generated when omitted)
  --repo=URL         Git repository to install from
  --branch=NAME      Git branch (default: main)
  --code=ACTION      When a panel already exists: update (default) — keep it and
                     fast-forward; overwrite — delete the project and install
                     fresh (files are backed up first); skip — leave the
                     existing code untouched
  --php=VERSION      PHP version to install (default: 8.3)
  --ns-domain=HOST   Nameserver domain for the DNS module (default: panel domain)
  --port=PORT        Also serve the panel on SERVER_IP:PORT over plain HTTP
                     (default: 6669) — usable before DNS resolves
  --only=STEP[,STEP] Run only these install steps (see scripts/install/) and
                     stop; useful to retry the one step that failed
  --local            Install from the directory containing this script
  --non-interactive  Never prompt; fail when a required value is missing
  -h, --help         Show this help
USAGE
}

# Copy-paste from docs, chat apps or Word replaces real spaces with
# non-breaking (U+00A0) or zero-width (U+200B) spaces. The shell does not
# treat those as separators, so a whole pasted command line arrives glued
# together and dies as an "Unknown option:" that shows nothing on screen.
# Split those back into real arguments; a value that already contains a
# real space (--password='a b') is never touched.
split_invisible_spaces() {
    local __arg __v __part
    local -a __parts

    NORMALIZED_ARGS=()

    for __arg in "$@"; do
        __v="${__arg//$'\xc2\xa0'/ }"
        __v="${__v//$'\xe2\x80\x8b'/ }"

        if [[ "$__v" != "$__arg" && "$__arg" != *" "* ]]; then
            __parts=()
            read -r -a __parts <<< "$__v"

            for __part in ${__parts[@]+"${__parts[@]}"}; do
                if [[ -n "$__part" ]]; then
                    NORMALIZED_ARGS+=("$__part")
                fi
            done
        else
            NORMALIZED_ARGS+=("$__arg")
        fi
    done
}

# Show invisible characters instead of printing them to a blank-looking slot.
describe_argument() {
    local __shown="$1"
    __shown="${__shown//$'\xc2\xa0'/[NBSP]}"
    __shown="${__shown//$'\xe2\x80\x8b'/[ZWSP]}"
    printf '%q' "$__shown"
}

split_invisible_spaces "$@"
set -- ${NORMALIZED_ARGS[@]+"${NORMALIZED_ARGS[@]}"}

for argument in "$@"; do
    # Copy-paste from docs or chat often collapses "\" line breaks into plain
    # spaces, which turn into whitespace-only arguments — ignore those instead
    # of failing on an option nobody typed.
    if [[ "$argument" =~ ^[[:space:]]*$ ]]; then
        continue
    fi
    case "$argument" in
        --domain=*)       PANEL_DOMAIN="${argument#*=}" ;;
        --email=*)        ADMIN_EMAIL="${argument#*=}" ;;
        --password=*)     ADMIN_PASSWORD="${argument#*=}" ;;
        --repo=*)         REPO_URL="${argument#*=}" ;;
        --branch=*)       BRANCH="${argument#*=}" ;;
        --code=*)         CODE_ACTION="${argument#*=}" ;;
        --php=*)          PHP_VERSION="${argument#*=}" ;;
        --ns-domain=*)    PDNS_NS_DOMAIN="${argument#*=}" ;;
        --port=*)         PANEL_PORT="${argument#*=}" ;;
        --only=*)         ONLY_STEPS="${ONLY_STEPS:+${ONLY_STEPS},}${argument#*=}" ;;
        --local)          MODE="local" ;;
        --non-interactive) NON_INTERACTIVE=1 ;;
        -h|--help)        usage; exit 0 ;;
        *)                usage >&2
                          fail "Unknown option: $(describe_argument "$argument")
       If this came from a copy-pasted command, invisible spaces (shown as
       [NBSP]/[ZWSP] above) or a broken line break are the usual cause —
       retype the command instead of pasting it." ;;
    esac
done

NON_INTERACTIVE="${NON_INTERACTIVE:-0}"
PANEL_PORT="${PANEL_PORT:-6669}"
ONLY_STEPS="${ONLY_STEPS//[[:space:]]/}"
[[ "$PANEL_PORT" =~ ^[0-9]+$ && "$PANEL_PORT" -ge 1 && "$PANEL_PORT" -le 65535 ]] \
    || fail "Invalid --port value: ${PANEL_PORT} (1-65535)."

# Piped installs (curl | sudo bash) have no terminal — prompting would also
# swallow the script itself, so never read stdin in that mode.
if [[ ! -t 0 ]]; then
    NON_INTERACTIVE=1
fi

prompt() {
    # prompt VAR "Question" [default]
    local __var="$1" __question="$2" __default="${3:-}" __answer=""

    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' "$__default"
        return 0
    fi

    if [[ -n "$__default" ]]; then
        read -r -p "$__question [$__default]: " __answer || __answer=""
        __answer="${__answer:-$__default}"
    else
        read -r -p "$__question: " __answer || __answer=""
    fi

    printf -v "$__var" '%s' "$__answer"
}

prompt_secret() {
    local __var="$1" __question="$2" __answer=""

    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' ""
        return 0
    fi

    read -r -s -p "$__question: " __answer || __answer=""
    echo
    printf -v "$__var" '%s' "$__answer"
}

# ---------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------

[[ "${EUID}" -eq 0 ]] || fail "Run this script as root: sudo bash install-panel.sh"

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || fail "Invalid --php value: $PHP_VERSION"
[[ -z "$PANEL_DOMAIN" || "$PANEL_DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid --domain value: $PANEL_DOMAIN"
[[ -z "$PDNS_NS_DOMAIN" || "$PDNS_NS_DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid --ns-domain value: $PDNS_NS_DOMAIN"
[[ -z "$CODE_ACTION" || "$CODE_ACTION" =~ ^(update|overwrite|skip)$ ]] \
    || fail "Invalid --code value: $CODE_ACTION (use update, overwrite or skip)."

if [[ "$NON_INTERACTIVE" == "1" ]]; then
    [[ -n "$PANEL_DOMAIN" ]] || fail "--domain is required in non-interactive mode."
fi

# ---------------------------------------------------------------
# Collect settings
# ---------------------------------------------------------------

echo "========================================================="
echo "         VPS PANEL — INSTALLATION WIZARD"
echo "========================================================="

if [[ -z "$PANEL_DOMAIN" ]]; then
    prompt PANEL_DOMAIN "Panel domain (DNS A record must point here)"
    [[ -n "$PANEL_DOMAIN" ]] || fail "Panel domain is required."
fi

if [[ -z "$ADMIN_EMAIL" ]]; then
    prompt ADMIN_EMAIL "Administrator email (blank = create the account on the setup page)"
fi

if [[ -n "$ADMIN_EMAIL" && -z "$ADMIN_PASSWORD" && "$NON_INTERACTIVE" != "1" ]]; then
    while :; do
        prompt_secret ADMIN_PASSWORD "Administrator password (blank = generate)"
        [[ -z "$ADMIN_PASSWORD" || ${#ADMIN_PASSWORD} -ge 8 ]] && break
        echo "  Password must be at least 8 characters."
    done
fi

if [[ -n "$ADMIN_EMAIL" && -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(openssl rand -hex 12)"
    GENERATED_PASSWORD=1
else
    GENERATED_PASSWORD=0
fi

# Credential rules enforced later by `panel:admin:create` — check them here,
# before any package is installed, instead of failing after ten minutes.
if [[ -n "$ADMIN_EMAIL" ]]; then
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] \
        || fail "Invalid --email value: ${ADMIN_EMAIL}"
    if [[ "$GENERATED_PASSWORD" -eq 0 && ${#ADMIN_PASSWORD} -lt 8 ]]; then
        fail "--password must be at least 8 characters (got ${#ADMIN_PASSWORD}) — pick a longer one and re-run."
    fi
else
    info "No administrator email given — the account is created on the setup page after this install."
fi

PDNS_NS_DOMAIN="${PDNS_NS_DOMAIN:-${PANEL_DOMAIN}}"

# Resolve the panel source: local checkout or git repository.
if [[ -z "$MODE" ]]; then
    if [[ -f "$BASE_DIR/artisan" ]]; then
        MODE="local"
    else
        MODE="git"
    fi
fi

if [[ "$MODE" == "git" && -z "$REPO_URL" && -d "$BASE_DIR/.git" ]]; then
    REPO_URL="$(git -C "$BASE_DIR" remote get-url origin 2>/dev/null || true)"
fi

if [[ "$MODE" == "git" && -z "$REPO_URL" ]]; then
    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        fail "--repo is required when this script does not live inside the panel checkout."
    fi
    prompt REPO_URL "Panel git repository URL"
    [[ -n "$REPO_URL" ]] || fail "Repository URL is required."
fi

# ---------------------------------------------------------------
# Locate the step scripts
#
# From a checkout they sit next to this file. Piped in (curl | bash) they are
# fetched from the same repository the code is cloned from — the installer
# only needs curl + tar, both present on any image that fetched this script.
# ---------------------------------------------------------------
resolve_modules() {
    if [[ -f "$BASE_DIR/scripts/install/lib.sh" ]]; then
        MODULES_DIR="$BASE_DIR/scripts/install"
        return 0
    fi

    if [[ "$MODE" == "local" ]]; then
        fail "--local was given but ${BASE_DIR} is not the panel checkout (scripts/install/ is missing)."
    fi

    command -v curl >/dev/null 2>&1 \
        || fail "Cannot fetch the installer steps: curl is missing. Install curl or run from a checkout."
    command -v tar >/dev/null 2>&1 \
        || fail "Cannot fetch the installer steps: tar is missing. Install tar or run from a checkout."
    [[ -n "$REPO_URL" ]] || fail "Cannot fetch the installer steps without --repo=URL."

    local slug owner repo tarball dir
    slug="$(printf '%s' "$REPO_URL" | sed -E 's#\.git$##; s#/$##')"

    if [[ "$slug" =~ ^https?://github\.com/([^/]+)/([^/]+)$ ]]; then
        owner="${BASH_REMATCH[1]}"
        repo="${BASH_REMATCH[2]}"
    else
        fail "Automatic step download works with GitHub repositories only.
       Clone the repository and run 'sudo bash install-panel.sh --local' instead."
    fi

    tarball="$STATE_DIR/src.tar.gz"
    dir="$STATE_DIR/src"
    mkdir -p "$STATE_DIR"
    rm -rf "$dir"
    mkdir -p "$dir"

    info "Fetching installer steps from ${owner}/${repo}@${BRANCH}..."
    curl -fsSL --retry 3 --connect-timeout 15 -o "$tarball" \
        "https://codeload.github.com/${owner}/${repo}/tar.gz/refs/heads/${BRANCH}" \
        || fail "Could not download the installer steps from GitHub."
    tar -xzf "$tarball" -C "$dir" --strip-components=1 \
        || fail "Could not unpack the installer steps."
    rm -f "$tarball"

    [[ -f "$dir/scripts/install/lib.sh" ]] \
        || fail "The downloaded archive did not contain scripts/install/."

    MODULES_DIR="$dir/scripts/install"
}

resolve_modules

# shellcheck source=scripts/install/lib.sh
source "$MODULES_DIR/lib.sh"

STATE_DIR="$(panel_install_state_dir)"
CONFIG_FILE="$STATE_DIR/config.env"
mkdir -p "$STATE_DIR"

# ---------------------------------------------------------------
# Existing installation
# ---------------------------------------------------------------

# An earlier install leaves a populated /var/www/panel. The administrator
# decides up front — before any package is touched — whether this run keeps it
# (update), wipes it (overwrite) or leaves the code alone (skip).
PANEL_EXISTS=0
if [[ -d "$PANEL_DIR" && -n "$(ls -A "$PANEL_DIR" 2>/dev/null || true)" ]]; then
    PANEL_EXISTS=1
fi

if [[ "$PANEL_EXISTS" -eq 1 && -z "$CODE_ACTION" ]]; then
    if [[ "$NON_INTERACTIVE" == "1" ]]; then
        CODE_ACTION="update"
        info "Existing panel found at ${PANEL_DIR} — updating it in place (use --code=overwrite or --code=skip to change that)."
    else
        info "Existing panel installation found at ${PANEL_DIR}."
        echo "  update    keep everything and fast-forward the code (default)"
        echo "  overwrite delete the project and install fresh (backed up first)"
        echo "  skip      leave the existing code untouched"
        __code="" __answer=""
        while [[ -z "$__code" ]]; do
            read -r -p "What should I do with it? [update]: " __answer || true
            __answer="${__answer,,}"
            __answer="${__answer//[[:space:]]/}"
            __answer="${__answer:-update}"
            case "$__answer" in
                u|up|update)    __code="update" ;;
                o|ov|overwrite) __code="overwrite" ;;
                s|sk|skip)      __code="skip" ;;
                *)              echo "Please answer update, overwrite or skip." ;;
            esac
        done
        CODE_ACTION="$__code"
        unset __code __answer
    fi
fi

# Nothing to keep or wipe: a first install behaves like an update.
CODE_ACTION="${CODE_ACTION:-update}"

if [[ "$CODE_ACTION" == "skip" && "$PANEL_EXISTS" -eq 0 ]]; then
    warn "--code=skip: there is no panel at ${PANEL_DIR} — installing it instead."
    CODE_ACTION="update"
fi

# A shorthand such as owner/repo.git is not something git can clone. Catch it
# here, before the run gets halfway through, and say what a full URL looks like.
if [[ "$MODE" == "git" && -n "$REPO_URL" ]] \
    && ! [[ "$REPO_URL" =~ ^[a-zA-Z][a-zA-Z0-9+.-]*:// ]] \
    && ! [[ "$REPO_URL" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:.+ ]] \
    && ! [[ "$REPO_URL" =~ ^(\.?/|~) ]]; then
    if [[ -d "$PANEL_DIR/.git" && "$CODE_ACTION" != "overwrite" ]]; then
        warn "--repo '${REPO_URL}' is not a repository URL — ignoring it, ${PANEL_DIR} is an existing checkout."
        REPO_URL=""
    elif [[ "$REPO_URL" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+(\.git)?$ ]]; then
        fail "--repo must be a full URL — try --repo=https://github.com/${REPO_URL}"
    else
        fail "--repo must be a repository URL (https://…, git@host:path or a local path) — got: ${REPO_URL}"
    fi
fi

# ---------------------------------------------------------------
# Persist the settings the step scripts read
# ---------------------------------------------------------------

write_config() {
    {
        printf 'PANEL_DIR=%q\n' "$PANEL_DIR"
        printf 'PHP_VERSION=%q\n' "$PHP_VERSION"
        printf 'PANEL_DOMAIN=%q\n' "$PANEL_DOMAIN"
        printf 'ADMIN_EMAIL=%q\n' "$ADMIN_EMAIL"
        printf 'ADMIN_PASSWORD=%q\n' "$ADMIN_PASSWORD"
        printf 'GENERATED_PASSWORD=%q\n' "$GENERATED_PASSWORD"
        printf 'REPO_URL=%q\n' "$REPO_URL"
        printf 'BRANCH=%q\n' "$BRANCH"
        printf 'CODE_ACTION=%q\n' "$CODE_ACTION"
        printf 'PANEL_PORT=%q\n' "$PANEL_PORT"
        printf 'MODE=%q\n' "$MODE"
        printf 'NON_INTERACTIVE=%q\n' "$NON_INTERACTIVE"
        printf 'PDNS_NS_DOMAIN=%q\n' "$PDNS_NS_DOMAIN"
        printf 'PDNS_API_URL=%q\n' "http://127.0.0.1:8081"
        printf 'PDNS_SERVER_IP=%q\n' "${PDNS_SERVER_IP:-}"
        printf 'SECRETS_FILE=%q\n' "$SECRETS_FILE"
        printf 'BASE_DIR=%q\n' "$BASE_DIR"
    } > "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
}

# Every step: key|label. The order is the install order.
STEP_ENTRIES=(
    "00-preflight|Preflight checks"
    "10-system|System packages"
    "20-php|PHP runtime"
    "30-composer|Composer"
    "40-caddy|Caddy web server"
    "50-node|Node.js"
    "60-code|Panel code"
    "70-app|Laravel application"
    "80-admin|Administrator account"
    "85-assets|Frontend assets"
    "90-ownership|File ownership"
    "95-caddy-site|Caddy site"
    "96-runner|Privileged runner"
    "98-services|Services & firewall"
)

step_selected() {
    if [[ -z "$ONLY_STEPS" ]]; then
        return 0
    fi

    [[ ",${ONLY_STEPS}," == *",${1},"* ]]
}

# Retry only the named steps: reject a typo instead of silently doing nothing.
if [[ -n "$ONLY_STEPS" ]]; then
    for __key in ${ONLY_STEPS//,/ }; do
        __found=0
        for __entry in "${STEP_ENTRIES[@]}"; do
            if [[ "${__entry%%|*}" == "$__key" ]]; then
                __found=1
            fi
        done
        [[ "$__found" -eq 1 ]] || fail "Unknown --only step: ${__key}"
    done
    unset __key __entry __found
fi

run_step() {
    local key="$1" label="$2"
    local script="$MODULES_DIR/${key}.sh"
    local log="$STATE_DIR/logs/${key}.log"
    local started finished status message

    [[ -f "$script" ]] || fail "Missing installer step: ${script}"

    mkdir -p "$STATE_DIR/logs"
    started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    panel_install_write_step "$key" "$label" running "" "" "$started"

    info "▶ ${label} (${key})"

    set +e
    PANEL_INSTALL_CONFIG="$CONFIG_FILE" PANEL_INSTALL_STATE="$STATE_DIR" \
        bash "$script" 2>&1 | tee "$log"
    status="${PIPESTATUS[0]}"
    set -e

    finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if [[ "$status" -eq 0 ]]; then
        panel_install_write_step "$key" "$label" success "" "0" "$started" "$finished"
        return 0
    fi

    message="$(panel_install_tail_message "$log")"
    [[ -n "$message" ]] || message="The step exited with status ${status}."
    panel_install_write_step "$key" "$label" failed "$message" "$status" "$started" "$finished"
    LAST_STEP_KEY="$key"
    LAST_STEP_LABEL="$label"
    LAST_STEP_MESSAGE="$message"
    return 1
}

# ---------------------------------------------------------------
# Run
# ---------------------------------------------------------------

SELECTED=()
for entry in "${STEP_ENTRIES[@]}"; do
    if step_selected "${entry%%|*}"; then
        SELECTED+=("$entry")
    fi
done

write_config

# A full run starts clean so stale results never linger on the Setup page.
if [[ -z "$ONLY_STEPS" ]]; then
    rm -f "$STATE_DIR"/steps/*.json "$STATE_DIR/derived.env"
fi

panel_install_write_manifest "${SELECTED[@]}"

for entry in "${SELECTED[@]}"; do
    key="${entry%%|*}"
    label="${entry#*|}"

    if ! run_step "$key" "$label"; then
        echo
        echo "============================================================"
        echo "                    INSTALL FAILED"
        echo "============================================================"
        echo "  Step:    ${LAST_STEP_LABEL} (${LAST_STEP_KEY})"
        echo "  Reason:  ${LAST_STEP_MESSAGE}"
        echo "  Log:     ${STATE_DIR}/logs/${LAST_STEP_KEY}.log"
        echo
        echo "  The panel's Setup page shows this failure too. Fix the"
        echo "  problem above and re-run — to retry just this step:"
        echo "      sudo bash install-panel.sh --only=${LAST_STEP_KEY} ..."
        echo "============================================================"
        exit 1
    fi
done

# ---------------------------------------------------------------
# Summary
# ---------------------------------------------------------------

# Values discovered by the steps (server IP, backup dir, deferred setup).
if [[ -f "$STATE_DIR/derived.env" ]]; then
    # shellcheck disable=SC1090
    source "$STATE_DIR/derived.env"
fi

SETUP_STEPS=()
if [[ -n "${SETUP_PENDING_STEPS:-}" ]]; then
    read -r -a SETUP_STEPS <<< "$SETUP_PENDING_STEPS"
fi

case "$CODE_ACTION" in
    overwrite) CODE_SUMMARY="overwritten with a clean install" ;;
    skip)      CODE_SUMMARY="left untouched (--code=skip)" ;;
    *)         CODE_SUMMARY="kept and updated in place" ;;
esac

echo
echo "============================================================"
echo "                 PANEL INSTALLED"
echo "============================================================"
echo
echo "  App dir:  ${PANEL_DIR}"
echo "  Code:     ${CODE_SUMMARY}"
echo "  Secrets:  ${SECRETS_FILE}"
if [[ -n "${OVERWRITE_BACKUP_DIR:-}" ]]; then
    echo "  Backup:   ${OVERWRITE_BACKUP_DIR} (.env + panel database)"
fi
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "  Nameservers: ns1.${PDNS_NS_DOMAIN} / ns2.${PDNS_NS_DOMAIN} → ${PDNS_SERVER_IP}"
fi
echo
echo "  Next steps:"
echo "  1. Open the panel right now — these work before DNS points here:"
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "       http://${PDNS_SERVER_IP}:${PANEL_PORT}      (plain HTTP — ready to use)"
    echo "       https://panel.${PDNS_SERVER_IP}.nip.io  (trusted certificate)"
else
    echo "       http://SERVER_IP:${PANEL_PORT}         (once the IP is known)"
fi
echo "  2. Point DNS: ${PANEL_DOMAIN} → this server's public IP"
echo "     (Caddy issues the certificate automatically once DNS resolves)"
if [[ -n "$ADMIN_EMAIL" ]]; then
    echo "  3. Sign in with the credentials below"
else
    echo "  3. Finish setup in the browser (the panel asks for it first)"
fi
echo "  4. Add the rest from the panel whenever you need it:"
echo "       Databases → installs MySQL (it generates and stores the root password)"
echo "       DNS       → installs PowerDNS, then register ns1./ns2.${PDNS_NS_DOMAIN}"
echo "       Email     → installs the mail server · Sites and phpMyAdmin are one click"
echo
echo "  Re-run this script any time to update the panel."
echo
if ((${#SETUP_STEPS[@]})); then
    echo "============================================================"
    echo "            ONE STEP LEFT — FINISH IN THE BROWSER"
    echo "============================================================"
    echo "  Open the panel and the setup page collects what is missing"
    echo "  (${SETUP_STEPS[*]}) — nothing else has to be installed:"
    if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
        echo "       http://${PDNS_SERVER_IP}:${PANEL_PORT}         (plain HTTP — setup)"
        echo "       https://panel.${PDNS_SERVER_IP}.nip.io    (trusted certificate)"
    fi
    echo "       https://${PANEL_DOMAIN}"
    echo "============================================================"
    echo
fi
echo "============================================================"
echo "              PANEL IS LIVE — SAVE THESE"
echo "============================================================"
echo "  Login URL:  https://${PANEL_DOMAIN}/login"
echo "  Panel URL:  https://${PANEL_DOMAIN}"
if [[ -n "${PDNS_SERVER_IP:-}" ]]; then
    echo "              http://${PDNS_SERVER_IP}:${PANEL_PORT}  (no DNS needed)"
fi
if [[ -n "$ADMIN_EMAIL" ]]; then
    echo "  Email:      ${ADMIN_EMAIL}"
    echo "  Password:   ${ADMIN_PASSWORD}"
    if [[ "$GENERATED_PASSWORD" == "1" ]]; then
        echo "              (generated — change it after your first login)"
    fi
    echo "  Stored in:  ${SECRETS_FILE} (PANEL_ADMIN_EMAIL / PANEL_ADMIN_PASSWORD)"
else
    echo "  Email:      (set on the setup page)"
    echo "  Password:   (set on the setup page)"
fi
echo "============================================================"
