<?php

declare(strict_types=1);

namespace WgPanel;

use PDO;
use RuntimeException;

final class WireGuardManager
{
    /** @var array<string, int>|null */
    private ?array $latestHandshakesCache = null;

    private ?bool $latestHandshakesOk = null;

    private ?string $serverPublicKeyCache = null;

    /** @var array<string, array{rx_bytes: int, tx_bytes: int, total_bytes: int}>|null */
    private ?array $wgTransferMapCache = null;

    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
    ) {
    }

    public function generateKeyPair(): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP extension sodium is required for key generation.');
        }

        $privateKeyBinary = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $privateKeyBinary = $this->clampPrivateKey($privateKeyBinary);
        $publicKeyBinary = sodium_crypto_scalarmult_base($privateKeyBinary);

        return [
            'private_key' => base64_encode($privateKeyBinary),
            'public_key' => base64_encode($publicKeyBinary),
        ];
    }

    private function clampPrivateKey(string $privateKey): string
    {
        $privateKey[0] = chr(ord($privateKey[0]) & 248);
        $privateKey[31] = chr((ord($privateKey[31]) & 127) | 64);

        return $privateKey;
    }

    public function allocateIp(): string
    {
        $subnet = SubnetHelper::fromConfig($this->config['wireguard']);

        $stmt = $this->db->query('SELECT ip_address FROM accounts');
        $usedIps = array_column($stmt->fetchAll(), 'ip_address');

        return $subnet->allocateNext($usedIps);
    }

    public function createAccount(array $data): array
    {
        $keys = $this->generateKeyPair();
        $ip = $this->allocateIp();

        $speedLimit = max(0, (int) ($data['speed_limit_kbps'] ?? 0));
        $volumeLimit = max(0, (int) ($data['volume_limit_bytes'] ?? 0));
        $expiry = $this->resolveExpiryFields($data);
        $subscribeToken = $this->generateSubscribeToken();

        $stmt = $this->db->prepare(
            'INSERT INTO accounts (
                name, public_key, private_key, ip_address, speed_limit_kbps, volume_limit_bytes,
                expires_at, expiry_mode, expiry_duration_days, first_connected_at, subscribe_token
             )
             VALUES (
                :name, :public_key, :private_key, :ip_address, :speed_limit_kbps, :volume_limit_bytes,
                :expires_at, :expiry_mode, :expiry_duration_days, :first_connected_at, :subscribe_token
             )'
        );

        $stmt->execute([
            'name' => trim((string) $data['name']),
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key'],
            'ip_address' => $ip,
            'speed_limit_kbps' => $speedLimit,
            'volume_limit_bytes' => $volumeLimit,
            'expires_at' => $expiry['expires_at'],
            'expiry_mode' => $expiry['expiry_mode'],
            'expiry_duration_days' => $expiry['expiry_duration_days'],
            'first_connected_at' => $expiry['first_connected_at'],
            'subscribe_token' => $subscribeToken,
        ]);

        $accountId = (int) $this->db->lastInsertId();
        $account = $this->getAccount($accountId);

        if ($account === null) {
            throw new RuntimeException('Failed to create account.');
        }

        $this->applyPeer($account);

        return $account;
    }

    public function updateAccount(int $id, array $data): array
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $speedLimit = max(0, (int) ($data['speed_limit_kbps'] ?? $account['speed_limit_kbps']));
        $volumeLimit = max(0, (int) ($data['volume_limit_bytes'] ?? $account['volume_limit_bytes']));
        $expiry = $this->resolveExpiryFields($data, $account);
        $isActive = array_key_exists('is_active', $data)
            ? ((int) (bool) $data['is_active'])
            : (int) $account['is_active'];

        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET name = :name,
                 speed_limit_kbps = :speed_limit_kbps,
                 volume_limit_bytes = :volume_limit_bytes,
                 expires_at = :expires_at,
                 expiry_mode = :expiry_mode,
                 expiry_duration_days = :expiry_duration_days,
                 first_connected_at = :first_connected_at,
                 is_active = :is_active,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'name' => trim((string) ($data['name'] ?? $account['name'])),
            'speed_limit_kbps' => $speedLimit,
            'volume_limit_bytes' => $volumeLimit,
            'expires_at' => $expiry['expires_at'],
            'expiry_mode' => $expiry['expiry_mode'],
            'expiry_duration_days' => $expiry['expiry_duration_days'],
            'first_connected_at' => $expiry['first_connected_at'],
            'is_active' => $isActive,
        ]);

        $updated = $this->getAccount($id);

        if ($updated === null) {
            throw new RuntimeException('Failed to update account.');
        }

        if (
            (int) $updated['is_active'] === 1
            && !$this->isExpired($updated)
            && !$this->isVolumeExceeded($updated)
        ) {
            $this->clearTrafficBaseline($id);
            $this->applyPeer($updated);
        } else {
            $this->clearTrafficBaseline($id);
            $this->removePeer($updated);
        }

        return $updated;
    }

    public function deleteAccount(int $id): void
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $this->removePeer($account, true);

        $stmt = $this->db->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function resetTraffic(int $id): array
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET volume_used_bytes = 0,
                 last_wg_rx_bytes = NULL,
                 last_wg_tx_bytes = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $this->finalizeReset($id);
    }

    public function resetExpiry(int $id): array
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $this->resetExpiryFields($id, $account);

        return $this->finalizeReset($id);
    }

    public function resetTrafficAndExpiry(int $id): array
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $awaitReconnect = $this->shouldAwaitReconnectAfterExpiryReset($account);

        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET volume_used_bytes = 0,
                 last_wg_rx_bytes = NULL,
                 last_wg_tx_bytes = NULL,
                 first_connected_at = NULL,
                 expires_at = NULL,
                 expiry_await_reconnect = :expiry_await_reconnect,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'expiry_await_reconnect' => $awaitReconnect,
        ]);

        return $this->finalizeReset($id);
    }

    private function resetExpiryFields(int $id, ?array $account = null): void
    {
        $account ??= $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $awaitReconnect = $this->shouldAwaitReconnectAfterExpiryReset($account);

        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET first_connected_at = NULL,
                 expires_at = NULL,
                 expiry_await_reconnect = :expiry_await_reconnect,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'expiry_await_reconnect' => $awaitReconnect,
        ]);
    }

    private function shouldAwaitReconnectAfterExpiryReset(array $account): int
    {
        if (!Helpers::isFirstConnectExpiry($account)) {
            return 0;
        }

        $handshakes = $this->getLatestHandshakes();
        $handshakeAt = $this->lookupHandshake(trim((string) $account['public_key']), $handshakes);

        return $this->isPeerConnected($handshakeAt, $this->onlineTimeoutSeconds()) ? 1 : 0;
    }

    private function clearExpiryAwaitReconnect(int $id): void
    {
        $this->db->prepare(
            'UPDATE accounts SET expiry_await_reconnect = 0, updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $id]);
    }

    private function finalizeReset(int $id): array
    {
        $updated = $this->getAccount($id);

        if ($updated === null) {
            throw new RuntimeException('Failed to reset account.');
        }

        if (!$this->isExpired($updated) && !$this->isVolumeExceeded($updated)) {
            $updated = $this->updateAccount($id, ['is_active' => 1]);
            $this->reconnectPeer($updated);
            $updated = $this->getAccount($id) ?? $updated;
        }

        return $updated;
    }

    /** Remove and re-apply WireGuard peer so the client reconnects after a reset. */
    private function reconnectPeer(array $account): void
    {
        if ((int) $account['is_active'] !== 1) {
            return;
        }

        if ($this->isExpired($account) || $this->isVolumeExceeded($account)) {
            return;
        }

        $accountId = (int) $account['id'];
        $this->removePeer($account);
        $this->clearTrafficBaseline($accountId);

        $fresh = $this->getAccount($accountId);

        if ($fresh === null) {
            return;
        }

        $this->applyPeer($fresh);
        $this->latestHandshakesCache = null;
        $this->latestHandshakesOk    = null;
    }

    public function getAccountBySubscribeToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM accounts WHERE subscribe_token = :token');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getAccountByShortToken(string $short): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9]{6,16}$/', $short)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM accounts WHERE sub_short = :short');
        $stmt->execute(['short' => $short]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function ensureShortToken(int $accountId): string
    {
        $account = $this->getAccount($accountId);

        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }

        if (!empty($account['sub_short'])) {
            return (string) $account['sub_short'];
        }

        $short = $this->generateShortToken();
        $stmt = $this->db->prepare('UPDATE accounts SET sub_short = :short WHERE id = :id');
        $stmt->execute(['short' => $short, 'id' => $accountId]);

        return $short;
    }

    private function generateShortToken(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;

        // Retry until unique (collision extremely unlikely for 12 chars)
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = '';
            for ($i = 0; $i < 12; $i++) {
                $token .= $chars[random_int(0, $max)];
            }

            $stmt = $this->db->prepare('SELECT id FROM accounts WHERE sub_short = :t');
            $stmt->execute(['t' => $token]);
            if ($stmt->fetch() === false) {
                return $token;
            }
        }

        throw new \RuntimeException('Could not generate unique short token.');
    }

    /** Web panel where the user sees volume, expiry, and config QR. Short /s/ URL when available. */
    public function buildSubscribePanelUrl(array $account): string
    {
        $short = (string) ($account['sub_short'] ?? '');

        if ($short !== '') {
            return $this->subscribeBaseUrl() . '/s/' . $short;
        }

        // Lazy-generate short token so future calls will use short URL
        try {
            $short = $this->ensureShortToken((int) $account['id']);
            return $this->subscribeBaseUrl() . '/s/' . $short;
        } catch (\Throwable) {
            // Fallback to long token URL if DB column not yet added
            $token = (string) ($account['subscribe_token'] ?? '');
            if ($token === '') {
                $token = $this->ensureSubscribeToken((int) $account['id']);
            }
            return $this->subscribeBaseUrl() . '/subscribe.php?token=' . urlencode($token);
        }
    }

    public function ensureSubscribeToken(int $accountId): string
    {
        $account = $this->getAccount($accountId);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        if (!empty($account['subscribe_token'])) {
            return (string) $account['subscribe_token'];
        }

        $token = $this->generateSubscribeToken();
        $stmt = $this->db->prepare('UPDATE accounts SET subscribe_token = :token WHERE id = :id');
        $stmt->execute(['token' => $token, 'id' => $accountId]);

        return $token;
    }

    private function generateSubscribeToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function subscribeBaseUrl(): string
    {
        if (!empty($this->config['app']['subscribe_base_url'])) {
            return rtrim((string) $this->config['app']['subscribe_base_url'], '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    public function getAccount(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listAccounts(): array
    {
        $stmt = $this->db->query('SELECT * FROM accounts ORDER BY id DESC');

        return $stmt->fetchAll();
    }

    /**
     * Accounts whose expires_at is after now and within the next $hours hours.
     * Skips unlimited and first-connect-not-yet-connected (expires_at is null).
     *
     * @return list<array<string, mixed>>
     */
    public function listExpiringSoon(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));

        $stmt = $this->db->query(
            'SELECT * FROM accounts
             WHERE expires_at IS NOT NULL
               AND expires_at > NOW()
               AND expires_at <= DATE_ADD(NOW(), INTERVAL ' . $hours . ' HOUR)
             ORDER BY expires_at ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countAccounts(?string $search = null, array $filters = []): int
    {
        [$where, $params] = $this->accountListWhere($search, $filters);
        $stmt = $this->prepareAccountList('SELECT COUNT(*) FROM accounts' . $where, $params);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{active: int, inactive: int}
     */
    public function countAccountsStatus(?string $search = null, array $filters = []): array
    {
        [$where, $params] = $this->accountListWhere($search, $filters);
        $stmt = $this->prepareAccountList(
            'SELECT
                COALESCE(SUM(is_active = 1), 0) AS active_count,
                COALESCE(SUM(is_active = 0), 0) AS inactive_count
             FROM accounts' . $where,
            $params
        );
        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'active' => (int) ($row['active_count'] ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listAccountsPaginated(int $page, int $perPage, ?string $search = null, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->accountListWhere($search, $filters);

        $stmt = $this->prepareAccountList(
            'SELECT * FROM accounts' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
            $params
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private static function accountSearchLike(?string $search): ?string
    {
        if ($search === null || trim($search) === '') {
            return null;
        }

        return '%' . trim($search) . '%';
    }

    /**
     * Shared WHERE for dashboard account list (search AND status AND date ranges).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function accountListWhere(?string $search, array $filters): array
    {
        $clauses = [];
        $params = [];

        $like = self::accountSearchLike($search);
        if ($like !== null) {
            $clauses[] = '(name LIKE :term1 OR ip_address LIKE :term2 OR CAST(id AS CHAR) LIKE :term3)';
            $params['term1'] = $like;
            $params['term2'] = $like;
            $params['term3'] = $like;
        }

        $pendingSql = "(expiry_mode = 'first_connect' AND first_connected_at IS NULL AND IFNULL(expiry_duration_days, 0) > 0)";
        $expiredSql = '(expires_at IS NOT NULL AND expires_at <= NOW())';
        $volumeSql = '(volume_limit_bytes > 0 AND volume_used_bytes >= volume_limit_bytes)';

        $status = (string) ($filters['status'] ?? '');
        switch ($status) {
            case 'active':
                $clauses[] = 'is_active = 1 AND NOT ' . $pendingSql . ' AND NOT ' . $expiredSql . ' AND NOT ' . $volumeSql;
                break;
            case 'inactive':
                $clauses[] = 'is_active = 0';
                break;
            case 'expired':
                $clauses[] = $expiredSql;
                break;
            case 'volume':
                $clauses[] = $volumeSql;
                break;
            case 'expiring':
                $clauses[] = '(expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR))';
                break;
            case 'pending':
                $clauses[] = $pendingSql;
                break;
        }

        $createdFrom = self::accountFilterDate($filters['created_from'] ?? null);
        $createdTo = self::accountFilterDate($filters['created_to'] ?? null);
        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            [$createdFrom, $createdTo] = [$createdTo, $createdFrom];
        }
        if ($createdFrom !== null) {
            $clauses[] = 'created_at >= :created_from';
            $params['created_from'] = $createdFrom;
        }
        if ($createdTo !== null) {
            $clauses[] = 'created_at < DATE_ADD(:created_to, INTERVAL 1 DAY)';
            $params['created_to'] = $createdTo;
        }

        $expiresFrom = self::accountFilterDate($filters['expires_from'] ?? null);
        $expiresTo = self::accountFilterDate($filters['expires_to'] ?? null);
        if ($expiresFrom !== null && $expiresTo !== null && $expiresFrom > $expiresTo) {
            [$expiresFrom, $expiresTo] = [$expiresTo, $expiresFrom];
        }
        if ($expiresFrom !== null || $expiresTo !== null) {
            $clauses[] = 'expires_at IS NOT NULL';
            if ($expiresFrom !== null) {
                $clauses[] = 'expires_at >= :expires_from';
                $params['expires_from'] = $expiresFrom;
            }
            if ($expiresTo !== null) {
                $clauses[] = 'expires_at < DATE_ADD(:expires_to, INTERVAL 1 DAY)';
                $params['expires_to'] = $expiresTo;
            }
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private static function accountFilterDate(mixed $value): ?string
    {
        return Jalali::parseDate((string) $value);
    }

    /**
     * @param array<string, string> $params
     */
    private function prepareAccountList(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }

        return $stmt;
    }

    public function buildClientConfig(array $account): string
    {
        $wg = $this->config['wireguard'];
        $serverPublicKey = $this->getServerPublicKey();

        $lines = [
            '[Interface]',
            'PrivateKey = ' . $account['private_key'],
            'Address = ' . $account['ip_address'] . '/32',
            'DNS = ' . $wg['dns'],
            '',
            '[Peer]',
            'PublicKey = ' . $serverPublicKey,
            'Endpoint = ' . $wg['endpoint'],
            'AllowedIPs = ' . $wg['allowed_ips'],
            'PersistentKeepalive = ' . (int) $wg['persistent_keepalive'],
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public function syncTraffic(): void
    {
        $this->enforceLimitsData();
        $this->syncTrafficData();
    }

    public function enforceLimits(): void
    {
        // enforceLimitsData already calls purgeInactivePeers internally
        $this->enforceLimitsData();
    }

    public function processFirstConnectionExpiry(): void
    {
        $this->activateExpiryFromFirstConnection();
    }

    public function syncTrafficData(bool $verbose = false): void
    {
        $transfers = $this->getWgTransferMap();

        if ($transfers === []) {
            if ($verbose) {
                echo "No WireGuard transfer data.\n";
            }
            return;
        }

        $handshakes = $this->getLatestHandshakes();
        $this->activateExpiryFromFirstConnection($handshakes);
        $handshakeTimeout = (int) ($this->config['wireguard']['handshake_timeout'] ?? 180);

        $accountsByKey = [];
        foreach ($this->listAccounts() as $row) {
            $accountsByKey[trim((string) $row['public_key'])] = $row;
        }

        // For limited users: update volume counter + baseline
        $updateStmt = $this->db->prepare(
            'UPDATE accounts
             SET last_wg_rx_bytes = :current_rx,
                 last_wg_tx_bytes = :current_tx,
                 volume_used_bytes = volume_used_bytes + :delta,
                 updated_at = NOW()
             WHERE id = :id
               AND is_active = 1'
        );

        // For unlimited users: only keep the baseline counters (no volume_used_bytes, no log)
        $baselineOnlyStmt = $this->db->prepare(
            'UPDATE accounts
             SET last_wg_rx_bytes = :current_rx,
                 last_wg_tx_bytes = :current_tx,
                 updated_at = NOW()
             WHERE id = :id
               AND is_active = 1'
        );

        $logStmt = $this->db->prepare(
            'INSERT INTO traffic_logs (account_id, rx_bytes, tx_bytes)
             VALUES (:account_id, :rx, :tx)'
        );

        foreach ($transfers as $publicKey => $stats) {
            $currentRx = $stats['rx_bytes'];
            $currentTx = $stats['tx_bytes'];
            $account = $accountsByKey[$publicKey] ?? null;

            if ($account === null) {
                if ($verbose) {
                    echo "Skipped orphan peer (runtime cleanup handled by limits/sync): {$publicKey}\n";
                }
                continue;
            }

            if (!$this->shouldCountTraffic($account)) {
                if ($verbose) {
                    echo "Skipped traffic for inactive/expired/over-limit account #{$account['id']} ({$account['name']})\n";
                }
                continue;
            }

            $handshakeAt = $this->lookupHandshake(trim($publicKey), $handshakes);

            if (!$this->isPeerConnected($handshakeAt, $handshakeTimeout)) {
                $this->baselineTrafficCounters((int) $account['id'], $currentRx, $currentTx);

                if ($verbose) {
                    echo sprintf(
                        "Disconnected #%d (%s): rx=%d tx=%d | last handshake=%s | baseline only (delta=0)\n",
                        (int) $account['id'],
                        $account['name'],
                        $currentRx,
                        $currentTx,
                        $handshakeAt > 0 ? date('Y-m-d H:i:s', $handshakeAt) : 'never'
                    );
                }
                continue;
            }

            $lastRx = $this->nullableInt($account['last_wg_rx_bytes'] ?? null);
            $lastTx = $this->nullableInt($account['last_wg_tx_bytes'] ?? null);

            if ($lastRx !== null && $lastTx !== null && $currentRx === $lastRx && $currentTx === $lastTx) {
                if ($verbose) {
                    echo "No change #{$account['id']} ({$account['name']}): rx={$currentRx} tx={$currentTx}\n";
                }
                continue;
            }

            $delta = $this->calculateTrafficDelta($currentRx, $currentTx, $lastRx, $lastTx);

            $isUnlimited = (int) $account['volume_limit_bytes'] === 0;

            if ($isUnlimited) {
                // Unlimited users: just update the WireGuard baseline counters.
                // volume_used_bytes stays at 0 and no traffic_logs row is written.
                $baselineOnlyStmt->execute([
                    'current_rx' => $currentRx,
                    'current_tx' => $currentTx,
                    'id'         => (int) $account['id'],
                ]);
            } else {
                $updateStmt->execute([
                    'current_rx' => $currentRx,
                    'current_tx' => $currentTx,
                    'delta'      => $delta['total'],
                    'id'         => (int) $account['id'],
                ]);

                if ($delta['total'] > 0) {
                    $logStmt->execute([
                        'account_id' => (int) $account['id'],
                        'rx'         => $delta['rx'],
                        'tx'         => $delta['tx'],
                    ]);
                }
            }

            if ($verbose) {
                echo sprintf(
                    "Account #%d (%s): wg rx=%d tx=%d | last rx=%s tx=%s | delta=%d\n",
                    (int) $account['id'],
                    $account['name'],
                    $currentRx,
                    $currentTx,
                    $lastRx === null ? 'NULL' : (string) $lastRx,
                    $lastTx === null ? 'NULL' : (string) $lastTx,
                    $delta['total']
                );
            }
        }

        $this->purgeInactivePeers();
    }

    /**
     * Compare DB active accounts with wg0.conf and live WireGuard peers.
     *
     * @return array{
     *     active_count: int,
     *     conf_peer_count: int,
     *     runtime_peer_count: int,
     *     missing_in_conf: list<array{id:int,name:string,ip:string,public_key:string}>,
     *     missing_in_runtime: list<array{id:int,name:string,ip:string,public_key:string}>,
     *     stale_in_conf: list<array{public_key:string,name:?string,ip:?string,reason:string}>,
     *     stale_in_runtime: list<array{public_key:string,name:?string,ip:?string,reason:string}>
     * }
     */
    public function analyzeWireguardSync(): array
    {
        $allAccounts = $this->db->query('SELECT * FROM accounts')->fetchAll();
        $dbKeyMap = [];
        foreach ($allAccounts as $account) {
            $dbKeyMap[trim((string) $account['public_key'])] = $account;
        }

        $activeByKey = [];
        foreach ($allAccounts as $account) {
            if (
                (int) $account['is_active'] === 1
                && !$this->isExpired($account)
                && !$this->isVolumeExceeded($account)
            ) {
                $activeByKey[trim((string) $account['public_key'])] = $account;
            }
        }

        $confKeys = $this->loadConfPeerKeys();
        $runtimeKeys = $this->parseRuntimePeerKeys();
        $confSet = array_fill_keys($confKeys, true);
        $runtimeSet = array_fill_keys($runtimeKeys, true);

        $missingInConf = [];
        $missingInRuntime = [];
        foreach ($activeByKey as $key => $account) {
            if (!isset($confSet[$key])) {
                $missingInConf[] = $this->accountWireguardSyncRow($account);
            }
            if (!isset($runtimeSet[$key])) {
                $missingInRuntime[] = $this->accountWireguardSyncRow($account);
            }
        }

        $staleInConf = [];
        foreach ($confKeys as $key) {
            if (!isset($activeByKey[$key])) {
                $staleInConf[] = $this->staleWireguardSyncRow($key, $dbKeyMap[$key] ?? null);
            }
        }

        $staleInRuntime = [];
        foreach ($runtimeKeys as $key) {
            if (!isset($activeByKey[$key])) {
                $staleInRuntime[] = $this->staleWireguardSyncRow($key, $dbKeyMap[$key] ?? null);
            }
        }

        return [
            'active_count' => count($activeByKey),
            'conf_peer_count' => count($confKeys),
            'runtime_peer_count' => count($runtimeKeys),
            'missing_in_conf' => $missingInConf,
            'missing_in_runtime' => $missingInRuntime,
            'stale_in_conf' => $staleInConf,
            'stale_in_runtime' => $staleInRuntime,
        ];
    }

    /**
     * Sync live WireGuard with database, then rewrite wg0.conf [Peer] blocks from DB.
     * - Remove stale peers from runtime
     * - Add active peers missing from runtime
     * - Persist [Peer] section from active accounts (keeps [Interface])
     *
     * @return array{
     *     added: list<string>,
     *     removed: list<string>,
     *     errors: list<string>,
     *     dry_run: bool,
     *     before: array<string, mixed>,
     *     after: array<string, mixed>
     * }
     */
    public function syncWireguard(bool $dryRun = false): array
    {
        $before = $this->analyzeWireguardSync();
        $summary = [
            'added' => [],
            'removed' => [],
            'errors' => [],
            'dry_run' => $dryRun,
            'before' => $before,
            'after' => $before,
        ];

        if ($dryRun) {
            return $summary;
        }

        $allAccounts = $this->db->query('SELECT * FROM accounts')->fetchAll();
        $dbKeyMap = [];
        $activeByKey = [];

        foreach ($allAccounts as $account) {
            $key = trim((string) $account['public_key']);
            $dbKeyMap[$key] = $account;
            if (
                (int) $account['is_active'] === 1
                && !$this->isExpired($account)
                && !$this->isVolumeExceeded($account)
            ) {
                $activeByKey[$key] = $account;
            }
        }

        foreach ($before['stale_in_runtime'] as $item) {
            $key = $item['public_key'];
            $account = $dbKeyMap[$key] ?? null;

            try {
                if ($account !== null) {
                    $this->removePeer($account);
                    $summary['removed'][] = ($account['name'] ?? $key) . ' (' . ($account['ip_address'] ?? '') . ')';
                } else {
                    if (!$this->isValidWireGuardPublicKey($key)) {
                        continue;
                    }

                    $this->removePeerFromRuntime($key);
                    $summary['removed'][] = 'orphan:' . substr($key, 0, 12) . '…';
                }
            } catch (Throwable $e) {
                $summary['errors'][] = ($account['name'] ?? $key) . ': ' . $e->getMessage();
            }
        }

        foreach ($before['missing_in_runtime'] as $item) {
            $account = $activeByKey[$item['public_key']] ?? null;
            if ($account === null) {
                continue;
            }

            try {
                $this->applyPeer($account);
                $summary['added'][] = ($account['name'] ?? $item['public_key']) . ' (' . ($account['ip_address'] ?? '') . ')';
            } catch (Throwable $e) {
                $summary['errors'][] = ($account['name'] ?? $item['public_key']) . ': ' . $e->getMessage();
            }
        }

        try {
            $this->persistConfPeers(array_values($activeByKey));
        } catch (Throwable $e) {
            $summary['errors'][] = 'persist wg0.conf: ' . $e->getMessage();
        }

        $summary['after'] = $this->analyzeWireguardSync();

        return $summary;
    }

    /**
     * Re-apply active accounts that exist in DB/conf but are missing from live WireGuard.
     * Safe to run after enforceLimits or on a schedule.
     *
     * @return array{added: list<string>, errors: list<string>}
     */
    public function reconcileRuntimePeers(bool $verbose = false): array
    {
        $analysis = $this->analyzeWireguardSync();
        $result   = ['added' => [], 'errors' => []];

        foreach ($analysis['missing_in_runtime'] as $item) {
            $account = $this->getAccount((int) $item['id']);

            if ($account === null) {
                continue;
            }

            try {
                $this->applyPeer($account);
                $label = ($account['name'] ?? 'account') . ' (' . ($account['ip_address'] ?? '') . ')';
                $result['added'][] = $label;

                if ($verbose) {
                    echo "Re-applied runtime peer: {$label}\n";
                }
            } catch (Throwable $e) {
                $label = ($account['name'] ?? 'account') . ': ' . $e->getMessage();
                $result['errors'][] = $label;

                if ($verbose) {
                    echo "Failed to re-apply peer: {$label}\n";
                }
            }
        }

        return $result;
    }

    /** @return array{id:int,name:string,ip:string,public_key:string} */
    private function accountWireguardSyncRow(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'name' => (string) $account['name'],
            'ip' => (string) $account['ip_address'],
            'public_key' => trim((string) $account['public_key']),
        ];
    }

    /** @return array{public_key:string,name:?string,ip:?string,reason:string} */
    private function staleWireguardSyncRow(string $publicKey, ?array $account): array
    {
        if ($account === null) {
            return [
                'public_key' => $publicKey,
                'name' => null,
                'ip' => null,
                'reason' => 'orphan',
            ];
        }

        if ((int) $account['is_active'] !== 1) {
            $reason = 'inactive';
        } elseif ($this->isExpired($account)) {
            $reason = 'expired';
        } elseif ($this->isVolumeExceeded($account)) {
            $reason = 'volume exceeded';
        } else {
            $reason = 'not active';
        }

        return [
            'public_key' => $publicKey,
            'name' => (string) $account['name'],
            'ip' => (string) $account['ip_address'],
            'reason' => $reason,
        ];
    }

    /** @return string[] */
    private function parseRuntimePeerKeys(): array
    {
        $interface = $this->wgInterface();
        $result = Shell::run("{$this->wgBinary()} show {$interface} dump", false, true);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                'خواندن peerهای WireGuard از kernel ناموفق بود. '
                . 'sudoers را برای www-data بررسی کنید (wg show dump). '
                . trim((string) ($result['output'] ?? ''))
            );
        }

        $keys = [];

        foreach (preg_split('/\R/', trim((string) ($result['output'] ?? ''))) ?: [] as $line) {
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }

            $publicKey = trim(explode("\t", $line)[0] ?? '');

            if ($this->isValidWireGuardPublicKey($publicKey)) {
                $keys[] = $publicKey;
            }
        }

        return $keys;
    }

    /** @return string[] list of public keys found in [Peer] blocks */
    private function loadConfPeerKeys(): array
    {
        $confPath = (string) ($this->config['wireguard']['config_path'] ?? '/etc/wireguard/' . $this->wgInterface() . '.conf');

        if (is_readable($confPath)) {
            return $this->parseConfPeerKeys($confPath);
        }

        $tmp = dirname(__DIR__) . '/storage/wg-conf-read.' . getmypid() . '.conf';
        $script = (string) ($this->config['scripts']['read_wg_conf'] ?? dirname(__DIR__) . '/scripts/read-wg-conf.sh');

        try {
            $result = Shell::runScript($script, [$this->wgInterface(), $tmp], false);
            if ($result['exit_code'] !== 0 || !is_readable($tmp)) {
                return [];
            }

            return $this->parseConfPeerKeys($tmp);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return string[] list of public keys found in [Peer] blocks */
    private function parseConfPeerKeys(string $confPath): array
    {
        if (!is_readable($confPath)) {
            return [];
        }

        $keys    = [];
        $inPeer  = false;

        foreach (file($confPath, FILE_IGNORE_NEW_LINES) as $line) {
            $line = rtrim($line, "\r");
            $line = trim($line);
            if (strtolower($line) === '[peer]') {
                $inPeer = true;
                continue;
            }
            if (str_starts_with($line, '[')) {
                $inPeer = false;
                continue;
            }
            if ($inPeer && stripos($line, 'PublicKey') === 0) {
                $parts = explode('=', $line, 2);
                $key   = trim($parts[1] ?? '');
                if ($this->isValidWireGuardPublicKey($key)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Replace [Peer] blocks in wg0.conf from active DB accounts. Keeps [Interface].
     *
     * @param list<array<string, mixed>> $activeAccounts
     */
    private function persistConfPeers(array $activeAccounts): void
    {
        $script = (string) ($this->config['scripts']['persist_wg_peers'] ?? dirname(__DIR__) . '/scripts/persist-wg-peers.sh');
        $storageDir = dirname(__DIR__) . '/storage';

        if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Cannot create storage directory for WireGuard persist.');
        }

        $peersFile = $storageDir . '/wg-peers.' . bin2hex(random_bytes(8)) . '.txt';
        $blocks = [];

        foreach ($activeAccounts as $account) {
            $key = trim((string) ($account['public_key'] ?? ''));
            $ip = trim((string) ($account['ip_address'] ?? ''));

            if (!$this->isValidWireGuardPublicKey($key) || $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $blocks[] = "[Peer]\nPublicKey = {$key}\nAllowedIPs = {$ip}/32";
        }

        $payload = $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";

        try {
            if (file_put_contents($peersFile, $payload) === false) {
                throw new RuntimeException('Cannot write WireGuard peers snapshot.');
            }

            $result = Shell::runScript($script, [$this->wgInterface(), $peersFile], false);

            if ($result['exit_code'] !== 0) {
                $detail = trim((string) ($result['output'] ?? ''));
                if ($detail === '') {
                    $detail = 'no output from persist-wg-peers.sh';
                }

                throw new RuntimeException(
                    'persist-wg-peers.sh failed (exit ' . $result['exit_code'] . '): ' . $detail
                    . '. Check sudoers for persist-wg-peers.sh, then: sudo bash scripts/fix-permissions.sh'
                );
            }
        } finally {
            if (is_file($peersFile)) {
                @unlink($peersFile);
            }
        }
    }

    public function purgeInactivePeers(): void
    {
        $stmt = $this->db->query(
            'SELECT * FROM accounts
             WHERE is_active = 0
                OR (expires_at IS NOT NULL AND expires_at <= NOW())
                OR (volume_limit_bytes > 0 AND volume_used_bytes >= volume_limit_bytes)'
        );

        foreach ($stmt->fetchAll() as $account) {
            $this->removePeer($account);
        }
    }

    public function enforceLimitsData(): void
    {
        $this->activateExpiryFromFirstConnection();

        $disableStmt = $this->db->prepare(
            'UPDATE accounts SET is_active = 0, updated_at = NOW() WHERE id = :id'
        );

        $expiredStmt = $this->db->query(
            'SELECT * FROM accounts
             WHERE is_active = 1
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()'
        );

        foreach ($expiredStmt->fetchAll() as $account) {
            $disableStmt->execute(['id' => (int) $account['id']]);
            $this->removePeer($account);
        }

        $volumeStmt = $this->db->query(
            'SELECT * FROM accounts
             WHERE is_active = 1
               AND volume_limit_bytes > 0
               AND volume_used_bytes >= volume_limit_bytes'
        );

        foreach ($volumeStmt->fetchAll() as $account) {
            $disableStmt->execute(['id' => (int) $account['id']]);
            $this->removePeer($account);
        }

        $this->purgeInactivePeers();
    }

    public function isExpired(array $account): bool
    {
        if (empty($account['expires_at'])) {
            return false;
        }

        return strtotime((string) $account['expires_at']) <= time();
    }

    public function isVolumeExceeded(array $account): bool
    {
        $limit = (int) $account['volume_limit_bytes'];

        if ($limit <= 0) {
            return false;
        }

        return (int) $account['volume_used_bytes'] >= $limit;
    }

    private function shouldCountTraffic(array $account): bool
    {
        if ((int) $account['is_active'] !== 1) {
            return false;
        }

        if ($this->isExpired($account)) {
            return false;
        }

        if ($this->isVolumeExceeded($account)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>>|null $accounts
     * @return array<string, mixed>
     */
    public function getAllOnlineStatuses(?array $accounts = null): array
    {
        return $this->getOnlineStatusesForAccounts($accounts ?? $this->listAccounts());
    }

    /**
     * Like getAllOnlineStatuses but only fetches accounts whose IDs are given.
     * Used by the dashboard polling endpoint to avoid loading all accounts.
     *
     * @param  int[]  $ids
     * @return array<string, mixed>
     */
    public function getOnlineStatusesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, public_key, is_active, expires_at, expiry_mode,
                    expiry_duration_days, first_connected_at,
                    volume_used_bytes, volume_limit_bytes
               FROM accounts
              WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));
        $accounts = $stmt->fetchAll();

        $handshakes = $this->getLatestHandshakes();
        $result = [];

        foreach ($accounts as $account) {
            $result[(string) $account['id']] = $this->buildOnlineStatus($account, $handshakes);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @return array<string, mixed>
     */
    public function getOnlineStatusesForAccounts(array $accounts): array
    {
        $handshakes = $this->getLatestHandshakes();
        $result = [];

        foreach ($accounts as $account) {
            $result[(string) $account['id']] = $this->buildOnlineStatus($account, $handshakes);
        }

        return $result;
    }

    public function getAccountOnlineStatus(array $account): array
    {
        return $this->buildOnlineStatus($account, $this->getLatestHandshakes());
    }

    public function isWireGuardHandshakesAvailable(): bool
    {
        $this->getLatestHandshakes();

        return $this->latestHandshakesOk === true;
    }

    public function getOnlineTimeoutSeconds(): int
    {
        return $this->onlineTimeoutSeconds();
    }

    public function getSubscribeLiveData(array $account): array
    {
        $online = $this->getAccountOnlineStatus($account);
        $volumePercent = Helpers::volumePercent($account);

        return array_merge($online, [
            'volume_used_bytes' => (int) $account['volume_used_bytes'],
            'volume_limit_bytes' => (int) $account['volume_limit_bytes'],
            'volume_used_human' => Helpers::formatBytes((int) $account['volume_used_bytes']),
            'volume_limit_human' => (int) $account['volume_limit_bytes'] > 0
                ? Helpers::formatBytes((int) $account['volume_limit_bytes'])
                : 'نامحدود',
            'volume_display_html' => Helpers::formatVolumeRangeHtml(
                (int) $account['volume_used_bytes'],
                (int) $account['volume_limit_bytes']
            ),
            'volume_percent_html' => Helpers::formatVolumePercentHtml($volumePercent),
            'expiry_display_html' => Helpers::formatExpiryDisplayHtml($account),
            'speed_display_html' => Helpers::formatSpeedHtml((int) $account['speed_limit_kbps']),
            'speed_hint_html' => Helpers::formatSpeedHintHtml((int) $account['speed_limit_kbps']),
            'volume_percent' => $volumePercent,
            'expires_at' => $account['expires_at'],
            'expiry_mode' => $account['expiry_mode'] ?? 'fixed',
            'expiry_duration_days' => (int) ($account['expiry_duration_days'] ?? 0),
            'first_connected_at' => $account['first_connected_at'] ?? null,
            'expiry_display' => Helpers::formatExpiryDisplay($account),
            'expiry_pending' => Helpers::isFirstConnectExpiry($account) && empty($account['first_connected_at']),
            'days_left' => Helpers::daysUntilExpiryForAccount($account),
            'account_status' => Helpers::statusBadge($account),
        ]);
    }

    private function buildOnlineStatus(array $account, array $handshakes): array
    {
        if (!$this->shouldCountTraffic($account)) {
            return [
                'online' => false,
                'state' => 'disabled',
                'label' => 'قطع',
                'last_handshake' => 0,
                'last_handshake_at' => null,
                'seconds_ago' => null,
                'relative' => '—',
                'wg_ok' => $this->latestHandshakesOk ?? false,
            ];
        }

        if ($this->latestHandshakesOk !== true) {
            return [
                'online' => false,
                'state' => 'unknown',
                'label' => 'نامشخص',
                'last_handshake' => 0,
                'last_handshake_at' => null,
                'seconds_ago' => null,
                'relative' => '—',
                'wg_ok' => false,
                'title' => 'خواندن handshake از WireGuard ممکن نشد',
            ];
        }

        $publicKey = trim((string) $account['public_key']);
        $lastHandshake = $this->lookupHandshake($publicKey, $handshakes);
        $timeout = $this->onlineTimeoutSeconds();
        $online = $this->isPeerConnected($lastHandshake, $timeout);
        $secondsAgo = $lastHandshake > 0 ? time() - $lastHandshake : null;

        return [
            'online' => $online,
            'state' => $online ? 'online' : 'offline',
            'label' => $online ? 'آنلاین' : 'آفلاین',
            'last_handshake' => $lastHandshake,
            'last_handshake_at' => $lastHandshake > 0 ? Helpers::formatDateTime(date('Y-m-d H:i:s', $lastHandshake)) : null,
            'seconds_ago' => $secondsAgo,
            'relative' => Helpers::formatRelativeTime($secondsAgo),
            'timeout' => $timeout,
            'wg_ok' => true,
            'title' => $lastHandshake > 0
                ? 'آخرین handshake: ' . Helpers::formatDateTime(date('Y-m-d H:i:s', $lastHandshake)) . ' (' . Helpers::formatRelativeTime($secondsAgo) . ')'
                : 'هنوز handshake ثبت نشده',
        ];
    }

    private function onlineTimeoutSeconds(): int
    {
        $wg = $this->config['wireguard'] ?? [];

        // WireGuard renegotiates a session every ~180 s regardless of persistent_keepalive.
        // online_timeout must therefore be >= 180 to avoid false "offline" flicker.
        // If the operator sets online_timeout explicitly we honour it as-is (min 60 s).
        if (array_key_exists('online_timeout', $wg)) {
            return max(60, (int) $wg['online_timeout']);
        }

        $configured = max(180, (int) ($wg['handshake_timeout'] ?? 180));

        return $configured;
    }

    /** @param array<string, int> $handshakes */
    private function lookupHandshake(string $publicKey, array $handshakes): int
    {
        if (isset($handshakes[$publicKey])) {
            return $handshakes[$publicKey];
        }

        foreach ($handshakes as $key => $timestamp) {
            if (trim((string) $key) === $publicKey) {
                return (int) $timestamp;
            }
        }

        return 0;
    }

    private function wgBinary(): string
    {
        return (string) ($this->config['wireguard']['wg_binary'] ?? '/usr/bin/wg');
    }

    private function wgInterface(): string
    {
        $interface = (string) ($this->config['wireguard']['interface'] ?? 'wg0');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $interface)) {
            throw new RuntimeException('Invalid WireGuard interface name.');
        }

        return $interface;
    }

    private function isValidWireGuardPublicKey(string $key): bool
    {
        $key = trim($key);

        if ($key === '') {
            return false;
        }

        if (preg_match('/\s|:/', $key) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9+\/]{42,43}={0,2}$/', $key) === 1;
    }

    /** @return array<string, int> */
    private function getLatestHandshakes(): array
    {
        if ($this->latestHandshakesCache !== null) {
            return $this->latestHandshakesCache;
        }

        $interface = $this->wgInterface();
        $result = Shell::run("{$this->wgBinary()} show {$interface} latest-handshakes", false, true);

        if ($result['exit_code'] === 0) {
            $this->latestHandshakesOk = true;
            $this->latestHandshakesCache = trim($result['output']) === ''
                ? []
                : $this->parseLatestHandshakesOutput($result['output']);

            return $this->latestHandshakesCache;
        }

        $dump = Shell::run("{$this->wgBinary()} show {$interface} dump", false, true);

        if ($dump['exit_code'] === 0) {
            $this->latestHandshakesOk = true;
            $this->latestHandshakesCache = trim($dump['output']) === ''
                ? []
                : $this->parseDumpHandshakes($dump['output']);

            return $this->latestHandshakesCache;
        }

        $this->latestHandshakesOk = false;
        $this->latestHandshakesCache = [];

        return $this->latestHandshakesCache;
    }

    /** @return array<string, int> */
    private function parseLatestHandshakesOutput(string $output): array
    {
        $handshakes = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line), 2);

            if ($parts === false || count($parts) < 2) {
                continue;
            }

            $publicKey = trim($parts[0]);

            if (!$this->isValidWireGuardPublicKey($publicKey)) {
                continue;
            }

            $handshakes[$publicKey] = (int) $parts[1];
        }

        return $handshakes;
    }

    /** @return array<string, int> */
    private function parseDumpHandshakes(string $output): array
    {
        $handshakes = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 5) {
                continue;
            }

            $publicKey = trim($parts[0]);

            if (!$this->isValidWireGuardPublicKey($publicKey)) {
                continue;
            }

            $handshakes[$publicKey] = (int) $parts[4];
        }

        return $handshakes;
    }

    private function isPeerConnected(int $timestamp, int $maxAgeSeconds): bool
    {
        if ($maxAgeSeconds <= 0) {
            return true;
        }

        if ($timestamp <= 0) {
            return false;
        }

        return (time() - $timestamp) <= $maxAgeSeconds;
    }

    private function baselineTrafficCounters(int $accountId, int $currentRx, int $currentTx): void
    {
        $this->db->prepare(
            'UPDATE accounts
             SET last_wg_rx_bytes = :current_rx,
                 last_wg_tx_bytes = :current_tx,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'current_rx' => $currentRx,
            'current_tx' => $currentTx,
            'id' => $accountId,
        ]);
    }

    private function calculateTrafficDelta(int $currentRx, int $currentTx, ?int $lastRx, ?int $lastTx): array
    {
        if ($lastRx === null || $lastTx === null) {
            return ['rx' => 0, 'tx' => 0, 'total' => 0];
        }

        if ($currentRx < $lastRx) {
            $deltaRx = $currentRx;
        } else {
            $deltaRx = $currentRx - $lastRx;
        }

        if ($currentTx < $lastTx) {
            $deltaTx = $currentTx;
        } else {
            $deltaTx = $currentTx - $lastTx;
        }

        return [
            'rx' => $deltaRx,
            'tx' => $deltaTx,
            'total' => $deltaRx + $deltaTx,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function clearTrafficBaseline(int $accountId): void
    {
        $this->db->prepare(
            'UPDATE accounts
             SET last_wg_rx_bytes = NULL,
                 last_wg_tx_bytes = NULL
             WHERE id = :id'
        )->execute(['id' => $accountId]);
    }

    private function removePeerFromRuntime(string $publicKey): void
    {
        $publicKey = trim($publicKey);

        if (!$this->isValidWireGuardPublicKey($publicKey)) {
            throw new RuntimeException('Invalid WireGuard public key for runtime remove.');
        }

        $interface = $this->wgInterface();
        $result = Shell::run(
            $this->wgBinary() . ' set ' . $interface . ' peer ' . escapeshellarg($publicKey) . ' remove',
            false,
            true
        );

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                'Failed to remove peer from WireGuard runtime: ' . trim($result['output'])
            );
        }

        if ($this->runtimePeerStillPresent($publicKey)) {
            throw new RuntimeException(
                'Peer still present in WireGuard after remove (key ' . substr($publicKey, 0, 12) . '…).'
            );
        }
    }

    private function runtimePeerStillPresent(string $publicKey): bool
    {
        foreach ($this->parseRuntimePeerKeys() as $key) {
            if ($key === $publicKey) {
                return true;
            }
        }

        return false;
    }

    private function applyPeer(array $account): void
    {
        Shell::runScript($this->config['scripts']['apply_peer'], [
            $this->config['wireguard']['interface'],
            $account['public_key'],
            $account['ip_address'] . '/32',
            (string) $account['speed_limit_kbps'],
        ]);
    }

    private function removePeer(array $account, bool $mustSucceed = false): void
    {
        $result = Shell::runScript($this->config['scripts']['remove_peer'], [
            $this->config['wireguard']['interface'],
            $account['public_key'],
            $account['ip_address'],
            (string) $account['speed_limit_kbps'],
        ], false);

        if ($mustSucceed && $result['exit_code'] !== 0) {
            throw new RuntimeException(
                'Failed to remove peer from WireGuard: ' . $result['output']
            );
        }
    }

    public function getServerPublicKey(): string
    {
        if ($this->serverPublicKeyCache !== null) {
            return $this->serverPublicKeyCache;
        }

        // Try static key from config first
        $static = trim((string) ($this->config['wireguard']['server_public_key'] ?? ''));
        if ($static !== '' && $static !== '(none)') {
            $this->serverPublicKeyCache = $static;

            return $this->serverPublicKeyCache;
        }

        $interface = $this->wgInterface();
        $result = Shell::run("{$this->wgBinary()} show {$interface} public-key", false, true);
        $key = trim($result['output'] ?? '');

        if ($result['exit_code'] !== 0 || $key === '' || $key === '(none)') {
            throw new RuntimeException(
                'Cannot read WireGuard server public key. ' .
                'Set "server_public_key" in config wireguard section, or ensure wg0 is running with a private key.'
            );
        }

        $this->serverPublicKeyCache = $key;

        return $this->serverPublicKeyCache;
    }

    private function normalizeExpiry(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return null;
        }

        $normalized = Jalali::parseDateTime($expiresAt);

        if ($normalized === null) {
            throw new RuntimeException('تاریخ انقضا نامعتبر است.');
        }

        return $normalized;
    }

    /** @return array{expiry_mode: string, expiry_duration_days: ?int, expires_at: ?string, first_connected_at: ?string} */
    private function resolveExpiryFields(array $data, ?array $existing = null): array
    {
        $mode = (string) ($data['expiry_mode'] ?? ($existing['expiry_mode'] ?? 'fixed'));
        if (!in_array($mode, ['fixed', 'first_connect'], true)) {
            throw new RuntimeException('Invalid expiry mode.');
        }

        if ($mode === 'first_connect') {
            $days = max(0, (int) ($data['expiry_duration_days'] ?? ($existing['expiry_duration_days'] ?? 0)));

            if ($existing !== null && !empty($existing['first_connected_at'])) {
                $firstTs = strtotime((string) $existing['first_connected_at']);
                $expiresAt = ($days > 0 && $firstTs !== false)
                    ? date('Y-m-d H:i:s', $firstTs + ($days * 86400))
                    : null;

                return [
                    'expiry_mode' => 'first_connect',
                    'expiry_duration_days' => $days > 0 ? $days : null,
                    'expires_at' => $expiresAt,
                    'first_connected_at' => (string) $existing['first_connected_at'],
                ];
            }

            return [
                'expiry_mode' => 'first_connect',
                'expiry_duration_days' => $days > 0 ? $days : null,
                'expires_at' => null,
                'first_connected_at' => null,
            ];
        }

        $expiresAt = array_key_exists('expires_at', $data)
            ? $this->normalizeExpiry($data['expires_at'])
            : ($existing['expires_at'] ?? null);

        return [
            'expiry_mode' => 'fixed',
            'expiry_duration_days' => null,
            'expires_at' => $expiresAt,
            'first_connected_at' => null,
        ];
    }

    /** @param array<string, int>|null $handshakes */
    private function activateExpiryFromFirstConnection(?array $handshakes = null): void
    {
        $handshakes ??= $this->getLatestHandshakes();
        $handshakeTimeout = (int) ($this->config['wireguard']['handshake_timeout'] ?? 180);

        $stmt = $this->db->query(
            "SELECT * FROM accounts
             WHERE expiry_mode = 'first_connect'
               AND first_connected_at IS NULL
               AND expiry_duration_days IS NOT NULL
               AND expiry_duration_days > 0"
        );

        $update = $this->db->prepare(
            'UPDATE accounts
             SET first_connected_at = :first_connected_at,
                 expires_at = :expires_at,
                 expiry_await_reconnect = 0,
                 updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($stmt->fetchAll() as $account) {
            $accountId = (int) $account['id'];
            $publicKey = trim((string) $account['public_key']);
            $handshakeAt = $this->lookupHandshake($publicKey, $handshakes);
            $connected = $this->isPeerConnected($handshakeAt, $handshakeTimeout);
            $awaitReconnect = (int) ($account['expiry_await_reconnect'] ?? 0) === 1;

            if ($awaitReconnect) {
                if ($connected) {
                    continue;
                }

                $this->clearExpiryAwaitReconnect($accountId);

                if ($handshakeAt <= 0) {
                    continue;
                }
            }

            if ($handshakeAt <= 0) {
                continue;
            }

            $days = (int) $account['expiry_duration_days'];
            $firstConnectedAt = date('Y-m-d H:i:s', $handshakeAt);
            $expiresAt = date('Y-m-d H:i:s', $handshakeAt + ($days * 86400));

            $update->execute([
                'id' => $accountId,
                'first_connected_at' => $firstConnectedAt,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    public function regenerateSubscribeToken(int $id): array
    {
        $account = $this->getAccount($id);

        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $token = $this->generateSubscribeToken();
        $stmt = $this->db->prepare(
            'UPDATE accounts SET subscribe_token = :token, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['token' => $token, 'id' => $id]);

        $updated = $this->getAccount($id);

        if ($updated === null) {
            throw new RuntimeException('Failed to regenerate subscribe token.');
        }

        return $updated;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function getTrafficLogs(int $accountId, int $page = 1, int $perPage = 50): array
    {
        if ($this->getAccount($accountId) === null) {
            throw new RuntimeException('Account not found.');
        }

        $page = max(1, $page);
        $perPage = max(5, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM traffic_logs WHERE account_id = :id');
        $countStmt->execute(['id' => $accountId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT id, account_id, rx_bytes, tx_bytes, recorded_at
             FROM traffic_logs
             WHERE account_id = :id
             ORDER BY recorded_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'account_id' => (int) $row['account_id'],
                'rx_bytes' => (int) $row['rx_bytes'],
                'tx_bytes' => (int) $row['tx_bytes'],
                'total_bytes' => (int) $row['rx_bytes'] + (int) $row['tx_bytes'],
                'recorded_at' => (string) $row['recorded_at'],
            ],
            $stmt->fetchAll(),
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /** @return array{public_key: string, rx_bytes: int, tx_bytes: int, total_bytes: int}|null */
    public function getPeerTransferStats(array $account): ?array
    {
        $publicKey = trim((string) $account['public_key']);
        $stats = $this->getWgTransferMap()[$publicKey] ?? null;

        if ($stats === null) {
            return null;
        }

        return [
            'public_key' => $publicKey,
            'rx_bytes' => $stats['rx_bytes'],
            'tx_bytes' => $stats['tx_bytes'],
            'total_bytes' => $stats['total_bytes'],
        ];
    }

    /**
     * @return array<string, array{rx_bytes: int, tx_bytes: int, total_bytes: int}>
     */
    private function getWgTransferMap(): array
    {
        if ($this->wgTransferMapCache !== null) {
            return $this->wgTransferMapCache;
        }

        $this->wgTransferMapCache = [];
        $interface = $this->wgInterface();
        $result = Shell::run("{$this->wgBinary()} show {$interface} transfer", false, true);

        if ($result['exit_code'] !== 0 || trim($result['output']) === '') {
            return $this->wgTransferMapCache;
        }

        foreach (explode("\n", trim($result['output'])) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));

            if ($parts === false || count($parts) < 3) {
                continue;
            }

            [$key, $rx, $tx] = $parts;
            $publicKey = trim((string) $key);

            if (!$this->isValidWireGuardPublicKey($publicKey)) {
                continue;
            }

            $rxBytes = (int) $rx;
            $txBytes = (int) $tx;

            $this->wgTransferMapCache[$publicKey] = [
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'total_bytes' => $rxBytes + $txBytes,
            ];
        }

        return $this->wgTransferMapCache;
    }

    /** @return array{synced: bool, log_lines: list<string>, updated_at: string} */
    public function syncTrafficDataReport(bool $verbose = true): array
    {
        ob_start();
        $this->syncTrafficData($verbose);
        $log = trim((string) ob_get_clean());

        return [
            'synced' => true,
            'log_lines' => $log === '' ? [] : explode("\n", $log),
            'updated_at' => date('c'),
        ];
    }
}
