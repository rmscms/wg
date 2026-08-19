-- MariaDB schema for WireGuard Panel

CREATE TABLE IF NOT EXISTS accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    public_key VARCHAR(64) NOT NULL,
    private_key VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    speed_limit_kbps INT UNSIGNED NOT NULL DEFAULT 0,
    volume_limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    volume_used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_wg_rx_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
    last_wg_tx_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
    subscribe_token VARCHAR(64) NULL DEFAULT NULL,
    sub_short VARCHAR(12) NULL DEFAULT NULL,
    expires_at DATETIME NULL DEFAULT NULL,
    expiry_mode VARCHAR(20) NOT NULL DEFAULT 'fixed',
    expiry_duration_days INT UNSIGNED NULL DEFAULT NULL,
    first_connected_at DATETIME NULL DEFAULT NULL,
    expiry_await_reconnect TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_accounts_public_key (public_key),
    UNIQUE KEY uk_accounts_ip_address (ip_address),
    UNIQUE KEY uk_accounts_subscribe_token (subscribe_token),
    UNIQUE KEY uk_accounts_sub_short (sub_short),
    KEY idx_accounts_active (is_active),
    KEY idx_accounts_expires (expires_at),
    KEY idx_accounts_active_expires (is_active, expires_at),
    KEY idx_accounts_active_volume (is_active, volume_limit_bytes, volume_used_bytes),
    KEY idx_accounts_name (name),
    KEY idx_accounts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS traffic_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_traffic_account (account_id),
    KEY idx_traffic_account_recorded (account_id, recorded_at),
    KEY idx_traffic_recorded (recorded_at),
    CONSTRAINT fk_traffic_account
        FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id VARCHAR(128) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS panel_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
