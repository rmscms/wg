<?php

declare(strict_types=1);

require dirname(__DIR__) . '/subscribe-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$account = resolveSubscribeAccount($wgManager);

if ($account === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $wgManager->processFirstConnectionExpiry();
    $fresh = $wgManager->getAccount((int) $account['id']) ?? $account;

    echo json_encode(array_merge(
        ['updated_at' => date('c')],
        $wgManager->getSubscribeLiveData($fresh)
    ), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
