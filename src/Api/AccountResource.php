<?php

declare(strict_types=1);

namespace WgPanel\Api;

use WgPanel\Helpers;
use WgPanel\WireGuardManager;

final class AccountResource
{
    public static function summary(
        array $account,
        ?array $online = null,
        ?WireGuardManager $wgManager = null,
    ): array {
        $badge = Helpers::statusBadge($account);

        $item = [
            'id'                    => (int) $account['id'],
            'name'                  => (string) $account['name'],
            'ip_address'            => (string) $account['ip_address'],
            'public_key'            => (string) $account['public_key'],
            'speed_limit_kbps'      => (int) $account['speed_limit_kbps'],
            'volume_limit_bytes'    => (int) $account['volume_limit_bytes'],
            'volume_used_bytes'     => (int) $account['volume_used_bytes'],
            'expires_at'            => $account['expires_at'],
            'expiry_mode'           => $account['expiry_mode'] ?? 'fixed',
            'expiry_duration_days'  => (int) ($account['expiry_duration_days'] ?? 0),
            'first_connected_at'    => $account['first_connected_at'] ?? null,
            'expiry_await_reconnect'=> (int) ($account['expiry_await_reconnect'] ?? 0) === 1,
            'expiry_display'        => Helpers::formatExpiryDisplay($account),
            'is_active'             => (int) $account['is_active'] === 1,
            'status'                => [
                'label' => $badge['label'],
                'class' => $badge['class'],
            ],
            'created_at'  => $account['created_at'],
            'updated_at'  => $account['updated_at'],
        ];

        if ($online !== null) {
            $item['online'] = $online;
        }

        if ($wgManager !== null) {
            $item['subscribe'] = self::subscribeLinks($account, $wgManager);
        }

        return $item;
    }

    public static function detail(
        array $account,
        WireGuardManager $wgManager,
    ): array {
        $item = self::summary(
            $account,
            $wgManager->getAccountOnlineStatus($account),
            $wgManager,
        );

        $item['volume_percent']    = Helpers::volumePercent($account);
        $item['days_until_expiry'] = Helpers::daysUntilExpiryForAccount($account);
        $item['expiry_pending']    = Helpers::isFirstConnectExpiry($account) && empty($account['first_connected_at']);
        $item['last_wg_rx_bytes']  = $account['last_wg_rx_bytes'] !== null ? (int) $account['last_wg_rx_bytes'] : null;
        $item['last_wg_tx_bytes']  = $account['last_wg_tx_bytes'] !== null ? (int) $account['last_wg_tx_bytes'] : null;

        return $item;
    }

    /** @return array<string, mixed> */
    public static function subscribeLinks(
        array $account,
        WireGuardManager $wgManager,
    ): array {
        return [
            'token'     => (string) ($account['subscribe_token'] ?? ''),
            'panel_url' => $wgManager->buildSubscribePanelUrl($account),
        ];
    }

    /** @param array<string, mixed> $body */
    public static function parseInput(array $body, bool $forCreate = false): array
    {
        $data = [];

        if ($forCreate || array_key_exists('name', $body)) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                Http::error('Field "name" is required.', 422);
            }
            $data['name'] = $name;
        }

        if ($forCreate || array_key_exists('speed_limit_kbps', $body)) {
            $data['speed_limit_kbps'] = max(0, (int) ($body['speed_limit_kbps'] ?? 0));
        }

        if ($forCreate || array_key_exists('volume_limit_bytes', $body) || array_key_exists('volume_limit', $body)) {
            if (array_key_exists('volume_limit_bytes', $body)) {
                $data['volume_limit_bytes'] = max(0, (int) $body['volume_limit_bytes']);
            } else {
                $volumeInput = trim((string) ($body['volume_limit'] ?? '0'));
                $data['volume_limit_bytes'] = ($volumeInput === '' || $volumeInput === '0')
                    ? 0
                    : Helpers::parseSize($volumeInput);
            }
        }

        if ($forCreate || array_key_exists('expiry_mode', $body)) {
            $mode = (string) ($body['expiry_mode'] ?? 'fixed');
            if (!in_array($mode, ['fixed', 'first_connect'], true)) {
                Http::error('Invalid expiry_mode.', 422);
            }
            $data['expiry_mode'] = $mode;
        }

        if ($forCreate || array_key_exists('expiry_duration_days', $body)) {
            $data['expiry_duration_days'] = max(0, (int) ($body['expiry_duration_days'] ?? 0));
        }

        if ($forCreate || array_key_exists('expires_at', $body)) {
            $expires = $body['expires_at'] ?? null;
            $data['expires_at'] = ($expires === null || $expires === '') ? null : (string) $expires;
        }

        if (!$forCreate && array_key_exists('is_active', $body)) {
            $data['is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        return $data;
    }
}
