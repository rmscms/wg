<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config/config.php';
$backupManager = new WgPanel\BackupManager($rootDir . '/storage/backups');

try {
    if (!$backupManager->shouldRunAuto($config)) {
        exit(0);
    }

    $result = $backupManager->runConfigured($config);

    $writer = new WgPanel\ConfigWriter($configPath);
    $writer->update(['backup' => ['last_run_at' => time()]]);

    echo '[' . date('c') . '] Backup created: ' . $result['filename']
        . ' (' . WgPanel\BackupManager::formatBytes($result['size']) . ')' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] Backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
