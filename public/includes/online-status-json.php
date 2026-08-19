<?php

declare(strict_types=1);

function sendOnlineStatusJson(WgPanel\WireGuardManager $wgManager): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Authentication required.',
            'hint' => 'Open the admin dashboard in this browser and login first.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        try {
            $wgManager->processFirstConnectionExpiry();
        } catch (Throwable) {
            // Keep live status working even if expiry sync fails.
        }

        // If the caller passes ?ids=1,2,3 only fetch those accounts (dashboard optimisation).
        // Fallback to all accounts for backward compatibility.
        $idsParam = trim((string) ($_GET['ids'] ?? ''));
        if ($idsParam !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
            $statuses = $wgManager->getOnlineStatusesByIds($ids);
        } else {
            $statuses = $wgManager->getAllOnlineStatuses();
        }

        echo json_encode([
            'updated_at' => date('c'),
            'wg_ok' => $wgManager->isWireGuardHandshakesAvailable(),
            'online_timeout' => $wgManager->getOnlineTimeoutSeconds(),
            'accounts' => $statuses,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
