#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

$interface = $config['wireguard']['interface'];
$result = WgPanel\Shell::run('wg show ' . escapeshellarg($interface) . ' transfer', false, true);

echo "=== WireGuard transfer ({$interface}) ===\n";
echo $result['output'] !== '' ? $result['output'] . "\n" : "(empty)\n";

$hsResult = WgPanel\Shell::run('wg show ' . escapeshellarg($interface) . ' latest-handshakes', false, true);
echo "=== Latest handshakes ===\n";
echo $hsResult['output'] !== '' ? $hsResult['output'] . "\n" : "(empty)\n";

echo "=== Database accounts ===\n";
$accounts = $db->query('SELECT id, name, is_active, volume_used_bytes, last_wg_rx_bytes, last_wg_tx_bytes, public_key FROM accounts ORDER BY id')->fetchAll();

foreach ($accounts as $account) {
    echo sprintf(
        "#%d %s | active=%d | volume=%d | last_rx=%s | last_tx=%s\n",
        (int) $account['id'],
        $account['name'],
        (int) $account['is_active'],
        (int) $account['volume_used_bytes'],
        $account['last_wg_rx_bytes'] === null ? 'NULL' : (string) $account['last_wg_rx_bytes'],
        $account['last_wg_tx_bytes'] === null ? 'NULL' : (string) $account['last_wg_tx_bytes']
    );
}

echo "\nRun sync with details:\n";
echo "  php " . __DIR__ . "/sync-traffic.php --verbose\n";
