<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$account = $wgManager->getAccount($id);

if ($account === null) {
    flash('danger', 'اکانت یافت نشد.');
    redirect('/');
}

$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $account['name']) . '.conf';
$config = $wgManager->buildClientConfig($account);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($config));
echo $config;
exit;
