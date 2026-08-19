<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$account = $wgManager->getAccount($id);

if ($account === null) {
    http_response_code(404);
    exit('Account not found.');
}

try {
    $url = $wgManager->buildSubscribePanelUrl($account);
    $png = WgPanel\QrGenerator::png($url);
} catch (Throwable $e) {
    http_response_code(500);
    exit('QR generation failed.');
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Length: ' . strlen($png));
echo $png;
exit;
