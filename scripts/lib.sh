#!/usr/bin/env bash
# shellcheck shell=bash
#
# Shared helpers for the panel's shell scripts. Source it, never execute it:
#
#   source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"       # scripts/*.sh
#   source "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"    # scripts/legacy/*.sh
#
# What every wizard gets from it:
#   · panel_init   — [FAIL] line with script/line/command when any command
#     dies. `set -e` alone killed scripts silently, so nothing was visible.
#   · require_tty  — refuses to run without a terminal instead of silently
#     eating piped stdin ("curl | bash" would swallow the script itself).
#   · ask / ask_secret / pause — EOF-safe prompts with defaults.
#   · step / ok / warn / fail — uniform, colour-only-when-a-terminal output.
#   · github_ssh_ok — ssh check that never hides ssh's own prompts (the
#     host-key question used to be swallowed by the pipe and hang forever).

if [[ -z "${BASH_VERSION:-}" ]]; then
    printf 'This script must be run with bash:  bash %s\n' "${0}" >&2
    exit 1
fi

# ---------------------------------------------------------------
# Output (ANSI only when the stream is a terminal, so logs stay clean)
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

step() { printf '%s==>%s %s\n' "${_C_BLUE}" "${_C_RESET}" "$*"; }
ok()   { printf '%s[ OK ]%s %s\n' "${_C_GREEN}" "${_C_RESET}" "$*"; }
warn() { printf '%s[WARN]%s %s\n' "${_C_YELLOW}" "${_C_RESET}" "$*"; }

fail() {
    printf '%s[FAIL]%s %s\n' "${_C_RED}" "${_C_RESET}" "$*" >&2
    exit 1
}

# ---------------------------------------------------------------
# Error visibility — called from the ERR trap installed by panel_init
# ---------------------------------------------------------------
panel_fail_at() {
    local __status="$1" __line="$2" __cmd="$3"

    printf '\n%s[FAIL]%s %s: line %s — command failed (exit %s)\n' \
        "${_C_RED}" "${_C_RESET}" "${PANEL_SCRIPT_NAME:-$0}" "$__line" "$__status" >&2
    printf '       %s\n' "$__cmd" >&2
    printf '       Script stopped here — nothing after this line ran.\n' >&2
    exit "$__status"
}

# Same, but for scripts that keep going after a failed command.
panel_warn_at() {
    local __status="$1" __line="$2" __cmd="$3"

    PANEL_FAILURES=$((${PANEL_FAILURES:-0} + 1))
    printf '%s[WARN]%s %s: line %s — command failed (exit %s): %s\n' \
        "${_C_YELLOW}" "${_C_RESET}" "${PANEL_SCRIPT_NAME:-$0}" "$__line" "$__status" "$__cmd" >&2
}

# Call before the final "done" message of a --continue-on-error script.
panel_report() {
    if [[ "${PANEL_FAILURES:-0}" -gt 0 ]]; then
        warn "${PANEL_FAILURES} command(s) failed — read the [WARN] lines above before trusting this result."
    fi
}

# panel_init [--continue-on-error] "${BASH_SOURCE[0]:-$0}"
panel_init() {
    PANEL_FAILURES=0

    if [[ "${1:-}" == "--continue-on-error" ]]; then
        PANEL_CONTINUE_ON_ERROR=1
        shift
    else
        PANEL_CONTINUE_ON_ERROR=0
    fi

    PANEL_SCRIPT_PATH="${1:-$0}"
    PANEL_SCRIPT_NAME="$(basename "$PANEL_SCRIPT_PATH")"
    set -E

    if [[ "$PANEL_CONTINUE_ON_ERROR" == "1" ]]; then
        trap 'panel_warn_at "$?" "$LINENO" "$BASH_COMMAND"' ERR
    else
        trap 'panel_fail_at "$?" "$LINENO" "$BASH_COMMAND"' ERR
    fi
}

# ---------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------
require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        fail "Run this script as root:  sudo bash ${PANEL_SCRIPT_PATH:-script.sh}"
    fi
}

require_tty() {
    if [[ ! -t 0 ]]; then
        fail "This is an interactive wizard but stdin is not a terminal.
       Piping it (curl … | bash) makes stdin the script itself, so it
       cannot ask anything and would stop at the first prompt.
       Run it in a shell instead:  sudo bash ${PANEL_SCRIPT_PATH:-script.sh}"
    fi
}

# ---------------------------------------------------------------
# Prompts — EOF on stdin ends the run with a message instead of
# letting `set -e` kill the script in silence.
# ---------------------------------------------------------------

# ask VAR "Question" ["default"]
ask() {
    local __var="$1" __q="$2" __def="${3:-}" __a=""

    [[ -t 0 ]] || fail "No terminal to answer: ${__q}"

    if [[ -n "$__def" ]]; then
        read -r -p "${__q} [${__def}]: " __a || fail "Input ended while asking: ${__q}"
        __a="${__a:-$__def}"
    else
        read -r -p "${__q}: " __a || fail "Input ended while asking: ${__q}"
    fi

    printf -v "$__var" '%s' "$__a"
}

# ask_secret VAR "Question"
ask_secret() {
    local __var="$1" __q="$2" __a=""

    [[ -t 0 ]] || fail "No terminal to answer: ${__q}"

    read -r -s -p "${__q}: " __a || fail "Input ended while asking: ${__q}"
    printf -v "$__var" '%s' "$__a"
    printf '\n'
}

# pause ["message"]
pause() {
    local __q="${1:-Press ENTER to continue...}" __a=""

    [[ -t 0 ]] || return 0

    read -r -p "${__q}" __a || fail "Input ended while waiting: ${__q}"
}

# ---------------------------------------------------------------
# Networking helpers that stay visible
# ---------------------------------------------------------------

# True when this machine can already authenticate with GitHub over SSH.
github_ssh_ok() {
    command -v ssh >/dev/null 2>&1 || return 1

    # BatchMode stops ssh from waiting for a password nobody can see and
    # accept-new answers the host-key question itself — both prompts were
    # previously swallowed by the pipe below and left the script hanging
    # with no output at all. stdin is closed so ssh can never eat what the
    # user types for the next prompt.
    ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 \
        -T git@github.com </dev/null 2>&1 | grep -q "successfully authenticated"
}

# download URL DEST — silent progress, loud failure.
download() {
    local __url="$1" __dest="$2"

    step "Downloading $(basename "$__dest")"
    curl -fsSL --retry 3 --connect-timeout 10 -o "$__dest" "$__url" \
        || fail "Download failed: ${__url}"
    [[ -s "$__dest" ]] || fail "Downloaded file is empty: ${__url}"
}
