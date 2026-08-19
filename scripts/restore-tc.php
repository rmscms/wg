<?php

/**
 * Restore panel state after wg0 comes up:
 *
 *   Phase 1 — sync WireGuard (DB ↔ wg0.conf ↔ runtime)
 *   Phase 2 — restore tc speed limits only for peers with tc diff
 *
 * PostUp example:
 *   PostUp = php /opt/wg-panel/scripts/restore-tc.php
 *
 * Manual:
 *   sudo php /opt/wg-panel/scripts/restore-tc.php
 *
 * Flags:
 *   --skip-wg-sync   skip phase 1
 *   --all            restore tc for all eligible accounts (not only diff)
 *   --post-up        for wg0.conf PostUp: skip wg sync, never fail wg-quick (exit 0)
 */

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

/** @return array{class_handle: string, filter_prio_dst: int, filter_prio_src: int} */
function tcMetaForIp(string $ip): array
{
    static $cache = [];

    if (isset($cache[$ip])) {
        return $cache[$ip];
    }

    $cmd = sprintf("printf %s | cksum | cut -d' ' -f1", escapeshellarg($ip));
    exec($cmd, $lines, $exitCode);
    $ipHash = $exitCode === 0 ? (int) trim($lines[0] ?? '0') : 0;
    $classMinor = ($ipHash % 65000) + 2;
    $filterPrioDst = (($classMinor % 32767) * 2) + 1;

    return $cache[$ip] = [
        'class_handle' => '1:' . dechex($classMinor),
        'filter_prio_dst' => $filterPrioDst,
        'filter_prio_src' => $filterPrioDst + 1,
    ];
}

/** @param array<string, mixed> $account */
function restoreAccountTc(
    array $account,
    string $applyScript,
    string $interface,
): array {
    $cmd = sprintf(
        'sudo %s %s %s %s %s 2>&1',
        escapeshellarg($applyScript),
        escapeshellarg($interface),
        escapeshellarg((string) $account['public_key']),
        escapeshellarg($account['ip_address'] . '/32'),
        escapeshellarg((string) $account['speed_limit_kbps'])
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    return [
        'ok' => $exitCode === 0,
        'output' => implode(' ', $output),
    ];
}

/** @return array<string, int> class handle => rate kbps */
function parseTcClasses(string $interface): array
{
    $cmd = sprintf('tc class show dev %s 2>/dev/null', escapeshellarg($interface));
    exec($cmd, $lines, $exitCode);

    if ($exitCode !== 0) {
        return [];
    }

    $classes = [];

    foreach ($lines as $line) {
        if (!preg_match('/class htb (1:[0-9a-f]+)/i', $line, $match)) {
            continue;
        }

        $handle = strtolower($match[1]);
        if ($handle === '1:999') {
            continue;
        }

        if (!preg_match('/rate (\d+(?:\.\d+)?)(Kbit|Mbit|Gbit)/i', $line, $rateMatch)) {
            continue;
        }

        $rate = (float) $rateMatch[1];
        $unit = strtolower($rateMatch[2]);
        $kbps = match ($unit) {
            'mbit' => (int) round($rate * 1000),
            'gbit' => (int) round($rate * 1000000),
            default => (int) round($rate),
        };

        $classes[$handle] = $kbps;
    }

    return $classes;
}

function tcHexToIp(string $hex): ?string
{
    $hex = strtolower(preg_replace('/^0x/', '', $hex));
    if ($hex === '' || !preg_match('/^[0-9a-f]+$/', $hex)) {
        return null;
    }

    $hex = str_pad($hex, 8, '0', STR_PAD_LEFT);
    if (strlen($hex) > 8) {
        return null;
    }

    $packed = pack('H*', $hex);
    $ip = @inet_ntop($packed);

    return is_string($ip) ? $ip : null;
}

/** @return list<string> */
function readTcFilterLines(string $interface): array
{
    $commands = [
        sprintf('tc -p filter show dev %s parent 1: 2>/dev/null', escapeshellarg($interface)),
        sprintf('tc filter show dev %s parent 1: 2>/dev/null', escapeshellarg($interface)),
    ];

    foreach ($commands as $cmd) {
        $lines = [];
        exec($cmd, $lines, $exitCode);
        if ($exitCode === 0 && $lines !== []) {
            return $lines;
        }
    }

    return [];
}

/** @return array<string, string> ip => class handle */
function parseTcFilterIps(string $interface): array
{
    $lines = readTcFilterLines($interface);
    if ($lines === []) {
        return [];
    }

    $ips = [];
    $currentFlowId = null;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);

        if ($line === '') {
            continue;
        }

        if (preg_match('/flowid (1:[0-9a-f]+)/i', $line, $flowMatch)) {
            $currentFlowId = strtolower($flowMatch[1]);
        }

        if (preg_match('/match ip (?:dst|src) ([0-9.]+)(?:\/\d+)?/i', $line, $ipMatch) && $currentFlowId !== null) {
            $ips[$ipMatch[1]] = $currentFlowId;
            continue;
        }

        if (preg_match('/match ([0-9a-fx]+)\/([0-9a-fx]+) at (12|16)/i', $line, $hexMatch) && $currentFlowId !== null) {
            $mask = strtolower(ltrim($hexMatch[2], '0x'));
            if (!in_array($mask, ['ffffffff', '4294967295', 'ffffff00'], true)) {
                continue;
            }

            $ip = tcHexToIp($hexMatch[1]);
            if ($ip !== null) {
                $ips[$ip] = $currentFlowId;
            }
        }
    }

    return $ips;
}

function accountShouldRestore(array $account): bool
{
    if ((int) $account['is_active'] !== 1) {
        return false;
    }

    if ((int) $account['speed_limit_kbps'] <= 0) {
        return false;
    }

    if (!empty($account['expires_at']) && strtotime((string) $account['expires_at']) <= time()) {
        return false;
    }

    if (
        (int) $account['volume_limit_bytes'] > 0
        && (int) $account['volume_used_bytes'] >= (int) $account['volume_limit_bytes']
    ) {
        return false;
    }

    return true;
}

function accountSkipReason(array $account): ?string
{
    if ((int) $account['speed_limit_kbps'] <= 0) {
        return 'no speed limit';
    }

    if ((int) $account['is_active'] !== 1) {
        return 'inactive';
    }

    if (!empty($account['expires_at']) && strtotime((string) $account['expires_at']) <= time()) {
        return 'expired';
    }

    if (
        (int) $account['volume_limit_bytes'] > 0
        && (int) $account['volume_used_bytes'] >= (int) $account['volume_limit_bytes']
    ) {
        return 'volume exceeded';
    }

    return null;
}

/** @param list<array<string, mixed>> $restoreAccounts */
function buildExpectedMap(array $restoreAccounts): array
{
    $expected = [];

    foreach ($restoreAccounts as $account) {
        $ip = (string) $account['ip_address'];
        $meta = tcMetaForIp($ip);
        $expected[$meta['class_handle']] = [
            'id' => (int) $account['id'],
            'name' => (string) $account['name'],
            'ip' => $ip,
            'rate_kbps' => (int) $account['speed_limit_kbps'],
        ];
    }

    return $expected;
}

/** @param array<string, array{id:int,name:string,ip:string,rate_kbps:int}> $expectedByHandle */
function analyzeTcDiff(string $interface, array $expectedByHandle): array
{
    $classes = parseTcClasses($interface);
    $filterIps = parseTcFilterIps($interface);

    $missing = [];
    $wrongRate = [];
    $missingFilter = [];

    foreach ($expectedByHandle as $handle => $item) {
        if (!isset($classes[$handle])) {
            $missing[] = $item + ['issue' => 'missing class'];
            continue;
        }

        if ($classes[$handle] !== $item['rate_kbps']) {
            $wrongRate[] = $item + [
                'found_kbps' => $classes[$handle],
                'issue' => 'wrong rate',
            ];
        }

        $filterHandle = $filterIps[$item['ip']] ?? null;
        if ($filterHandle === null) {
            $missingFilter[] = $item + ['issue' => 'missing filter'];
        } elseif ($filterHandle !== $handle) {
            $missingFilter[] = $item + [
                'issue' => 'filter mismatch',
                'found_handle' => $filterHandle,
            ];
        }
    }

    $expectedHandles = array_fill_keys(array_keys($expectedByHandle), true);
    $expectedIps = [];
    foreach ($expectedByHandle as $item) {
        $expectedIps[$item['ip']] = true;
    }

    $orphanClasses = [];
    foreach ($classes as $handle => $rateKbps) {
        if (!isset($expectedHandles[$handle])) {
            $orphanClasses[] = [
                'class_handle' => $handle,
                'rate_kbps' => $rateKbps,
                'issue' => 'orphan class',
            ];
        }
    }

    $staleFilters = [];
    foreach ($filterIps as $ip => $handle) {
        if (!isset($expectedIps[$ip])) {
            $staleFilters[] = [
                'ip' => $ip,
                'class_handle' => $handle,
                'rate_kbps' => $classes[$handle] ?? null,
                'issue' => 'stale filter',
            ];
        }
    }

    $filtersVerified = $filterIps !== [] || $classes === [] || $expectedByHandle === [];

    return [
        'missing' => $missing,
        'wrong_rate' => $wrongRate,
        'missing_filter' => $filtersVerified ? $missingFilter : [],
        'orphan_classes' => $orphanClasses,
        'stale_filters' => $filtersVerified ? $staleFilters : [],
        'class_count' => count($classes),
        'filter_count' => count($filterIps),
        'filters_verified' => $filtersVerified,
        'unverified_filter_checks' => !$filtersVerified ? count($expectedByHandle) : 0,
    ];
}

/** @param array<string, mixed> $diff */
function countDiffIssues(array $diff, bool $criticalOnly = false): int
{
    $total = count($diff['missing'])
        + count($diff['wrong_rate'])
        + count($diff['orphan_classes']);

    if (!$criticalOnly) {
        $total += count($diff['missing_filter']) + count($diff['stale_filters']);
    }

    return $total;
}

/** @param array<string, mixed> $diff */
function formatDiffBreakdown(array $diff): string
{
    $parts = [];

    if ($diff['missing'] !== []) {
        $parts[] = 'missing class ' . count($diff['missing']);
    }
    if ($diff['wrong_rate'] !== []) {
        $parts[] = 'wrong rate ' . count($diff['wrong_rate']);
    }
    if ($diff['missing_filter'] !== []) {
        $parts[] = 'filter ' . count($diff['missing_filter']);
    }
    if ($diff['orphan_classes'] !== []) {
        $parts[] = 'orphan class ' . count($diff['orphan_classes']);
    }
    if ($diff['stale_filters'] !== []) {
        $parts[] = 'stale filter ' . count($diff['stale_filters']);
    }
    if (($diff['unverified_filter_checks'] ?? 0) > 0) {
        $parts[] = 'filter check unverified ' . $diff['unverified_filter_checks'];
    }

    return $parts === [] ? 'none' : implode(', ', $parts);
}

/** @param array<string, mixed> $diff */
function printTcDiffReport(string $title, array $diff): void
{
    echo "\n=== {$title} ===\n";

    if (countDiffIssues($diff) === 0) {
        echo "No tc differences found.\n";
        if (($diff['unverified_filter_checks'] ?? 0) > 0) {
            echo 'Note: tc filters could not be parsed; class/rate checks passed for '
                . $diff['unverified_filter_checks'] . " accounts.\n";
        }
        echo "tc classes (limited): {$diff['class_count']}  filter IPs: {$diff['filter_count']}\n";
        return;
    }

    if ($diff['missing'] !== []) {
        echo 'Missing tc class (' . count($diff['missing']) . "):\n";
        foreach ($diff['missing'] as $item) {
            echo "  - #{$item['id']} {$item['name']} {$item['ip']} expected {$item['rate_kbps']} kbps\n";
        }
    }

    if ($diff['wrong_rate'] !== []) {
        echo 'Wrong rate (' . count($diff['wrong_rate']) . "):\n";
        foreach ($diff['wrong_rate'] as $item) {
            echo "  - #{$item['id']} {$item['name']} {$item['ip']} expected {$item['rate_kbps']} kbps, found {$item['found_kbps']} kbps\n";
        }
    }

    if ($diff['missing_filter'] !== []) {
        echo 'Filter mismatch (' . count($diff['missing_filter']) . "):\n";
        $shown = 0;
        foreach ($diff['missing_filter'] as $item) {
            if ($shown >= 10) {
                echo '  - ... and ' . (count($diff['missing_filter']) - 10) . " more\n";
                break;
            }
            $handle = tcMetaForIp($item['ip'])['class_handle'];
            $extra = isset($item['found_handle'])
                ? ", found filter flowid {$item['found_handle']}"
                : ', no filter for IP';
            echo "  - #{$item['id']} {$item['name']} {$item['ip']} expected flowid {$handle}{$extra}\n";
            $shown++;
        }
    }

    if (($diff['unverified_filter_checks'] ?? 0) > 0) {
        echo 'Filter check skipped (' . $diff['unverified_filter_checks'] . "): tc filter output not parsed\n";
    }

    if ($diff['orphan_classes'] !== []) {
        echo 'Orphan tc classes (' . count($diff['orphan_classes']) . "):\n";
        foreach ($diff['orphan_classes'] as $item) {
            echo "  - {$item['class_handle']} rate {$item['rate_kbps']} kbps (no matching restore target)\n";
        }
    }

    if ($diff['stale_filters'] !== []) {
        echo 'Stale tc filters (' . count($diff['stale_filters']) . "):\n";
        foreach ($diff['stale_filters'] as $item) {
            $rate = $item['rate_kbps'] !== null ? " rate {$item['rate_kbps']} kbps" : '';
            echo "  - {$item['ip']} flowid {$item['class_handle']}{$rate}\n";
        }
    }

    echo "tc classes (limited): {$diff['class_count']}  filter IPs: {$diff['filter_count']}\n";
}

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
        echo "No WireGuard differences found.\n";
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
function printWireguardAccountLines(array $items, int $limit = 10): void
{
    $shown = 0;
    foreach ($items as $item) {
        if ($shown >= $limit) {
            echo '  - ... and ' . (count($items) - $limit) . " more\n";
            break;
        }
        echo "  - #{$item['id']} {$item['name']} {$item['ip']}\n";
        $shown++;
    }
}

/** @param list<array<string, mixed>> $items */
function printWireguardStaleLines(array $items, int $limit = 10): void
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

/**
 * @param array<string, mixed> $diff
 * @param list<array<string, mixed>> $restoreAccounts
 * @return list<array<string, mixed>>
 */
function collectAccountsNeedingTcFix(array $diff, array $restoreAccounts): array
{
    $ids = [];

    foreach (['missing', 'wrong_rate', 'missing_filter'] as $bucket) {
        foreach ($diff[$bucket] as $item) {
            $ids[(int) $item['id']] = true;
        }
    }

    $byId = [];
    foreach ($restoreAccounts as $account) {
        $byId[(int) $account['id']] = $account;
    }

    $accounts = [];
    foreach (array_keys($ids) as $id) {
        if (isset($byId[$id])) {
            $accounts[] = $byId[$id];
        }
    }

    usort($accounts, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

    return $accounts;
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

function wireguardInterfaceIsUp(string $interface): bool
{
    exec('ip link show ' . escapeshellarg($interface) . ' 2>/dev/null', $output, $exitCode);

    return $exitCode === 0;
}

$postUpMode = in_array('--post-up', $argv ?? [], true);
$skipWgSync = $postUpMode || in_array('--skip-wg-sync', $argv ?? [], true);
$restoreAll = in_array('--all', $argv ?? [], true);

$applyScript = (string) ($config['scripts']['apply_peer'] ?? '/opt/wg-panel/scripts/apply-peer.sh');
$interface = (string) ($config['wireguard']['interface'] ?? 'wg0');
$confPath = (string) ($config['wireguard']['config_path'] ?? "/etc/wireguard/{$interface}.conf");

if (!is_file($applyScript)) {
    fwrite(STDERR, "apply-peer script not found: {$applyScript}\n");
    exit(1);
}

$startedAt = hrtime(true);
$wgSyncErrors = [];

echo "Interface: {$interface}\n";
echo "Config: {$confPath}\n";
if ($postUpMode) {
    echo "Mode: post-up (wg sync skipped, exit 0 for wg-quick)\n";
}

if (!wireguardInterfaceIsUp($interface)) {
    fwrite(STDERR, "WireGuard interface {$interface} is not up. Start wg-quick first.\n");
    exit($postUpMode ? 0 : 1);
}

if (!$skipWgSync) {
    echo "\n=== Phase 1: WireGuard sync ===\n";
    $wgBefore = $wgManager->analyzeWireguardSync();
    printWireguardDiffReport('WireGuard diff before sync', $wgBefore);

    $wgResult = $wgManager->syncWireguard(false);
    foreach ($wgResult['added'] as $line) {
        echo "  + added {$line}\n";
    }
    foreach ($wgResult['removed'] as $line) {
        echo "  - removed {$line}\n";
    }
    foreach ($wgResult['errors'] as $line) {
        echo "  ! error {$line}\n";
        $wgSyncErrors[] = $line;
    }
    if ($wgResult['added'] === [] && $wgResult['removed'] === [] && $wgResult['errors'] === []) {
        echo "  nothing to change\n";
    }

    printWireguardDiffReport('WireGuard diff after sync', $wgResult['after']);
} else {
    echo "\nPhase 1 skipped (--skip-wg-sync)\n";
}

echo "\n=== Phase 2: TC restore ===\n";

$allLimitedStmt = $db->query(
    "SELECT id, name, public_key, ip_address, speed_limit_kbps, is_active,
            expires_at, volume_limit_bytes, volume_used_bytes
       FROM accounts
      WHERE speed_limit_kbps > 0
      ORDER BY id ASC"
);
$allLimitedAccounts = $allLimitedStmt->fetchAll(PDO::FETCH_ASSOC);

$restoreAccounts = array_values(array_filter(
    $allLimitedAccounts,
    static fn (array $account): bool => accountShouldRestore($account),
));

$skippedAccounts = [];
foreach ($allLimitedAccounts as $account) {
    $reason = accountSkipReason($account);
    if ($reason !== null && !accountShouldRestore($account)) {
        $skippedAccounts[] = $account + ['skip_reason' => $reason];
    }
}

$expectedByHandle = buildExpectedMap($restoreAccounts);
$beforeDiff = analyzeTcDiff($interface, $expectedByHandle);

echo 'Eligible accounts with speed limit: ' . count($restoreAccounts) . "\n";
printTcDiffReport('TC diff before restore', $beforeDiff);

if ($skippedAccounts !== []) {
    echo "\nSkipped accounts (" . count($skippedAccounts) . "):\n";
    foreach ($skippedAccounts as $account) {
        echo "  - #{$account['id']} {$account['name']} {$account['ip_address']} "
            . "({$account['speed_limit_kbps']} kbps) reason: {$account['skip_reason']}\n";
    }
}

if ($restoreAccounts === []) {
    echo "\nNo accounts eligible for tc restore.\n";
    $elapsed = (hrtime(true) - $startedAt) / 1e9;
    echo 'Elapsed: ' . formatElapsed($elapsed) . "\n";
    exit(countDiffIssues($beforeDiff) > 0 || $wgSyncErrors !== [] ? 1 : 0);
}

$accountsToFix = $restoreAll ? $restoreAccounts : collectAccountsNeedingTcFix($beforeDiff, $restoreAccounts);

if ($restoreAll) {
    echo "\nMode: restore tc for ALL eligible accounts (--all)\n";
} else {
    echo "\nMode: restore tc only for accounts with diff (" . count($accountsToFix) . ' / ' . count($restoreAccounts) . ")\n";
}

if ($accountsToFix === []) {
    echo "No tc differences to fix.\n";
    $afterDiff = $beforeDiff;
    $ok = 0;
    $failed = 0;
    $failedAccounts = [];
} else {
    echo "\nApplying tc rules...\n";

    $ok = 0;
    $failed = 0;
    $failedAccounts = [];

    foreach ($accountsToFix as $account) {
        $result = restoreAccountTc($account, $applyScript, $interface);

        if ($result['ok']) {
            echo "  ✓ #{$account['id']} {$account['name']} ({$account['ip_address']}, {$account['speed_limit_kbps']} kbps)\n";
            $ok++;
        } else {
            echo "  ✗ #{$account['id']} {$account['name']}: {$result['output']}\n";
            $failed++;
            $failedAccounts[] = [
                'id' => (int) $account['id'],
                'name' => (string) $account['name'],
                'ip' => (string) $account['ip_address'],
                'rate_kbps' => (int) $account['speed_limit_kbps'],
                'error' => $result['output'],
            ];
        }
    }

    $afterDiff = analyzeTcDiff($interface, $expectedByHandle);
}

printTcDiffReport('TC diff after restore', $afterDiff);

$fixedMissing = count($beforeDiff['missing']) - count($afterDiff['missing']);
$fixedWrongRate = count($beforeDiff['wrong_rate']) - count($afterDiff['wrong_rate']);
$fixedFilters = count($beforeDiff['missing_filter']) - count($afterDiff['missing_filter']);

echo "\n=== Summary ===\n";
if (!$skipWgSync) {
    echo 'WireGuard sync errors: ' . count($wgSyncErrors) . "\n";
}
echo "TC applied: {$ok}  TC failed: {$failed}  TC skipped (already OK): " . (count($restoreAccounts) - count($accountsToFix)) . "\n";
echo "TC diff fixed: missing {$fixedMissing}, wrong rate {$fixedWrongRate}, filter {$fixedFilters}\n";
echo 'TC diff remaining (all): ' . countDiffIssues($afterDiff) . ' [' . formatDiffBreakdown($afterDiff) . "]\n";
echo 'TC diff remaining (critical): ' . countDiffIssues($afterDiff, true) . "\n";

if ($failedAccounts !== []) {
    echo "Failed tc accounts:\n";
    foreach ($failedAccounts as $item) {
        echo "  - #{$item['id']} {$item['name']} {$item['ip']}: {$item['error']}\n";
    }
}

$elapsed = (hrtime(true) - $startedAt) / 1e9;
echo 'Elapsed: ' . formatElapsed($elapsed) . "\n";

$wouldFail = $wgSyncErrors !== []
    || $failed > 0
    || countDiffIssues($afterDiff, true) > 0
    || count($afterDiff['missing_filter']) > 0;

if ($postUpMode) {
    if ($wouldFail) {
        echo "Post-up mode: issues remain but exiting 0 so wg-quick is not failed.\n";
    }
    exit(0);
}

exit($wouldFail ? 1 : 0);
