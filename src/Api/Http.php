<?php

declare(strict_types=1);

namespace WgPanel\Api;

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (self::wantsPretty()) {
            $flags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($data, $flags);
        exit;
    }

    private static function wantsPretty(): bool
    {
        $val = $_GET['pretty'] ?? $_GET['format'] ?? '';

        return $val === '1' || $val === 'true' || $val === 'pretty';
    }

    public static function error(string $message, int $status = 400, ?string $code = null): never
    {
        self::json([
            'error' => [
                'code' => $code ?? self::defaultErrorCode($status),
                'message' => $message,
            ],
        ], $status);
    }

    public static function ok(mixed $data, int $status = 200, array $meta = []): never
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    /** @return array<string, mixed> */
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::error('Invalid JSON body.', 400, 'invalid_json');
        }

        return $decoded;
    }

    private static function defaultErrorCode(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            422 => 'validation_error',
            500 => 'server_error',
            default => 'bad_request',
        };
    }
}
