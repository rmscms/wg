<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$filename = basename((string) ($_GET['file'] ?? ''));

try {
    $backupManager = backupManager();
    $path = $backupManager->resolveBackupPath($filename);
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');

readfile($path);
exit;
