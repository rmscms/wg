<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config/config.php';
$backupManager = new WgPanel\BackupManager(
    WgPanel\BackupManager::resolveStorageDir($config, $rootDir)
);

try {
    if (!$backupManager->shouldRunAuto($config)) {
        exit(0);
    }

    $result = $backupManager->runConfigured($config);

    $config = WgPanel\SettingsStore::update(
        $db,
        $config,
        ['backup' => ['last_run_at' => time()]],
        $configPath
    );

    echo '[' . date('c') . '] Backup created: ' . $result['filename']
        . ' (' . WgPanel\BackupManager::formatBytes($result['size']) . ')' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] Backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (empty($config['telegram']['send_auto_backup'])) {
    exit(0);
}

if (!WgPanel\TelegramBridge::isConfigured($config)) {
    fwrite(STDERR, '[' . date('c') . '] Telegram skipped: not configured' . PHP_EOL);
    exit(0);
}

try {
    $path = $backupManager->directory() . '/' . $result['filename'];
    $caption = $result['filename'] . ' (' . WgPanel\BackupManager::formatBytes((int) $result['size']) . ')';
    (new WgPanel\TelegramBridge($config))->sendBackup($path, $caption);
    echo '[' . date('c') . '] Backup sent to Telegram' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] Telegram send failed: ' . $e->getMessage() . PHP_EOL);
}

exit(0);
