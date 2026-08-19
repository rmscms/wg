<?php

declare(strict_types=1);

namespace WgPanel\Api;

final class ApiAuth
{
    public static function requireAdmin(array $config): void
    {
        if (self::isAuthenticated($config)) {
            return;
        }

        Http::error('Authentication required. Use Authorization: Bearer {api_token} or admin session.', 401);
    }

    public static function isAuthenticated(array $config): bool
    {
        if (!empty($_SESSION['wg_admin'])) {
            return true;
        }

        $api = $config['api'] ?? [];
        if (empty($api['enabled'])) {
            return false;
        }

        $configuredToken = trim((string) ($api['token'] ?? ''));
        if ($configuredToken === '') {
            return false;
        }

        $provided = self::extractBearerToken();
        if ($provided === null) {
            return false;
        }

        return hash_equals($configuredToken, $provided);
    }

    public static function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
