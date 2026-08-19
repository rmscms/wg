#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

PANEL_DIR="/opt/wg-panel"
APACHE_SITE="wg-panel"
WG_INTERFACE="wg0"
DB_NAME="wg_panel"
DB_USER="wg_panel"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash uninstall.sh"
    exit 1
fi

read_config_value() {
    local key="$1"
    local default="$2"

    if [[ ! -f "${PANEL_DIR}/config/config.php" ]]; then
        echo "$default"
        return
    fi

    php -r '
        $config = require $argv[1];
        $parts = explode(".", $argv[2]);
        $value = $config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                echo $argv[3];
                exit(0);
            }
            $value = $value[$part];
        }
        echo is_scalar($value) ? (string) $value : $argv[3];
    ' "${PANEL_DIR}/config/config.php" "$key" "$default"
}

if [[ -d "$PANEL_DIR" ]]; then
    DB_NAME="$(read_config_value database.name "$DB_NAME")"
    DB_USER="$(read_config_value database.username "$DB_USER")"
    WG_INTERFACE="$(read_config_value wireguard.interface "$WG_INTERFACE")"
fi

echo "============================================"
echo " WireGuard Panel Uninstaller"
echo "============================================"
echo " Panel dir : ${PANEL_DIR}"
echo " Apache    : ${APACHE_SITE}"
echo " WireGuard : ${WG_INTERFACE}"
echo " Database  : ${DB_NAME}"
echo " DB user   : ${DB_USER}"
echo "============================================"
echo

read -rp "Continue uninstall? [y/N]: " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

read -rp "Remove panel files (${PANEL_DIR})? [Y/n]: " REMOVE_PANEL
REMOVE_PANEL="${REMOVE_PANEL:-Y}"

read -rp "Drop MariaDB database and user? [Y/n]: " REMOVE_DB
REMOVE_DB="${REMOVE_DB:-Y}"

read -rp "Stop and remove WireGuard server (${WG_INTERFACE})? [y/N]: " REMOVE_WG
REMOVE_WG="${REMOVE_WG:-N}"

read -rp "Remove installed apt packages (wireguard, mariadb, apache, php)? [y/N]: " REMOVE_PACKAGES
REMOVE_PACKAGES="${REMOVE_PACKAGES:-N}"

echo
echo "==> Stopping cron jobs..."
rm -f /etc/cron.d/wg-panel

echo "==> Removing sudo rules..."
rm -f /etc/sudoers.d/wg-panel

if [[ "$REMOVE_WG" =~ ^[Yy]$ ]]; then
    echo "==> Stopping WireGuard (${WG_INTERFACE})..."
    tc qdisc del dev "${WG_INTERFACE}" root 2>/dev/null || true
    systemctl stop "wg-quick@${WG_INTERFACE}" 2>/dev/null || wg-quick down "${WG_INTERFACE}" 2>/dev/null || true
    systemctl disable "wg-quick@${WG_INTERFACE}" 2>/dev/null || true

    rm -f "/etc/wireguard/${WG_INTERFACE}.conf"
    rm -f "/etc/wireguard/${WG_INTERFACE}.private"
    rm -f "/etc/wireguard/${WG_INTERFACE}.public"
fi

echo "==> Disabling Apache site..."
if command -v a2dissite >/dev/null 2>&1; then
    a2dissite "${APACHE_SITE}" >/dev/null 2>&1 || true
fi
rm -f "/etc/apache2/sites-available/${APACHE_SITE}.conf"
rm -f "/etc/apache2/sites-enabled/${APACHE_SITE}.conf"

if [[ -f /etc/apache2/sites-available/000-default.conf ]] && command -v a2ensite >/dev/null 2>&1; then
    a2query -s 000-default.conf >/dev/null 2>&1 || a2ensite 000-default.conf >/dev/null 2>&1 || true
fi

if systemctl is-active --quiet apache2 2>/dev/null; then
    systemctl reload apache2
fi

if [[ "$REMOVE_DB" =~ ^[Yy]$ ]]; then
    echo "==> Removing MariaDB database and user..."
    if command -v mysql >/dev/null 2>&1; then
        mysql <<SQL || true
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    else
        echo "mysql client not found, skipping database removal."
    fi
fi

if [[ "$REMOVE_PANEL" =~ ^[Yy]$ ]]; then
    echo "==> Removing panel directory..."
    rm -rf "$PANEL_DIR"
fi

echo "==> Removing log files..."
rm -f /var/log/wg-panel-sync.log /var/log/wg-panel-limits.log
rm -f /var/log/apache2/wg-panel-access.log /var/log/apache2/wg-panel-error.log 2>/dev/null || true

if [[ "$REMOVE_PACKAGES" =~ ^[Yy]$ ]]; then
    echo "==> Removing apt packages..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get remove -y --purge \
        wireguard wireguard-tools \
        mariadb-server mariadb-client \
        apache2 libapache2-mod-php8.3 \
        php8.3 php8.3-mysql php8.3-cli php8.3-gd \
        composer unzip 2>/dev/null || true
    apt-get autoremove -y 2>/dev/null || true
fi

echo
echo "============================================"
echo " Uninstall completed."
echo "============================================"
if [[ ! "$REMOVE_WG" =~ ^[Yy]$ ]]; then
    echo " WireGuard (${WG_INTERFACE}) was kept running."
fi
if [[ ! "$REMOVE_DB" =~ ^[Yy]$ ]]; then
    echo " MariaDB database '${DB_NAME}' was kept."
fi
if [[ ! "$REMOVE_PANEL" =~ ^[Yy]$ ]]; then
    echo " Panel directory was kept at ${PANEL_DIR}."
fi
echo "============================================"
