<?php

declare(strict_types=1);

require __DIR__ . '/subscribe-bootstrap.php';

$account = resolveSubscribeAccount($wgManager);

if ($account === null) {
    http_response_code(404);
    exit('Not found.');
}

try {
    $config = $wgManager->buildClientConfig($account);
    $png = WgPanel\QrGenerator::png($config);
} catch (Throwable $e) {
    http_response_code(500);
    exit('QR generation failed.');
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Length: ' . strlen($png));
echo $png;
exit;
