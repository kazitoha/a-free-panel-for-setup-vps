#!/usr/bin/env bash
# shellcheck shell=bash
#
# Shared helpers for the install-panel.sh step scripts (scripts/install/*.sh).
# Source it, never execute it:
#
#   source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
#
# Every step script:
#   1. calls `install_step_init <key> "<label>"` (loads config + arms the trap),
#   2. runs its commands,
#   3. calls `install_step_done`.
#
# If any command dies the trap writes a small JSON result under the install
# status directory (seen by the panel's Setup page) and prints the usual
# [FAIL] line naming the script, line and command — exactly what the old
# single-file installer did, but per step.
#
# The install-panel.sh orchestrator sources this too: it writes the manifest,
# runs each step with output teed into $STATE_DIR/logs/<key>.log and records
# the final pass/fail (with the failing log tail) itself.

if [[ -z "${BASH_VERSION:-}" ]]; then
    printf 'These scripts must be run with bash.\n' >&2
    exit 1
fi

PANEL_INSTALL_STATE="${PANEL_INSTALL_STATE:-/var/lib/panel/install}"

# ---------------------------------------------------------------
# Output (ANSI only on a terminal so logs stay clean)
# ---------------------------------------------------------------
if [[ -t 1 ]]; then
    _C_RESET=$'\033[0m'
    _C_RED=$'\033[1;31m'
    _C_GREEN=$'\033[1;32m'
    _C_YELLOW=$'\033[1;33m'
    _C_BLUE=$'\033[1;34m'
else
    _C_RESET=""
    _C_RED=""
    _C_GREEN=""
    _C_YELLOW=""
    _C_BLUE=""
fi

info() { printf '%s[INFO]%s %s\n' "${_C_BLUE}" "${_C_RESET}" "$*"; }
ok()   { printf '%s[ OK ]%s %s\n' "${_C_GREEN}" "${_C_RESET}" "$*"; }
warn() { printf '%s[WARN]%s %s\n' "${_C_YELLOW}" "${_C_RESET}" "$*"; }

fail() {
    printf '%s[FAIL]%s %s\n' "${_C_RED}" "${_C_RESET}" "$*" >&2

    if [[ -n "${PANEL_STEP_KEY:-}" ]]; then
        panel_install_write_step "${PANEL_STEP_KEY}" "${PANEL_STEP_LABEL:-}" failed "$*" "1"
    fi

    exit 1
}

# ---------------------------------------------------------------
# Config: defaults <- config.env <- derived.env
#
# A step run by hand (without install-panel.sh) still works for most values
# because every one has a sensible default; the orchestrator's config.env
# simply overrides them.
# ---------------------------------------------------------------
panel_install_load_config() {
    local state="${PANEL_INSTALL_STATE:-/var/lib/panel/install}"

    : "${PANEL_DIR:=/var/www/panel}"
    : "${PHP_VERSION:=8.3}"
    : "${PANEL_DOMAIN:=}"
    : "${ADMIN_EMAIL:=}"
    : "${ADMIN_PASSWORD:=}"
    : "${GENERATED_PASSWORD:=0}"
    : "${REPO_URL:=}"
    : "${BRANCH:=main}"
    : "${CODE_ACTION:=update}"
    : "${PANEL_PORT:=6669}"
    : "${MODE:=}"
    : "${NON_INTERACTIVE:=1}"
    : "${PDNS_NS_DOMAIN:=${PANEL_DOMAIN}}"
    : "${PDNS_API_URL:=http://127.0.0.1:8081}"
    : "${PDNS_SERVER_IP:=}"
    : "${SECRETS_FILE:=/root/.panel-secrets}"
    : "${BASE_DIR:=$(pwd)}"
    : "${OVERWRITE_BACKUP_DIR:=}"
    : "${SETUP_PENDING_STEPS:=}"

    local config="${PANEL_INSTALL_CONFIG:-$state/config.env}"

    if [[ -f "$config" ]]; then
        # shellcheck disable=SC1090
        source "$config"
    fi

    if [[ -f "$state/derived.env" ]]; then
        # shellcheck disable=SC1090
        source "$state/derived.env"
    fi
}

# ---------------------------------------------------------------
# Status files
#
#   $STATE_DIR/manifest.json       — the ordered list of steps
#   $STATE_DIR/steps/<key>.json    — one result per step
#   $STATE_DIR/logs/<key>.log      — that step's output
#
# The panel reads manifest.json + steps/*.json (see app/Support/InstallStatus).
# ---------------------------------------------------------------
panel_install_state_dir() {
    printf '%s' "${PANEL_INSTALL_STATE:-/var/lib/panel/install}"
}

panel_install_ensure_dirs() {
    mkdir -p "${PANEL_INSTALL_STATE}/steps" "${PANEL_INSTALL_STATE}/logs"
}

_json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\t'/\\t}"
    s="${s//$'\r'/\\r}"
    printf '%s' "$s"
}

_json_string_or_null() {
    local s="${1:-}"

    if [[ -z "$s" ]]; then
        printf 'null'
    else
        printf '"%s"' "$(_json_escape "$s")"
    fi
}

# panel_install_write_step KEY LABEL STATUS [MESSAGE] [EXIT] [STARTED] [FINISHED]
panel_install_write_step() {
    local key="$1" label="$2" status="$3"
    local message="${4:-}" exit_code="${5:-}" started="${6:-}" finished="${7:-}"
    local dir="${PANEL_INSTALL_STATE}/steps" tmp now

    mkdir -p "$dir" "${PANEL_INSTALL_STATE}/logs"

    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    [[ -n "$started" ]] || started="$now"
    [[ -n "$exit_code" ]] || exit_code="null"

    tmp="$(mktemp "${dir}/.${key}.XXXXXX")"

    {
        printf '{\n'
        printf '  "key": "%s",\n' "$(_json_escape "$key")"
        printf '  "label": "%s",\n' "$(_json_escape "$label")"
        printf '  "status": "%s",\n' "$(_json_escape "$status")"
        printf '  "message": %s,\n' "$(_json_string_or_null "$message")"
        printf '  "exit": %s,\n' "$exit_code"
        printf '  "started_at": %s,\n' "$(_json_string_or_null "$started")"
        printf '  "finished_at": %s\n' "$(_json_string_or_null "$finished")"
        printf '}\n'
    } > "$tmp"

    chmod 0644 "$tmp"
    mv -f "$tmp" "${dir}/${key}.json"
}

# panel_install_write_manifest "key|label" "key|label" ...
panel_install_write_manifest() {
    local tmp now entry key label first=1

    mkdir -p "${PANEL_INSTALL_STATE}"
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    tmp="$(mktemp "${PANEL_INSTALL_STATE}/.manifest.XXXXXX")"

    {
        printf '{\n'
        printf '  "started_at": "%s",\n' "$now"
        printf '  "panel_dir": "%s",\n' "$(_json_escape "${PANEL_DIR:-}")"
        printf '  "php_version": "%s",\n' "$(_json_escape "${PHP_VERSION:-}")"
        printf '  "steps": [\n'

        for entry in "$@"; do
            key="${entry%%|*}"
            label="${entry#*|}"
            [[ "$first" -eq 1 ]] || printf ',\n'
            first=0
            printf '    {"key": "%s", "label": "%s"}' "$(_json_escape "$key")" "$(_json_escape "$label")"
        done

        printf '\n  ]\n}\n'
    } > "$tmp"

    chmod 0644 "$tmp"
    mv -f "$tmp" "${PANEL_INSTALL_STATE}/manifest.json"
}

# panel_install_set_derived KEY VALUE — a value discovered by a step (server
# IP, backup dir, deferred setup) that later steps and the summary read.
panel_install_set_derived() {
    local key="$1" value="$2" file="${PANEL_INSTALL_STATE}/derived.env"

    mkdir -p "${PANEL_INSTALL_STATE}"

    if [[ -f "$file" ]]; then
        grep -v "^${key}=" "$file" > "${file}.tmp" 2>/dev/null || true
        mv -f "${file}.tmp" "$file"
    fi

    printf '%s=%q\n' "$key" "$value" >> "$file"
    chmod 0644 "$file"
}

# Last few meaningful lines of a step log, collapsed to one line for the
# panel's Setup page.
panel_install_tail_message() {
    local log="$1"

    [[ -f "$log" ]] || return 0

    sed -r 's/\x1B\[[0-9;]*[A-Za-z]//g; /^[[:space:]]*$/d' "$log" \
        | tail -n 5 \
        | tr '\n' ' ' \
        | cut -c1-500
}

# ---------------------------------------------------------------
# Step lifecycle
# ---------------------------------------------------------------
panel_install_on_error() {
    local line="$1" cmd="$2" status="$?"
    local msg="line ${line} — command failed (exit ${status}): ${cmd}"

    printf '\n%s[FAIL]%s %s: %s\n' \
        "${_C_RED}" "${_C_RESET}" "${PANEL_STEP_SCRIPT:-step.sh}" "$msg" >&2

    if [[ -n "${PANEL_STEP_KEY:-}" ]]; then
        panel_install_write_step "${PANEL_STEP_KEY}" "${PANEL_STEP_LABEL:-}" failed "$msg" "$status"
    fi

    exit "$status"
}

# install_step_init KEY LABEL
install_step_init() {
    PANEL_STEP_KEY="$1"
    PANEL_STEP_LABEL="${2:-$1}"
    PANEL_STEP_SCRIPT="$(basename "${BASH_SOURCE[1]:-step.sh}")"

    panel_install_load_config
    panel_install_ensure_dirs
    panel_install_write_step "$PANEL_STEP_KEY" "$PANEL_STEP_LABEL" running ""

    set -E
    trap 'panel_install_on_error "$LINENO" "$BASH_COMMAND"' ERR
}

install_step_done() {
    panel_install_write_step "${PANEL_STEP_KEY:-step}" "${PANEL_STEP_LABEL:-}" success ""
}
