#!/usr/bin/env bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib.sh
source "$SCRIPT_DIR/lib.sh"
panel_init --continue-on-error "${BASH_SOURCE[0]}"

# Ensure the script is run with root privileges
require_root
require_tty

echo "=========================================================="
echo "WARNING: Full system cleanup initiated!"
echo "This will update the system and permanently remove installed"
echo "packages (PHP, Caddy, PowerDNS), /var/www, and user data."
echo "Your Root SSH configuration will be preserved."
echo "=========================================================="
ask REPLY "Are you sure you want to proceed? (y/N)" "n"
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
  echo "Cleanup cancelled."
  exit 1
fi

step "[1/8] Updating and upgrading system packages..."
apt-get update -y && apt-get dist-upgrade -y

step "[2/8] Backing up Root SSH configuration..."
mkdir -p /tmp/root_ssh_backup
if [ -d "/root/.ssh" ]; then
    cp -r /root/.ssh/* /tmp/root_ssh_backup/ 2>/dev/null || true
fi

step "[3/8] Removing all non-root users and their home directories..."
for user in $(awk -F: '($3 >= 1000 && $3 < 65534) {print $1}' /etc/passwd); do
    userdel -r "$user" 2>/dev/null || true
done

step "[4/8] Cleaning up /var/www directory..."
rm -rf /var/www/*

step "[5/8] Stopping services and removing web/DNS packages..."
systemctl stop caddy pdns php*-fpm 2>/dev/null || true
apt-get purge -y "php*" caddy pdns-server pdns-backend-* 2>/dev/null || true
apt-get autoremove --purge -y
apt-get clean

step "[6/8] Cleaning root directory (preserving SSH)..."
rm -rf /root/*
rm -rf /root/.* 2>/dev/null || true

step "[7/8] Restoring SSH credentials..."
mkdir -p /root/.ssh
if [ "$(ls -A /tmp/root_ssh_backup 2>/dev/null)" ]; then
    cp -r /tmp/root_ssh_backup/* /root/.ssh/
    rm -rf /tmp/root_ssh_backup
    chmod 700 /root/.ssh
    chmod 600 /root/.ssh/* 2>/dev/null || true
fi

step "[8/8] Clearing logs and system cache..."
rm -rf /var/cache/* /var/log/*

echo "=========================================================="
panel_report
ok "System reset complete! Please reboot the server now."
echo "=========================================================="
