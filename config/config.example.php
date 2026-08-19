<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'WireGuard Panel',
        'timezone' => 'Asia/Tehran',
        'debug' => false,
        // Optional: https://vpn.example.com
        'subscribe_base_url' => '',
    ],

    'api' => [
        'enabled' => true,
        // Bearer token for REST API (Authorization: Bearer ...)
        'token' => 'CHANGE_ME_GENERATE_RANDOM_HEX',
        // Empty = all IPs allowed for Bearer requests
        'allowed_ips' => [],
        // Pagination for GET /api/v1/accounts (client sets ?per_page=...)
        'pagination' => [
            'default_per_page' => 20,
            'min_per_page' => 1,
            // 0 = no upper limit (client chooses any per_page >= min)
            'max_per_page' => 0,
        ],
    ],

    'admin' => [
        'username' => 'admin',
        // Generate with: php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
        'password_hash' => '$2y$10$CHANGE_ME_GENERATE_WITH_PHP',
        // Custom admin login URL slug (empty = /login.php). Example: mandooli-x7k9 → /mandooli-x7k9
        'login_path' => '',
    ],

    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'wg_panel',
        'username' => 'wg_panel',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'wireguard' => [
        'interface' => 'wg0',
        'config_path' => '/etc/wireguard/wg0.conf',
        'endpoint' => 'YOUR_SERVER_IP:51820',
        'subnet' => '10.66.0.0/19',
        'server_ip' => '10.66.0.1',
        'dns' => '1.1.1.1, 8.8.8.8',
        'allowed_ips' => '0.0.0.0/0, ::/0',
        'persistent_keepalive' => 25,
        'mtu' => 1420,
        // handshake_timeout: used by traffic sync / limit enforcement
        'handshake_timeout' => 180,
        // online_timeout: UI/API online status. Should be >= persistent_keepalive + 15.
        // With keepalive 25, effective minimum is 40 seconds.
        'online_timeout' => 45,
    ],

    'scripts' => [
        'apply_peer' => '/opt/wg-panel/scripts/apply-peer.sh',
        'remove_peer' => '/opt/wg-panel/scripts/remove-peer.sh',
        'sync_traffic' => '/opt/wg-panel/scripts/sync-traffic.php',
        'check_limits' => '/opt/wg-panel/scripts/check-limits.php',
        'sync_wg' => '/opt/wg-panel/scripts/sync-wg.php',
        'backup' => '/opt/wg-panel/scripts/backup.php',
        'read_wg_conf' => '/opt/wg-panel/scripts/read-wg-conf.sh',
        'restore_wg_conf' => '/opt/wg-panel/scripts/restore-wg-conf.sh',
    ],

    'backup' => [
        'enabled' => false,
        'interval_hours' => 24,
        'include_wg_conf' => true,
        'include_database' => true,
        'retention_count' => 14,
        'last_run_at' => 0,
        'backup_dir' => '',
    ],

    'telegram' => [
        'bot_token' => '',
        'chat_id' => '',
        'send_auto_backup' => false,
    ],
];
