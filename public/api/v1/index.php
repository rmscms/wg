<?php

declare(strict_types=1);

function apiFatal(string $message, int $status = 500, ?string $code = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    echo json_encode([
        'error' => [
            'code' => $code ?? 'server_error',
            'message' => $message,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

$publicDir = dirname(__DIR__, 2);
$rootDir = dirname($publicDir);
$bootstrap = $publicDir . '/bootstrap.php';

if (!is_file($bootstrap)) {
    apiFatal('Bootstrap not found at ' . $bootstrap, 500);
}

try {
    require $bootstrap;
} catch (Throwable $e) {
    apiFatal('Bootstrap failed: ' . $e->getMessage(), 500);
}

$apiDir = $rootDir . '/src/Api';
if (!is_dir($apiDir)) {
    apiFatal('Missing ' . $apiDir . '. Upload src/Api/ to the server.', 500);
}

foreach (['Http.php', 'ApiAuth.php', 'AccountResource.php', 'Router.php'] as $apiFile) {
    if (!is_file($apiDir . '/' . $apiFile)) {
        apiFatal('Missing API file: src/Api/' . $apiFile, 500);
    }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$route = trim((string) ($_GET['route'] ?? ''), '/');
$segments = $route === '' ? [] : explode('/', $route);

try {
    WgPanel\Api\Router::dispatch($method, $segments, $config, $wgManager, $db);
} catch (Throwable $e) {
    apiFatal($e->getMessage(), 500, 'server_error');
}
