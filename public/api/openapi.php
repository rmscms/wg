<?php

declare(strict_types=1);

$specPath = __DIR__ . '/openapi.yaml';

if (!is_file($specPath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'OpenAPI spec not found.']));
}

$wantsJson = (
    isset($_GET['format']) && $_GET['format'] === 'json'
    || isset($_GET['api-docs.json'])           // Scalar ?api-docs.json pattern
    || str_ends_with($_SERVER['REQUEST_URI'] ?? '', '.json')
);

if ($wantsJson) {
    $parsed = null;

    // Method 1: PHP yaml extension
    if (function_exists('yaml_parse_file')) {
        $parsed = yaml_parse_file($specPath);
    }

    // Method 2: symfony/yaml (if installed via composer)
    if ($parsed === null && class_exists(\Symfony\Component\Yaml\Yaml::class)) {
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($specPath);
    }

    // Method 3: shell python3 fallback (available on most Linux servers)
    if ($parsed === null) {
        $escaped = escapeshellarg($specPath);
        $json = shell_exec("python3 -c \"import yaml,json,sys; print(json.dumps(yaml.safe_load(open($escaped))))\" 2>/dev/null");
        if ($json !== null && $json !== '') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            header('Access-Control-Allow-Origin: *');
            echo trim($json);
            exit;
        }
    }

    if ($parsed !== null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // Fallback: serve YAML (Scalar can parse it too)
}

header('Content-Type: application/yaml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');
readfile($specPath);
exit;
