<?php

declare(strict_types=1);

namespace WgPanel\Api;

use PDO;
use WgPanel\Helpers;
use WgPanel\QrGenerator;
use WgPanel\WireGuardManager;

final class Router
{
    /**
     * @param list<string> $segments
     */
    public static function dispatch(
        string $method,
        array $segments,
        array $config,
        WireGuardManager $wgManager,
        PDO $db,
    ): never {
        $root = $segments[0] ?? '';

        if ($root === 'health') {
            Http::ok([
                'status'  => 'ok',
                'time'    => date('c'),
                'version' => 'v1',
            ]);
        }

        if ($root === 'auth') {
            self::handleAuth($method, $segments, $config);
        }

        if ($root === 'subscribe') {
            self::handleSubscribe($method, $segments, $wgManager);
        }

        ApiAuth::requireAdmin($config);

        match ($root) {
            'accounts' => self::handleAccounts($method, $segments, $wgManager, $config),
            'traffic'  => self::handleTraffic($method, $segments, $wgManager),
            'limits'   => self::handleLimits($method, $segments, $wgManager),
            'server'   => self::handleServer($method, $segments, $wgManager, $config),
            'system'   => self::handleSystem($method, $segments, $wgManager, $config, $db),
            default    => Http::error('Not found.', 404),
        };
    }

    /** @param list<string> $segments */
    private static function handleAuth(string $method, array $segments, array $config): never
    {
        $action = $segments[1] ?? '';

        if ($action === 'login' && $method === 'POST') {
            $body     = Http::readJsonBody();
            $username = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $throttle = loginThrottle();

            if ($throttle->isBlocked()) {
                Http::error($throttle->lockMessage(), 429, 'too_many_attempts');
            }

            if (!verifyLogin($config, $username, $password)) {
                $throttle->recordFailure();

                if ($throttle->isBlocked()) {
                    Http::error($throttle->lockMessage(), 429, 'too_many_attempts');
                }

                Http::error($throttle->failureMessage(), 401, 'invalid_credentials');
            }

            $throttle->clear();
            $_SESSION['wg_admin'] = true;
            Http::ok([
                'authenticated' => true,
                'auth_type'     => 'session',
            ]);
        }

        if ($action === 'logout' && $method === 'POST') {
            unset($_SESSION['wg_admin']);
            Http::ok(['authenticated' => false]);
        }

        if ($action === 'me' && $method === 'GET') {
            Http::ok([
                'authenticated' => ApiAuth::isAuthenticated($config),
                'auth_type'     => ApiAuth::extractBearerToken() !== null ? 'bearer' : (isLoggedIn() ? 'session' : null),
                'api_enabled'   => !empty($config['api']['enabled']),
            ]);
        }

        Http::error('Not found.', 404);
    }

    /** @param list<string> $segments */
    private static function handleSubscribe(
        string $method,
        array $segments,
        WireGuardManager $wgManager,
    ): never {
        $token = $segments[1] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            Http::error('Invalid subscribe token.', 404);
        }

        $account = $wgManager->getAccountBySubscribeToken($token);
        if ($account === null) {
            Http::error('Subscribe token not found.', 404);
        }

        $subAction = $segments[2] ?? '';

        if ($subAction === '' && $method === 'GET') {
            Http::ok(array_merge(
                $wgManager->getSubscribeLiveData($account),
                [
                    'speed_limit_kbps' => (int) $account['speed_limit_kbps'],
                    'speed_human'      => Helpers::formatSpeed((int) $account['speed_limit_kbps']),
                ],
            ), 200, [
                'updated_at' => date('c'),
                'account'    => [
                    'name'       => $account['name'],
                    'ip_address' => $account['ip_address'],
                ],
            ]);
        }

        if ($subAction === 'status' && $method === 'GET') {
            Http::ok(array_merge(
                ['updated_at' => date('c')],
                $wgManager->getSubscribeLiveData($account),
            ));
        }

        Http::error('Not found.', 404);
    }

    /** @param list<string> $segments */
    private static function handleAccounts(
        string $method,
        array $segments,
        WireGuardManager $wgManager,
        array $config,
    ): never {
        if (($segments[1] ?? '') === 'online-status' && $method === 'GET') {
            $wgManager->processFirstConnectionExpiry();
            Http::ok([
                'updated_at'     => date('c'),
                'wg_ok'          => $wgManager->isWireGuardHandshakesAvailable(),
                'online_timeout' => $wgManager->getOnlineTimeoutSeconds(),
                'accounts'       => $wgManager->getAllOnlineStatuses(),
            ]);
        }

        if (!isset($segments[1]) || $segments[1] === '') {
            if ($method === 'GET') {
                self::listAccounts($wgManager, $config);
            }

            if ($method === 'POST') {
                $body    = Http::readJsonBody();
                $data    = AccountResource::parseInput($body, true);
                $account = $wgManager->createAccount($data);
                Http::ok(AccountResource::detail($account, $wgManager), 201);
            }

            Http::error('Method not allowed.', 405);
        }

        if (!ctype_digit($segments[1])) {
            Http::error('Invalid account id.', 422);
        }

        $id     = (int) $segments[1];
        $action = $segments[2] ?? '';

        if ($action === '') {
            if ($method === 'GET') {
                $account = self::requireAccount($wgManager, $id);
                Http::ok(AccountResource::detail($account, $wgManager));
            }

            if ($method === 'PATCH' || $method === 'PUT') {
                self::requireAccount($wgManager, $id);
                $body    = Http::readJsonBody();
                $data    = AccountResource::parseInput($body, false);
                $updated = $wgManager->updateAccount($id, $data);
                Http::ok(AccountResource::detail($updated, $wgManager));
            }

            if ($method === 'DELETE') {
                $wgManager->deleteAccount($id);
                Http::ok(['deleted' => true, 'id' => $id]);
            }

            Http::error('Method not allowed.', 405);
        }

        if ($action === 'toggle' && $method === 'POST') {
            $account = self::requireAccount($wgManager, $id);
            $updated = $wgManager->updateAccount($id, [
                'is_active' => (int) $account['is_active'] === 1 ? 0 : 1,
            ]);
            Http::ok(AccountResource::detail($updated, $wgManager));
        }

        if ($action === 'reset-traffic' && $method === 'POST') {
            $updated = $wgManager->resetTraffic($id);
            Http::ok(AccountResource::detail($updated, $wgManager));
        }

        if ($action === 'reset-expiry' && $method === 'POST') {
            $updated = $wgManager->resetExpiry($id);
            Http::ok(AccountResource::detail($updated, $wgManager));
        }

        if ($action === 'reset-both' && $method === 'POST') {
            $updated = $wgManager->resetTrafficAndExpiry($id);
            Http::ok(AccountResource::detail($updated, $wgManager));
        }

        if ($action === 'regenerate-subscribe-token' && $method === 'POST') {
            $updated = $wgManager->regenerateSubscribeToken($id);
            Http::ok(AccountResource::detail($updated, $wgManager));
        }

        if ($action === 'config' && $method === 'GET') {
            $account = self::requireAccount($wgManager, $id);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $account['name']) . '.conf"');
            echo $wgManager->buildClientConfig($account);
            exit;
        }

        if ($action === 'qr' && $method === 'GET') {
            self::sendAccountQr($id, $wgManager);
        }

        if ($action === 'online-status' && $method === 'GET') {
            $account = self::requireAccount($wgManager, $id);
            Http::ok($wgManager->getAccountOnlineStatus($account), 200, [
                'updated_at'     => date('c'),
                'account_id'     => $id,
                'wg_ok'          => $wgManager->isWireGuardHandshakesAvailable(),
                'online_timeout' => $wgManager->getOnlineTimeoutSeconds(),
            ]);
        }

        if ($action === 'traffic-logs' && $method === 'GET') {
            self::requireAccount($wgManager, $id);

            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(5, min(200, (int) ($_GET['per_page'] ?? 50)));
            $logs    = $wgManager->getTrafficLogs($id, $page, $perPage);

            Http::ok($logs['items'], 200, [
                'account_id'  => $id,
                'page'        => $logs['page'],
                'per_page'    => $logs['per_page'],
                'total'       => $logs['total'],
                'total_pages' => $logs['total_pages'],
            ]);
        }

        if ($action === 'transfer' && $method === 'GET') {
            $account = self::requireAccount($wgManager, $id);

            $stats = $wgManager->getPeerTransferStats($account);
            Http::ok([
                'account_id'  => $id,
                'public_key'  => (string) $account['public_key'],
                'wg_transfer' => $stats,
                'db_baseline' => [
                    'last_wg_rx_bytes'  => $account['last_wg_rx_bytes'] !== null ? (int) $account['last_wg_rx_bytes'] : null,
                    'last_wg_tx_bytes'  => $account['last_wg_tx_bytes'] !== null ? (int) $account['last_wg_tx_bytes'] : null,
                    'volume_used_bytes' => (int) $account['volume_used_bytes'],
                ],
            ], 200, [
                'updated_at' => date('c'),
            ]);
        }

        Http::error('Not found.', 404);
    }

    private static function listAccounts(WireGuardManager $wgManager, array $config): never
    {
        $hasPagination = isset($_GET['page']) || isset($_GET['per_page']) || isset($_GET['q']);
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = self::resolveApiPerPage(
            $config,
            isset($_GET['per_page']) ? (int) $_GET['per_page'] : null,
        );
        $search  = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
        if ($search === '') {
            $search = null;
        }

        if ($hasPagination) {
            $accounts = $wgManager->listAccountsPaginated($page, $perPage, $search);
            $total    = $wgManager->countAccounts($search);
            $online   = $wgManager->getOnlineStatusesForAccounts($accounts);
        } else {
            $accounts = $wgManager->listAccounts();
            $online   = $wgManager->getOnlineStatusesForAccounts($accounts);
            $total    = count($accounts);
            $page     = 1;
            $perPage  = $total > 0 ? $total : 20;
        }

        $items = array_map(
            static fn (array $account): array => AccountResource::summary(
                $account,
                $online[(string) $account['id']] ?? $online[(int) $account['id']] ?? null,
                $wgManager,
            ),
            $accounts,
        );

        $meta = [
            'count'       => count($items),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];

        if ($search !== null) {
            $meta['q'] = $search;
        }

        Http::ok($items, 200, $meta);
    }

    /** @return array<string, mixed> */
    private static function requireAccount(WireGuardManager $wgManager, int $id): array
    {
        $account = $wgManager->getAccount($id);
        if ($account === null) {
            Http::error('Account not found.', 404);
        }

        return $account;
    }

    private static function sendAccountQr(int $id, WireGuardManager $wgManager): never
    {
        $account = self::requireAccount($wgManager, $id);
        $format = strtolower(trim((string) ($_GET['format'] ?? 'png')));

        try {
            $png = QrGenerator::pngForAccount($wgManager, $account);
        } catch (\Throwable $e) {
            Http::error('QR generation failed: ' . $e->getMessage(), 500, 'qr_generation_failed');
        }

        if ($format === 'json') {
            Http::ok([
                'type'     => 'config',
                'format'   => 'png',
                'data_url' => 'data:image/png;base64,' . base64_encode($png),
            ]);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }

    /** @param list<string> $segments */
    private static function handleTraffic(string $method, array $segments, WireGuardManager $wgManager): never
    {
        $action = $segments[1] ?? '';

        if ($action === 'sync' && $method === 'POST') {
            $body    = Http::readJsonBody();
            $verbose = filter_var($body['verbose'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($verbose) {
                $report = $wgManager->syncTrafficDataReport(true);
                $wgManager->enforceLimits();
                Http::ok(array_merge($report, ['enforced' => true]));
            }

            $wgManager->syncTraffic();
            Http::ok([
                'synced'     => true,
                'enforced'   => true,
                'updated_at' => date('c'),
            ]);
        }

        if ($action === 'sync-data' && $method === 'POST') {
            $body    = Http::readJsonBody();
            $verbose = filter_var($body['verbose'] ?? true, FILTER_VALIDATE_BOOLEAN);
            Http::ok($wgManager->syncTrafficDataReport($verbose));
        }

        Http::error('Not found.', 404);
    }

    /** @param list<string> $segments */
    private static function handleLimits(string $method, array $segments, WireGuardManager $wgManager): never
    {
        $action = $segments[1] ?? '';

        if ($action === 'enforce' && $method === 'POST') {
            $wgManager->enforceLimits();
            Http::ok([
                'enforced'   => true,
                'updated_at' => date('c'),
            ]);
        }

        if ($action === 'process-first-connection' && $method === 'POST') {
            $wgManager->processFirstConnectionExpiry();
            Http::ok([
                'processed'  => true,
                'updated_at' => date('c'),
            ]);
        }

        Http::error('Not found.', 404);
    }

    /** @param list<string> $segments */
    private static function handleServer(
        string $method,
        array $segments,
        WireGuardManager $wgManager,
        array $config,
    ): never {
        if ($method !== 'GET' || ($segments[1] ?? '') !== '') {
            Http::error('Not found.', 404);
        }

        $wg = $config['wireguard'];
        Http::ok([
            'interface'           => $wg['interface'],
            'endpoint'            => $wg['endpoint'],
            'subnet'              => $wg['subnet'],
            'server_ip'           => $wg['server_ip'],
            'public_key'          => $wgManager->getServerPublicKey(),
            'subscribe_base_url'  => $config['app']['subscribe_base_url'] ?? null,
            'online_timeout'      => $wgManager->getOnlineTimeoutSeconds(),
            'wg_ok'               => $wgManager->isWireGuardHandshakesAvailable(),
        ]);
    }

    /** @param list<string> $segments */
    private static function handleSystem(
        string $method,
        array $segments,
        WireGuardManager $wgManager,
        array $config,
        PDO $db,
    ): never {
        $action = $segments[1] ?? '';

        if ($action === 'info' && $method === 'GET') {
            $accountCount     = (int) $db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
            $activeCount      = (int) $db->query('SELECT COUNT(*) FROM accounts WHERE is_active = 1')->fetchColumn();
            $trafficLogCount  = (int) $db->query('SELECT COUNT(*) FROM traffic_logs')->fetchColumn();

            Http::ok([
                'app' => [
                    'name'               => $config['app']['name'] ?? 'WireGuard Panel',
                    'timezone'           => $config['app']['timezone'] ?? 'UTC',
                    'subscribe_base_url' => $config['app']['subscribe_base_url'] ?? null,
                ],
                'api' => [
                    'enabled' => !empty($config['api']['enabled']),
                    'pagination' => [
                        'default_per_page' => max(1, (int) (($config['api']['pagination'] ?? [])['default_per_page'] ?? 20)),
                        'min_per_page' => max(1, (int) (($config['api']['pagination'] ?? [])['min_per_page'] ?? 1)),
                        'max_per_page' => (int) (($config['api']['pagination'] ?? [])['max_per_page'] ?? 0),
                    ],
                ],
                'wireguard' => [
                    'interface'           => $config['wireguard']['interface'] ?? 'wg0',
                    'endpoint'            => $config['wireguard']['endpoint'] ?? null,
                    'subnet'              => $config['wireguard']['subnet'] ?? null,
                    'online_timeout'      => $wgManager->getOnlineTimeoutSeconds(),
                    'handshake_timeout'   => (int) ($config['wireguard']['handshake_timeout'] ?? 180),
                    'persistent_keepalive'=> (int) ($config['wireguard']['persistent_keepalive'] ?? 25),
                ],
                'scripts' => [
                    'sync_traffic' => $config['scripts']['sync_traffic'] ?? null,
                    'check_limits' => $config['scripts']['check_limits'] ?? null,
                ],
                'stats' => [
                    'accounts_total'  => $accountCount,
                    'accounts_active' => $activeCount,
                    'traffic_logs_total' => $trafficLogCount,
                ],
                'wg_ok' => $wgManager->isWireGuardHandshakesAvailable(),
                'time'  => date('c'),
            ]);
        }

        if ($action === 'purge-peers' && $method === 'POST') {
            $wgManager->purgeInactivePeers();
            Http::ok([
                'purged'     => true,
                'updated_at' => date('c'),
            ]);
        }

        if ($action === 'sync-wireguard' && $method === 'POST') {
            $body = Http::readJsonBody();
            $dryRun = filter_var($body['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $result = $wgManager->syncWireguard($dryRun);
            Http::ok([
                'added'      => $result['added'],
                'removed'    => $result['removed'],
                'errors'     => $result['errors'],
                'dry_run'    => $result['dry_run'],
                'before'     => $result['before'],
                'after'      => $result['after'],
                'updated_at' => date('c'),
            ]);
        }

        Http::error('Not found.', 404);
    }

    private static function resolveApiPerPage(array $config, ?int $requested): int
    {
        $pagination = $config['api']['pagination'] ?? [];
        $default = max(1, (int) ($pagination['default_per_page'] ?? 20));
        $min = max(1, (int) ($pagination['min_per_page'] ?? 1));
        $max = (int) ($pagination['max_per_page'] ?? 0);

        if ($requested === null) {
            return $default;
        }

        $perPage = max($min, $requested);
        if ($max > 0) {
            $perPage = min($max, $perPage);
        }

        return $perPage;
    }
}
