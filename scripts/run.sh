#!/usr/bin/env bash
#
# Panel privileged runner.
#
# Installed by install-panel.sh as /usr/local/panel/run (root:root, 0755)
# and referenced from /etc/sudoers.d/panel, so the panel web user may only
# execute allowlisted `panel:*` artisan commands as root — never a shell.
#
# The web application invokes it as:
#   sudo -n /usr/local/panel/run panel:service:restart caddy

set -euo pipefail

APP_DIR="${PANEL_APP_DIR:-/var/www/panel}"
PHP_BIN="${PANEL_PHP_BIN:-$(command -v php || true)}"
COMMAND="${1:-}"

if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
    echo "run: php binary not found" >&2
    exit 78
fi

if [[ ! "$COMMAND" =~ ^panel:[a-z0-9:-]+$ ]]; then
    echo "run: denied '${COMMAND}' — only panel:* commands are allowed" >&2
    exit 77
fi

if [[ ! -f "$APP_DIR/artisan" ]]; then
    echo "run: panel application not found at ${APP_DIR}" >&2
    exit 78
fi

shift
exec "$PHP_BIN" "$APP_DIR/artisan" "$COMMAND" "$@"
