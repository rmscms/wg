<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Configuration file not found.\n");
    exit(1);
}

$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies not installed. Run: composer install --no-dev -o\n");
    exit(1);
}

require_once $autoload;

$db = WgPanel\Database::connect($config);
$seededGroups = WgPanel\SettingsStore::ensureSeeded($db, $config);
$config = WgPanel\SettingsStore::overlay($db, $config);
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
$wgManager = new WgPanel\WireGuardManager($db, $config);

$upgradeNotes = WgPanel\Database::consumeUpgradeNotes();
if ($upgradeNotes !== [] || $seededGroups !== []) {
    $bits = $upgradeNotes;
    if ($seededGroups !== []) {
        $bits[] = 'settings seed (' . implode(', ', $seededGroups) . ')';
    }
    echo '[' . date('c') . '] Database upgrade: ' . implode(', ', $bits) . PHP_EOL;
}
