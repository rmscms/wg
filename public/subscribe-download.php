<?php

declare(strict_types=1);

require __DIR__ . '/subscribe-bootstrap.php';

$account = resolveSubscribeAccount($wgManager);

if ($account === null) {
    http_response_code(404);
    exit('Not found.');
}

$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $account['name']) . '.conf';
$config = $wgManager->buildClientConfig($account);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($config));
echo $config;
exit;
