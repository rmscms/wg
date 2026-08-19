#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

/** @param array<string, mixed> $analysis */
function countWireguardDiffIssues(array $analysis): int
{
    return count($analysis['missing_in_conf'])
        + count($analysis['missing_in_runtime'])
        + count($analysis['stale_in_conf'])
        + count($analysis['stale_in_runtime']);
}

/** @param array<string, mixed> $analysis */
function printWireguardDiffReport(string $title, array $analysis): void
{
    echo "\n=== {$title} ===\n";

    if (countWireguardDiffIssues($analysis) === 0) {
        echo "No differences found.\n";
        echo "Active accounts: {$analysis['active_count']}  conf peers: {$analysis['conf_peer_count']}  runtime peers: {$analysis['runtime_peer_count']}\n";
        return;
    }

    if ($analysis['missing_in_conf'] !== []) {
        echo 'Missing in wg0.conf (' . count($analysis['missing_in_conf']) . "):\n";
        printWireguardAccountLines($analysis['missing_in_conf']);
    }

    if ($analysis['missing_in_runtime'] !== []) {
        echo 'Missing in live WireGuard (' . count($analysis['missing_in_runtime']) . "):\n";
        printWireguardAccountLines($analysis['missing_in_runtime']);
    }

    if ($analysis['stale_in_conf'] !== []) {
        echo 'Stale in wg0.conf (' . count($analysis['stale_in_conf']) . "):\n";
        printWireguardStaleLines($analysis['stale_in_conf']);
    }

    if ($analysis['stale_in_runtime'] !== []) {
        echo 'Stale in live WireGuard (' . count($analysis['stale_in_runtime']) . "):\n";
        printWireguardStaleLines($analysis['stale_in_runtime']);
    }

    echo "Active accounts: {$analysis['active_count']}  conf peers: {$analysis['conf_peer_count']}  runtime peers: {$analysis['runtime_peer_count']}\n";
}

/** @param list<array<string, mixed>> $items */
function printWireguardAccountLines(array $items, int $limit = 15): void
{
    $shown = 0;
    foreach ($items as $item) {
        if ($shown >= $limit) {
            echo '  - ... and ' . (count($items) - $limit) . " more\n";
            break;
        }
        echo "  - #{$item['id']} {$item['name']} {$item['ip']} key=" . substr((string) $item['public_key'], 0, 12) . "…\n";
        $shown++;
    }
}

/** @param list<array<string, mixed>> $items */
function printWireguardStaleLines(array $items, int $limit = 15): void
{
    $shown = 0;
    foreach ($items as $item) {
        if ($shown >= $limit) {
            echo '  - ... and ' . (count($items) - $limit) . " more\n";
            break;
        }
        $label = $item['name'] !== null
            ? "# {$item['name']} {$item['ip']}"
            : 'orphan ' . substr((string) $item['public_key'], 0, 12) . '…';
        echo "  - {$label} reason: {$item['reason']}\n";
        $shown++;
    }
}

function formatElapsed(float $seconds): string
{
    if ($seconds < 1) {
        return sprintf('%.0f ms', $seconds * 1000);
    }

    if ($seconds < 60) {
        return sprintf('%.2f s', $seconds);
    }

    $minutes = (int) floor($seconds / 60);
    $remaining = $seconds - ($minutes * 60);

    return sprintf('%d m %.1f s', $minutes, $remaining);
}

$dryRun = in_array('--dry-run', $argv ?? [], true) || in_array('-n', $argv ?? [], true);
$startedAt = hrtime(true);

try {
    $interface = (string) ($config['wireguard']['interface'] ?? 'wg0');
    $confPath = (string) ($config['wireguard']['config_path'] ?? "/etc/wireguard/{$interface}.conf");

    echo "Interface: {$interface}\n";
    echo "Config: {$confPath}\n";
    if ($dryRun) {
        echo "Mode: dry-run (no changes)\n";
    }
    @ob_implicit_flush(true);
    @flush();

    $before = $wgManager->analyzeWireguardSync();
    printWireguardDiffReport('Diff before sync', $before);
    @flush();

    if ($dryRun) {
        $elapsed = (hrtime(true) - $startedAt) / 1e9;
        echo "\nDry-run complete. Diff issues: " . countWireguardDiffIssues($before) . "\n";
        echo 'Elapsed: ' . formatElapsed($elapsed) . "\n";
        exit(countWireguardDiffIssues($before) > 0 ? 1 : 0);
    }

    echo "\n=== Syncing WireGuard ===\n";
    echo "Applying live peers, then writing {$confPath} ...\n";
    @flush();
    $result = $wgManager->syncWireguard(false);

    foreach ($result['added'] as $line) {
        echo "  + added {$line}\n";
    }
    foreach ($result['removed'] as $line) {
        echo "  - removed {$line}\n";
    }
    foreach ($result['errors'] as $line) {
        echo "  ! error {$line}\n";
    }

    if ($result['added'] === [] && $result['removed'] === [] && $result['errors'] === []) {
        echo "  runtime already in sync\n";
    }

    printWireguardDiffReport('Diff after sync', $result['after']);

    $after = $result['after'];
    $persistErrors = [];
    foreach ($result['errors'] as $line) {
        if (str_contains($line, 'persist wg0.conf')) {
            $persistErrors[] = $line;
        }
    }
    $missingInConf = $after['missing_in_conf'] ?? [];

    echo "\n=== wg0.conf persist ===\n";
    echo "Before: {$before['conf_peer_count']} peers\n";
    echo "After:  {$after['conf_peer_count']} peers\n";

    $confSaved = $persistErrors === [] && $missingInConf === [];
    if ($persistErrors !== []) {
        echo "NOT SAVED.\n";
        foreach ($persistErrors as $line) {
            echo "  {$line}\n";
        }
    } elseif ($missingInConf !== []) {
        echo 'NOT SAVED: still missing ' . count($missingInConf) . " active peer(s) in conf.\n";
        printWireguardAccountLines($missingInConf);
    } else {
        echo "wg0.conf saved: {$after['conf_peer_count']} peers\n";
    }

    echo "\n=== Summary ===\n";
    echo 'Added: ' . count($result['added']) . '  Removed: ' . count($result['removed']) . '  Errors: ' . count($result['errors']) . "\n";
    echo 'Diff remaining: ' . countWireguardDiffIssues($after) . "\n";
    echo 'wg0.conf: ' . ($confSaved ? 'saved' : 'NOT saved') . "\n";

    $elapsed = (hrtime(true) - $startedAt) / 1e9;
    echo 'Elapsed: ' . formatElapsed($elapsed) . "\n";

    exit($result['errors'] !== [] || countWireguardDiffIssues($after) > 0 || !$confSaved ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
