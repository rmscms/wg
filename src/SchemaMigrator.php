<?php

declare(strict_types=1);

namespace WgPanel;

use PDO;
use PDOException;
use RuntimeException;

final class SchemaMigrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationsDir,
    ) {
    }

    public function apply(): void
    {
        $this->ensureMigrationsTable();

        $files = glob(rtrim($this->migrationsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = $this->appliedIds();

        foreach ($files as $path) {
            $id = basename($path, '.sql');

            if (isset($applied[$id])) {
                continue;
            }

            $sql = (string) file_get_contents($path);

            foreach ($this->splitStatements($sql) as $statement) {
                try {
                    $this->db->exec($statement);
                } catch (PDOException $e) {
                    if (!$this->isIgnorable($e)) {
                        throw $e;
                    }
                }
            }

            $stmt = $this->db->prepare(
                'INSERT INTO schema_migrations (id) VALUES (:id)'
            );
            $stmt->execute(['id' => $id]);
        }
    }

    /** @return array<string, true> */
    private function appliedIds(): array
    {
        $ids = [];
        $stmt = $this->db->query('SELECT id FROM schema_migrations');

        foreach ($stmt->fetchAll() as $row) {
            $ids[(string) $row['id']] = true;
        }

        return $ids;
    }

    private function isIgnorable(PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate column')
            || str_contains($message, 'Duplicate key')
            || str_contains($message, 'already exists');
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id VARCHAR(128) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
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

    public static function run(PDO $db): void
    {
        $dir = dirname(__DIR__) . '/database/migrations';

        if (!is_dir($dir)) {
            return;
        }

        try {
            (new self($db, $dir))->apply();
        } catch (PDOException $e) {
            throw new RuntimeException('Database migration failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
