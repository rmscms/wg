<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$publicDir = dirname(__DIR__, 2);
$rootDir = dirname($publicDir);
$apiDir = $rootDir . '/src/Api';

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'paths' => [
        'public' => $publicDir,
        'root' => $rootDir,
        'bootstrap' => is_file($publicDir . '/bootstrap.php'),
        'api_dir' => is_dir($apiDir),
    ],
    'api_files' => is_dir($apiDir)
        ? array_values(array_map('basename', glob($apiDir . '/*.php') ?: []))
        : [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
