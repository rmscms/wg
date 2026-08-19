<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function accountModalPayload(array $account, WgPanel\WireGuardManager $wgManager): array
{
    $badge = WgPanel\Helpers::statusBadge($account);
    $online = $wgManager->getAccountOnlineStatus($account);
    $volumePercent = WgPanel\Helpers::volumePercent($account);
    $volumeUsed = WgPanel\Helpers::formatBytes((int) $account['volume_used_bytes']);
    $volumeLimit = (int) $account['volume_limit_bytes'] > 0
        ? WgPanel\Helpers::formatBytes((int) $account['volume_limit_bytes'])
        : 'نامحدود';
    $expiresAt = $account['expires_at'] ?? null;
    $configError = null;
    $configText = null;

    try {
        $configText = $wgManager->buildClientConfig($account);
    } catch (Throwable $e) {
        $configError = $e->getMessage();
    }

    $publicKey = (string) $account['public_key'];

    return [
        'id' => (int) $account['id'],
        'name' => (string) $account['name'],
        'ip_address' => (string) $account['ip_address'],
        'public_key' => $publicKey,
        'public_key_short' => substr($publicKey, 0, 12) . '…',
        'badge' => $badge,
        'online' => $online,
        'speed_limit_kbps' => (int) $account['speed_limit_kbps'],
        'speed_human' => (int) $account['speed_limit_kbps'] > 0
            ? WgPanel\Helpers::formatSpeed((int) $account['speed_limit_kbps'])
            : 'نامحدود',
        'volume_used_human' => $volumeUsed,
        'volume_limit_human' => $volumeLimit,
        'volume_limit_input' => (int) $account['volume_limit_bytes'] > 0
            ? WgPanel\Helpers::formatBytes((int) $account['volume_limit_bytes'])
            : '0',
        'volume_percent' => $volumePercent,
        'expiry_mode' => (string) ($account['expiry_mode'] ?? 'fixed'),
        'expires_at' => $expiresAt,
        'expires_at_local' => $expiresAt ? WgPanel\Jalali::formatInputDateTime((string) $expiresAt) : '',
        'expiry_display' => WgPanel\Helpers::formatExpiryDisplay($account),
        'expiry_duration_days' => (int) ($account['expiry_duration_days'] ?? 30) ?: 30,
        'first_connected_at' => $account['first_connected_at'] ?? null,
        'first_connected_display' => !empty($account['first_connected_at'])
            ? WgPanel\Helpers::formatDateTime((string) $account['first_connected_at'])
            : 'هنوز ثبت نشده',
        'is_active' => (int) $account['is_active'] === 1,
        'subscribe_panel_url' => $wgManager->buildSubscribePanelUrl($account),
        'qr_config' => '/qr.php?id=' . (int) $account['id'],
        'qr_panel' => '/panel-qr.php?id=' . (int) $account['id'],
        'download_url' => '/download.php?id=' . (int) $account['id'],
        'config_text' => $configText,
        'config_error' => $configError,
    ];
}

function sendAccountModalJson(WgPanel\WireGuardManager $wgManager): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int) ($_GET['id'] ?? 0);
    $account = $wgManager->getAccount($id);

    if ($account === null) {
        http_response_code(404);
        echo json_encode(['error' => 'اکانت یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'account' => accountModalPayload($account, $wgManager)], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendAccountModalSaveJson(WgPanel\WireGuardManager $wgManager): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $account = $wgManager->getAccount($id);

    if ($account === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'اکانت یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $volumeInput = trim((string) ($_POST['volume_limit'] ?? '0'));
        $volumeBytes = $volumeInput === '0' || $volumeInput === ''
            ? 0
            : WgPanel\Helpers::parseSize($volumeInput);

        $updated = $wgManager->updateAccount($id, [
            'name' => $_POST['name'] ?? $account['name'],
            'speed_limit_kbps' => (int) ($_POST['speed_limit_kbps'] ?? 0),
            'volume_limit_bytes' => $volumeBytes,
            'expiry_mode' => $_POST['expiry_mode'] ?? ($account['expiry_mode'] ?? 'fixed'),
            'expires_at' => $_POST['expires_at'] ?? null,
            'expiry_duration_days' => (int) ($_POST['expiry_duration_days'] ?? ($account['expiry_duration_days'] ?? 0)),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);

        $listState = dashboardStateFromRequest();
        $list = dashboardLoadList($wgManager, $listState);

        echo json_encode([
            'ok' => true,
            'message' => 'اکانت به‌روزرسانی شد.',
            'account' => accountModalPayload($updated, $wgManager),
            'list' => dashboardListAjaxPayload($list, $wgManager),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
