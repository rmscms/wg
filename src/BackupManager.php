<?php

declare(strict_types=1);

namespace WgPanel;

use RuntimeException;

final class BackupManager
{
    private const FILENAME_PATTERN = '/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.tar\.gz$/';

    private string $backupDir;

    public function __construct(string $backupDir)
    {
        $this->backupDir = rtrim($backupDir, '/\\');
    }

    /** @return array{filename: string, path: string, size: int, created_at: int} */
    public function create(array $config, bool $includeWgConf, bool $includeDatabase): array
    {
        if (!$includeWgConf && !$includeDatabase) {
            throw new RuntimeException('حداقل یکی از wg0.conf یا دیتابیس باید انتخاب شود.');
        }

        $this->ensureBackupDir();

        $timestamp = time();
        $filename = 'backup_' . date('Y-m-d_H-i-s', $timestamp) . '.tar.gz';
        $archivePath = $this->backupDir . '/' . $filename;
        $stagingDir = $this->backupDir . '/.tmp_' . bin2hex(random_bytes(4));

        if (!mkdir($stagingDir, 0750, true) && !is_dir($stagingDir)) {
            throw new RuntimeException('امکان ساخت پوشه موقت بک‌آپ وجود ندارد.');
        }

        $items = [];

        try {
            if ($includeWgConf) {
                $this->copyWireguardConfig($config, $stagingDir);
                $items[] = 'wg0.conf';
            }

            if ($includeDatabase) {
                $this->dumpDatabase($config, $stagingDir . '/database.sql');
                $items[] = 'database.sql';
            }

            file_put_contents(
                $stagingDir . '/manifest.json',
                json_encode([
                    'created_at' => date('c', $timestamp),
                    'items' => $items,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            $this->createArchive($stagingDir, $archivePath);
        } finally {
            $this->removeDirectory($stagingDir);
        }

        if (!is_file($archivePath)) {
            throw new RuntimeException('ساخت فایل بک‌آپ ناموفق بود.');
        }

        return [
            'filename' => $filename,
            'path' => $archivePath,
            'size' => (int) filesize($archivePath),
            'created_at' => $timestamp,
        ];
    }

    /** @return list<array{filename: string, size: int, created_at: int}> */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/backup_*.tar.gz') ?: [];
        rsort($files, SORT_STRING);

        $backups = [];

        foreach ($files as $path) {
            $filename = basename($path);

            if (!self::isValidFilename($filename)) {
                continue;
            }

            $backups[] = [
                'filename' => $filename,
                'size' => (int) filesize($path),
                'created_at' => (int) filemtime($path),
            ];
        }

        return $backups;
    }

    public function delete(string $filename): void
    {
        $path = $this->resolveBackupPath($filename);

        if (!unlink($path)) {
            throw new RuntimeException('حذف بک‌آپ ناموفق بود.');
        }
    }

    public function resolveBackupPath(string $filename): string
    {
        if (!self::isValidFilename($filename)) {
            throw new RuntimeException('نام فایل بک‌آپ نامعتبر است.');
        }

        $path = $this->backupDir . '/' . $filename;

        if (!is_file($path)) {
            throw new RuntimeException('فایل بک‌آپ یافت نشد.');
        }

        return $path;
    }

    public function prune(int $retentionCount): void
    {
        $retentionCount = max(1, $retentionCount);
        $backups = $this->listBackups();

        foreach (array_slice($backups, $retentionCount) as $backup) {
            @unlink($this->backupDir . '/' . $backup['filename']);
        }
    }

    public function shouldRunAuto(array $config): bool
    {
        $backup = $config['backup'] ?? [];

        if (empty($backup['enabled'])) {
            return false;
        }

        $intervalHours = max(1, (int) ($backup['interval_hours'] ?? 24));
        $lastRunAt = (int) ($backup['last_run_at'] ?? 0);

        return $lastRunAt <= 0 || (time() - $lastRunAt) >= ($intervalHours * 3600);
    }

    /** @return array{filename: string, size: int} */
    public function runConfigured(array $config): array
    {
        $backup = $config['backup'] ?? [];
        $result = $this->create(
            $config,
            !empty($backup['include_wg_conf']),
            !empty($backup['include_database'])
        );

        $retention = max(1, (int) ($backup['retention_count'] ?? 14));
        $this->prune($retention);

        return [
            'filename' => $result['filename'],
            'size' => $result['size'],
        ];
    }

    public static function isValidFilename(string $filename): bool
    {
        return preg_match(self::FILENAME_PATTERN, $filename) === 1;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    /** @return list<int> */
    public static function intervalOptions(): array
    {
        return [6, 12, 24, 48, 168];
    }

    private function ensureBackupDir(): void
    {
        if (is_dir($this->backupDir)) {
            return;
        }

        if (!mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('پوشه بک‌آپ قابل ساخت نیست: ' . $this->backupDir);
        }
    }

    private function copyWireguardConfig(array $config, string $stagingDir): void
    {
        $interface = (string) ($config['wireguard']['interface'] ?? 'wg0');
        $source = (string) ($config['wireguard']['config_path'] ?? "/etc/wireguard/{$interface}.conf");

        if (!is_readable($source)) {
            throw new RuntimeException('فایل WireGuard قابل خواندن نیست: ' . $source);
        }

        $target = $stagingDir . '/wg0.conf';

        if (@copy($source, $target) !== true) {
            throw new RuntimeException('کپی wg0.conf ناموفق بود.');
        }
    }

    private function dumpDatabase(array $config, string $targetPath): void
    {
        $db = $config['database'] ?? [];
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['username'] ?? '');
        $pass = (string) ($db['password'] ?? '');

        if ($name === '' || $user === '') {
            throw new RuntimeException('تنظیمات دیتابیس ناقص است.');
        }

        $cnfPath = tempnam(sys_get_temp_dir(), 'wg_dump_');

        if ($cnfPath === false) {
            throw new RuntimeException('ساخت فایل موقت mysqldump ناموفق بود.');
        }

        $cnfContent = implode(PHP_EOL, [
            '[client]',
            'user=' . $user,
            'password=' . $pass,
            'host=' . $host,
            'port=' . $port,
        ]) . PHP_EOL;

        file_put_contents($cnfPath, $cnfContent);
        @chmod($cnfPath, 0600);

        $command = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnfPath)
            . ' --single-transaction --routines --triggers '
            . escapeshellarg($name)
            . ' > ' . escapeshellarg($targetPath);

        try {
            Shell::run($command, true, false);
        } finally {
            @unlink($cnfPath);
        }

        if (!is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('خروجی mysqldump خالی است.');
        }
    }

    private function createArchive(string $stagingDir, string $archivePath): void
    {
        if (is_file($archivePath)) {
            @unlink($archivePath);
        }

        $command = 'tar -czf ' . escapeshellarg($archivePath)
            . ' -C ' . escapeshellarg($stagingDir) . ' .';

        Shell::run($command, true, false);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
