<?php

declare(strict_types=1);

namespace WgPanel;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connect(array $config): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $db = $config['database'];
        $charset = $db['charset'] ?? 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) ($db['port'] ?? 3306),
            $db['name'],
            $charset
        );

        self::$instance = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $charset . ' COLLATE utf8mb4_unicode_ci',
        ]);

        self::migrate(self::$instance);

        return self::$instance;
    }

    private static function migrate(PDO $db): void
    {
        $schemaPath = dirname(__DIR__) . '/database/schema.sql';

        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema file not found.');
        }

        $sql = (string) file_get_contents($schemaPath);

        foreach (self::splitStatements($sql) as $statement) {
            $db->exec($statement);
        }

        self::ensureColumn($db, 'accounts', 'last_wg_rx_bytes', 'BIGINT UNSIGNED NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'last_wg_tx_bytes', 'BIGINT UNSIGNED NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'subscribe_token', 'VARCHAR(64) NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'sub_short', 'VARCHAR(12) NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'expiry_mode', "VARCHAR(20) NOT NULL DEFAULT 'fixed'");
        self::ensureColumn($db, 'accounts', 'expiry_duration_days', 'INT UNSIGNED NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'first_connected_at', 'DATETIME NULL DEFAULT NULL');
        self::ensureColumn($db, 'accounts', 'expiry_await_reconnect', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::ensureUniqueIndex($db, 'accounts', 'uk_accounts_subscribe_token', 'subscribe_token');
        self::ensureUniqueIndex($db, 'accounts', 'uk_accounts_sub_short', 'sub_short');
        self::backfillSubscribeTokens($db);
        SchemaMigrator::run($db);
    }

    private static function ensureUniqueIndex(PDO $db, string $table, string $indexName, string $column): void
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index'
        );
        $stmt->execute(['table' => $table, 'index' => $indexName]);

        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("CREATE UNIQUE INDEX `{$indexName}` ON `{$table}` (`{$column}`)");
        }
    }

    private static function backfillSubscribeTokens(PDO $db): void
    {
        $stmt = $db->query('SELECT id FROM accounts WHERE subscribe_token IS NULL OR subscribe_token = \'\'');

        foreach ($stmt->fetchAll() as $row) {
            $token = bin2hex(random_bytes(32));
            $update = $db->prepare('UPDATE accounts SET subscribe_token = :token WHERE id = :id');
            $update->execute([
                'token' => $token,
                'id' => (int) $row['id'],
            ]);
        }
    }

    private static function ensureColumn(PDO $db, string $table, string $column, string $definition): void
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';

        foreach (preg_split('/\R/', $sql) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $line . PHP_EOL;

            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim($buffer, " \t\n\r\0\x0B;");

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $buffer = '';
            }
        }

        return $statements;
    }
}
