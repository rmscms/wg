#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash install.sh"
    exit 1
fi

PANEL_DIR="/opt/wg-panel"
WEB_ROOT="${PANEL_DIR}/public"
APACHE_SITE="wg-panel"
WG_INTERFACE="wg0"
WG_PORT="51820"
SERVER_IP=""
WG_DOMAIN=""
PANEL_DOMAIN=""
LE_EMAIL=""
ADMIN_USER="admin"
ADMIN_PASS=""
DB_NAME="wg_panel"
DB_USER="wg_panel"
DB_PASS=""
API_TOKEN=""
SUBSCRIBE_BASE_URL=""

normalize_domain() {
    local d="$1"
    d="${d#https://}"
    d="${d#http://}"
    d="${d%%/*}"
    d="${d%%:*}"
    echo "$d" | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
}

resolve_domain_ip() {
    local domain="$1"
    local ip=""

    ip=$(getent ahostsv4 "$domain" 2>/dev/null | awk 'NR==1 {print $1; exit}')
    if [[ -z "$ip" ]] && command -v dig >/dev/null 2>&1; then
        ip=$(dig +short A "$domain" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
    fi
    if [[ -z "$ip" ]] && command -v host >/dev/null 2>&1; then
        ip=$(host -t A "$domain" 2>/dev/null | awk '/has address/ {print $4; exit}')
    fi

    echo "$ip"
}

prompt_domain() {
    local label="$1"
    local var_name="$2"
    local value=""

    while [[ -z "$value" ]]; do
        read -rp "${label}: " value
        value="$(normalize_domain "$value")"
        if [[ -z "$value" ]]; then
            echo "Domain is required."
        elif [[ ! "$value" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]]; then
            echo "Invalid domain format."
            value=""
        fi
    done

    printf -v "$var_name" '%s' "$value"
}

warn_dns() {
    local domain="$1"
    local expected_ip="$2"
    local resolved
    resolved="$(resolve_domain_ip "$domain")"

    if [[ -z "$resolved" ]]; then
        echo "WARNING: DNS A record not found for ${domain}"
        return 1
    fi
    if [[ "$resolved" != "$expected_ip" ]]; then
        echo "WARNING: ${domain} resolves to ${resolved} (expected ${expected_ip})"
        return 1
    fi
    echo "OK: ${domain} -> ${resolved}"
    return 0
}

echo "==> WireGuard Panel installer"
echo

read -rp "Server public IP [auto-detect]: " SERVER_IP
if [[ -z "$SERVER_IP" ]]; then
    SERVER_IP=$(curl -4 -s ifconfig.me || hostname -I | awk '{print $1}')
fi
echo "Detected server IP: ${SERVER_IP}"
echo

prompt_domain "WireGuard endpoint domain (clients connect to this, e.g. vpn.example.com)" WG_DOMAIN
prompt_domain "Panel / subscribe domain (web UI + SSL, e.g. panel.example.com)" PANEL_DOMAIN

while [[ -z "$LE_EMAIL" ]]; do
    read -rp "Email for Let's Encrypt SSL: " LE_EMAIL
    if [[ -z "$LE_EMAIL" ]]; then
        echo "Email is required for SSL certificate."
    fi
done

echo
echo "==> DNS check (A records must point to ${SERVER_IP})"
warn_dns "$WG_DOMAIN" "$SERVER_IP" || true
warn_dns "$PANEL_DOMAIN" "$SERVER_IP" || true
echo
read -rp "Continue installation? [Y/n]: " confirm
confirm="${confirm:-Y}"
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

read -rp "Admin username [admin]: " ADMIN_USER
ADMIN_USER="${ADMIN_USER:-admin}"

while [[ -z "$ADMIN_PASS" ]]; do
    read -rsp "Admin password: " ADMIN_PASS
    echo
done

DB_PASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
API_TOKEN=$(openssl rand -hex 32)

read -rp "MariaDB database name [${DB_NAME}]: " input_db
DB_NAME="${input_db:-$DB_NAME}"

read -rp "MariaDB username [${DB_USER}]: " input_user
DB_USER="${input_user:-$DB_USER}"

echo "==> Installing packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
    wireguard wireguard-tools mariadb-server mariadb-client \
    php8.3 php8.3-mysql php8.3-cli php8.3-gd \
    apache2 libapache2-mod-php8.3 \
    iptables iproute2 curl composer unzip \
    certbot python3-certbot-apache

if ! php -r 'exit(extension_loaded("sodium") ? 0 : 1);'; then
    echo "ERROR: PHP sodium extension is not available."
    echo "On Ubuntu 24.04 it should be included in php8.3-cli by default."
    echo "Try: sudo apt-get install --reinstall php8.3-cli"
    exit 1
fi

echo "==> Copying panel files..."
mkdir -p "$PANEL_DIR"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --exclude '.git' ./ "$PANEL_DIR/"
else
    cp -a . "$PANEL_DIR/"
    rm -rf "$PANEL_DIR/.git" 2>/dev/null || true
fi
chmod +x "$PANEL_DIR"/scripts/*.sh
chmod +x "$PANEL_DIR"/scripts/*.php 2>/dev/null || true
sed -i 's/\r$//' "$PANEL_DIR"/install.sh "$PANEL_DIR"/uninstall.sh "$PANEL_DIR"/scripts/*.sh 2>/dev/null || true

if [[ ! -f "$PANEL_DIR/public/assets/fonts/Vazirmatn-Regular.woff2" ]]; then
    echo "==> Downloading local fonts..."
    bash "$PANEL_DIR/scripts/download-fonts.sh"
fi

if [[ ! -f "$PANEL_DIR/public/assets/swagger/swagger-ui-bundle.js" ]]; then
    echo "==> Downloading Swagger UI assets..."
    bash "$PANEL_DIR/scripts/download-swagger-ui.sh"
fi

echo "==> Installing PHP dependencies..."
cd "$PANEL_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
chown -R www-data:www-data "$PANEL_DIR/vendor" 2>/dev/null || true

mkdir -p "$PANEL_DIR/storage/sessions"
mkdir -p "$PANEL_DIR/storage/login-throttle"
mkdir -p "$PANEL_DIR/storage/backups"
chown -R www-data:www-data "$PANEL_DIR/storage"
chmod 750 "$PANEL_DIR/storage"
chmod 750 "$PANEL_DIR/storage/sessions"
chmod 750 "$PANEL_DIR/storage/login-throttle"
chmod 750 "$PANEL_DIR/storage/backups"

echo "==> Setting up MariaDB..."
systemctl enable mariadb >/dev/null 2>&1 || systemctl enable mysql >/dev/null 2>&1 || true
systemctl start mariadb >/dev/null 2>&1 || systemctl start mysql >/dev/null 2>&1 || true

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql "${DB_NAME}" < "$PANEL_DIR/database/schema.sql"

echo "==> Generating config..."
PASS_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")

if [[ ! -f /etc/wireguard/${WG_INTERFACE}.private ]]; then
    umask 077
    wg genkey | tee /etc/wireguard/${WG_INTERFACE}.private | wg pubkey > /etc/wireguard/${WG_INTERFACE}.public
fi

SERVER_PRIVATE=$(cat /etc/wireguard/${WG_INTERFACE}.private)
SERVER_PUBLIC=$(cat /etc/wireguard/${WG_INTERFACE}.public)
WAN_IF=$(ip route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="dev") print $(i+1); exit}')

cat > "$PANEL_DIR/config/config.php" <<PHP
<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'WireGuard Panel',
        'timezone' => 'Asia/Tehran',
        'debug' => false,
        'subscribe_base_url' => 'https://${PANEL_DOMAIN}',
    ],
    'api' => [
        'enabled' => true,
        'token' => '${API_TOKEN}',
    ],
    'admin' => [
        'username' => '${ADMIN_USER}',
        'password_hash' => '${PASS_HASH}',
        'login_path' => '',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => '${DB_NAME}',
        'username' => '${DB_USER}',
        'password' => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],
    'wireguard' => [
        'interface' => '${WG_INTERFACE}',
        'config_path' => '/etc/wireguard/${WG_INTERFACE}.conf',
        'endpoint' => '${WG_DOMAIN}:${WG_PORT}',
        'subnet' => '10.66.0.0/19',
        'server_ip' => '10.66.0.1',
        'dns' => '1.1.1.1, 8.8.8.8',
        'allowed_ips' => '0.0.0.0/0, ::/0',
        'persistent_keepalive' => 25,
        'mtu' => 142,
        'handshake_timeout' => 180,
        'online_timeout' => 180,
    ],
    'scripts' => [
        'apply_peer' => '${PANEL_DIR}/scripts/apply-peer.sh',
        'remove_peer' => '${PANEL_DIR}/scripts/remove-peer.sh',
        'sync_traffic' => '${PANEL_DIR}/scripts/sync-traffic.php',
        'check_limits' => '${PANEL_DIR}/scripts/check-limits.php',
        'backup' => '${PANEL_DIR}/scripts/backup.php',
    ],
    'backup' => [
        'enabled' => false,
        'interval_hours' => 24,
        'include_wg_conf' => true,
        'include_database' => true,
        'retention_count' => 14,
        'last_run_at' => 0,
    ],
];
PHP

chmod 640 "$PANEL_DIR/config/config.php"
chown root:www-data "$PANEL_DIR/config/config.php"

if [[ ! -f /etc/wireguard/${WG_INTERFACE}.conf ]]; then
    cat > /etc/wireguard/${WG_INTERFACE}.conf <<WGCONF
[Interface]
Address = 10.66.0.1/19
ListenPort = ${WG_PORT}
PrivateKey = ${SERVER_PRIVATE}
PostUp = iptables -A FORWARD -i %i -j ACCEPT; iptables -A FORWARD -o %i -j ACCEPT; iptables -t nat -A POSTROUTING -o ${WAN_IF} -j MASQUERADE
PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -D FORWARD -o %i -j ACCEPT; iptables -t nat -D POSTROUTING -o ${WAN_IF} -j MASQUERADE
WGCONF
    chmod 600 /etc/wireguard/${WG_INTERFACE}.conf
fi

sysctl -w net.ipv4.ip_forward=1
grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

systemctl enable "wg-quick@${WG_INTERFACE}"
systemctl restart "wg-quick@${WG_INTERFACE}"

echo "==> Configuring Apache..."
cat > "/etc/apache2/sites-available/${APACHE_SITE}.conf" <<APACHE
<VirtualHost *:80>
    ServerName ${PANEL_DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory ${PANEL_DIR}>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/wg-panel-error.log
    CustomLog \${APACHE_LOG_DIR}/wg-panel-access.log combined
</VirtualHost>
APACHE

a2ensite "${APACHE_SITE}" >/dev/null
a2enmod rewrite ssl headers php8.3 >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
systemctl reload apache2

echo "==> Obtaining SSL certificate (Let's Encrypt)..."
SUBSCRIBE_BASE_URL="https://${PANEL_DOMAIN}"
if certbot --apache \
    -d "${PANEL_DOMAIN}" \
    --non-interactive \
    --agree-tos \
    -m "${LE_EMAIL}" \
    --redirect; then
    echo "SSL certificate installed."
else
    echo "WARNING: SSL setup failed."
    echo "Ensure DNS A record for ${PANEL_DOMAIN} -> ${SERVER_IP} and ports 80/443 are open."
    echo "Then run:"
    echo "  certbot --apache -d ${PANEL_DOMAIN} -m ${LE_EMAIL} --redirect"
    SUBSCRIBE_BASE_URL="http://${PANEL_DOMAIN}"

    php <<PHPFIX
<?php
\$file = '${PANEL_DIR}/config/config.php';
\$config = require \$file;
\$config['app']['subscribe_base_url'] = '${SUBSCRIBE_BASE_URL}';
\$export = var_export(\$config, true);
file_put_contents(\$file, "<?php\\n\\ndeclare(strict_types=1);\\n\\nreturn " . \$export . ";\\n");
PHPFIX
fi

echo "==> Configuring sudo..."
cat > /etc/sudoers.d/wg-panel <<SUDO
www-data ALL=(root) NOPASSWD: ${PANEL_DIR}/scripts/apply-peer.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${PANEL_DIR}/scripts/apply-peer.sh *
www-data ALL=(root) NOPASSWD: ${PANEL_DIR}/scripts/remove-peer.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${PANEL_DIR}/scripts/remove-peer.sh *
www-data ALL=(root) NOPASSWD: ${PANEL_DIR}/scripts/sync-traffic.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${PANEL_DIR}/scripts/sync-traffic.sh *
www-data ALL=(root) NOPASSWD: ${PANEL_DIR}/scripts/check-limits.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${PANEL_DIR}/scripts/check-limits.sh *
www-data ALL=(root) NOPASSWD: /usr/bin/php ${PANEL_DIR}/scripts/sync-wg.php
www-data ALL=(root) NOPASSWD: /usr/bin/php ${PANEL_DIR}/scripts/sync-wg.php *
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} public-key
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} transfer
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} latest-handshakes
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} peers
www-data ALL=(root) NOPASSWD: /usr/bin/wg show ${WG_INTERFACE} dump
www-data ALL=(root) NOPASSWD: /usr/bin/wg set ${WG_INTERFACE} peer *
SUDO
chmod 440 /etc/sudoers.d/wg-panel

echo "==> Setting up cron..."
cat > /etc/cron.d/wg-panel <<CRON
*/5 * * * * root php ${PANEL_DIR}/scripts/check-limits.php >> /var/log/wg-panel-limits.log 2>&1
*/5 * * * * root php ${PANEL_DIR}/scripts/sync-traffic.php >> /var/log/wg-panel-sync.log 2>&1
0 * * * * www-data php ${PANEL_DIR}/scripts/backup.php >> /var/log/wg-panel-backup.log 2>&1
15 3 * * * root find ${PANEL_DIR}/storage/sessions -name 'sess_*' -mmin +4320 -delete
CRON

touch /var/log/wg-panel-sync.log /var/log/wg-panel-limits.log /var/log/wg-panel-backup.log

echo
echo "============================================"
echo " WireGuard Panel installed successfully!"
echo "============================================"
echo " Panel URL       : ${SUBSCRIBE_BASE_URL}/"
echo " API docs        : ${SUBSCRIBE_BASE_URL}/api/docs"
echo " API token       : ${API_TOKEN}"
echo " Subscribe base  : ${SUBSCRIBE_BASE_URL}"
echo " WG endpoint     : ${WG_DOMAIN}:${WG_PORT}"
echo " Server IP       : ${SERVER_IP}"
echo " Username        : ${ADMIN_USER}"
echo " Database        : ${DB_NAME} @ localhost"
echo " DB User         : ${DB_USER}"
echo " DB Pass         : ${DB_PASS}"
echo " WG Port (UDP)   : ${WG_PORT}"
echo " Server PK       : ${SERVER_PUBLIC}"
echo "============================================"
echo " DNS required:"
echo "   ${WG_DOMAIN}    A -> ${SERVER_IP}"
echo "   ${PANEL_DOMAIN} A -> ${SERVER_IP}"
echo " Firewall:"
echo "   ufw allow ${WG_PORT}/udp"
echo "   ufw allow 80/tcp"
echo "   ufw allow 443/tcp"
echo " SSL renew (auto via certbot timer):"
echo "   certbot renew --dry-run"
echo "============================================"
echo " To uninstall: sudo bash ${PANEL_DIR}/uninstall.sh"
echo "============================================"
