#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash scripts/fix-permissions.sh"
    exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="${ROOT}/config/config.php"
WG_INTERFACE="wg0"

if [[ -f "$CONFIG" ]] && command -v php >/dev/null 2>&1; then
    WG_INTERFACE="$(php -r '$c=require $argv[1]; echo $c["wireguard"]["interface"] ?? "wg0";' "$CONFIG" 2>/dev/null || echo wg0)"
    if [[ -z "$WG_INTERFACE" ]]; then
        WG_INTERFACE="wg0"
    fi
fi

echo "==> Scripts executable"
chmod +x "${ROOT}/scripts/"*.sh "${ROOT}/scripts/"*.php 2>/dev/null || true
sed -i 's/\r$//' "${ROOT}/scripts/"*.sh 2>/dev/null || true

echo "==> Storage directories"
mkdir -p "${ROOT}/storage/sessions" "${ROOT}/storage/login-throttle" "${ROOT}/storage/backups"
chown -R www-data:www-data "${ROOT}/storage"
chmod 750 "${ROOT}/storage" "${ROOT}/storage/sessions" "${ROOT}/storage/login-throttle" "${ROOT}/storage/backups"

if [[ -f "$CONFIG" ]]; then
    echo "==> config.php stays mode 640 (settings live in the database)"
    chown root:www-data "$CONFIG"
    chmod 640 "$CONFIG"
fi

echo "==> sudoers for WireGuard + backup"
cat > /etc/sudoers.d/wg-panel <<SUDO
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/apply-peer.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/apply-peer.sh *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/remove-peer.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/remove-peer.sh *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/sync-traffic.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/sync-traffic.sh *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/check-limits.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/check-limits.sh *
www-data ALL=(root) NOPASSWD: /usr/bin/php ${ROOT}/scripts/sync-wg.php
www-data ALL=(root) NOPASSWD: /usr/bin/php ${ROOT}/scripts/sync-wg.php *
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} public-key
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} transfer
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} latest-handshakes
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} peers
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} dump
www-data ALL=(root) NOPASSWD: /usr/bin/wg set ${WG_INTERFACE} peer *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/read-wg-conf.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/read-wg-conf.sh *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/restore-wg-conf.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/restore-wg-conf.sh *
www-data ALL=(root) NOPASSWD: ${ROOT}/scripts/persist-wg-peers.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${ROOT}/scripts/persist-wg-peers.sh *
SUDO
chmod 440 /etc/sudoers.d/wg-panel

if command -v visudo >/dev/null 2>&1; then
    visudo -cf /etc/sudoers.d/wg-panel
fi

echo "==> ip_forward"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

if [[ -f "$CONFIG" ]] && command -v php >/dev/null 2>&1; then
    echo "==> Database migrations"
    php "${ROOT}/scripts/migrate.php" || true
fi

echo "Done."
