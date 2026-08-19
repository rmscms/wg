<?php

declare(strict_types=1);

namespace WgPanel;

use PDO;
use PDOException;

final class SettingsStore
{
    /** @var array<string, list<string>> */
    public const GROUPS = [
        'app' => ['name', 'subscribe_base_url', 'timezone'],
        'wireguard' => [
            'endpoint',
            'dns',
            'allowed_ips',
            'persistent_keepalive',
            'online_timeout',
            'server_public_key',
        ],
        'admin' => ['username', 'password_hash', 'login_path'],
        'api' => ['enabled', 'token'],
        'backup' => [
            'enabled',
            'interval_hours',
            'include_wg_conf',
            'include_database',
            'retention_count',
            'last_run_at',
            'backup_dir',
        ],
    ];

    public static function ensureSeeded(PDO $db, array $config): void
    {
        if (!self::tableExists($db)) {
            return;
        }

        foreach (self::GROUPS as $group => $keys) {
            if (self::hasKey($db, $group)) {
                continue;
            }

            $payload = self::pick($config[$group] ?? [], $keys);
            self::writeGroup($db, $group, $payload);
        }
    }

    public static function overlay(PDO $db, array $config): array
    {
        $stored = self::all($db);

        foreach ($stored as $group => $values) {
            if (!is_array($values)) {
                continue;
            }

            $allowed = self::GROUPS[$group] ?? null;

            if ($allowed === null) {
                continue;
            }

            $current = is_array($config[$group] ?? null) ? $config[$group] : [];
            $config[$group] = array_merge($current, self::pick($values, $allowed));
        }

        return $config;
    }

    /**
     * Persist settings to DB (always) and config.php (if writable).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed> merged config
     */
    public static function update(PDO $db, array $config, array $changes, ?string $configPath = null): array
    {
        foreach ($changes as $group => $values) {
            if (!is_string($group) || !isset(self::GROUPS[$group]) || !is_array($values)) {
                continue;
            }

            $current = is_array($config[$group] ?? null) ? $config[$group] : [];
            $picked = self::pick($values, self::GROUPS[$group]);
            $merged = array_merge($current, $picked);
            $config[$group] = $merged;
            self::writeGroup($db, $group, self::pick($merged, self::GROUPS[$group]));
        }

        if ($configPath !== null && $configPath !== '' && is_writable($configPath)) {
            try {
                (new ConfigWriter($configPath))->update($changes);
            } catch (\Throwable) {
                // File stays as fallback; DB is the writable source.
            }
        }

        return $config;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(PDO $db): array
    {
        if (!self::tableExists($db)) {
            return [];
        }

        try {
            $stmt = $db->query('SELECT setting_key, setting_value FROM panel_settings');
        } catch (PDOException) {
            return [];
        }

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['setting_value'], true);

            if (is_array($decoded)) {
                $out[(string) $row['setting_key']] = $decoded;
            }
        }

        return $out;
    }

    private static function tableExists(PDO $db): bool
    {
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'panel_settings'");

            return $stmt !== false && $stmt->fetch() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    private static function hasKey(PDO $db, string $key): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM panel_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $payload */
    private static function writeGroup(PDO $db, string $group, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO panel_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([
            'key' => $group,
            'value' => $json,
        ]);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }

        return $out;
    }
}
