<?php

declare(strict_types=1);

namespace WgPanel;

final class ApiAllowedIp
{
    /**
     * @param  list<string>|string|null  $allowed
     */
    public static function allows(array|string|null $allowed, string $clientIp): bool
    {
        $rules = self::normalizeList($allowed);

        if ($rules === []) {
            return true;
        }

        $clientIp = self::normalize($clientIp);

        foreach ($rules as $rule) {
            if (str_contains($rule, '/')) {
                if (self::inCidr($clientIp, $rule)) {
                    return true;
                }
                continue;
            }

            if (self::normalize($rule) === $clientIp) {
                return true;
            }
        }

        return false;
    }

    public static function clientIp(): string
    {
        foreach ([
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
        ] as $candidate) {
            $ip = trim((string) $candidate);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return self::normalize($ip);
            }
        }

        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return self::normalize($first);
            }
        }

        return self::normalize((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function isValidRule(string $rule): bool
    {
        $rule = trim($rule);

        if ($rule === '') {
            return false;
        }

        if (!str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        [$ip, $bits] = array_pad(explode('/', $rule, 2), 2, '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || !ctype_digit((string) $bits)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
        $prefix = (int) $bits;

        return $prefix >= 0 && $prefix <= $max;
    }

    /**
     * @param  list<string>|string|null  $raw
     * @return list<string>
     */
    public static function parseRules(array|string|null $raw): array
    {
        $ips = self::normalizeList($raw);

        foreach ($ips as $ip) {
            if (!self::isValidRule($ip)) {
                throw new \RuntimeException('IP نامعتبر: ' . $ip);
            }
        }

        return $ips;
    }

    /**
     * @param  list<string>|string|null  $allowed
     * @return list<string>
     */
    public static function normalizeList(array|string|null $allowed): array
    {
        if ($allowed === null || $allowed === '' || $allowed === []) {
            return [];
        }

        if (is_string($allowed)) {
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded)
                ? $decoded
                : preg_split('/[,;\s]+/', $allowed);
        }

        if (!is_array($allowed)) {
            return [];
        }

        $out = [];
        foreach ($allowed as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    public static function normalize(string $ip): string
    {
        $ip = trim($ip);

        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);
        }

        $packed = @inet_pton($ip);
        if ($packed === false || $packed === '') {
            return $ip;
        }

        $unpacked = inet_ntop($packed);

        return is_string($unpacked) ? $unpacked : $ip;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        if ($subnet === '' || !ctype_digit((string) $bits)) {
            return false;
        }

        $ipBin = @inet_pton(self::normalize($ip));
        $subnetBin = @inet_pton(self::normalize($subnet));
        if ($ipBin === false || $subnetBin === false || strlen((string) $ipBin) !== strlen((string) $subnetBin)) {
            return false;
        }

        $prefix = (int) $bits;
        $len = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $len) {
            return false;
        }

        $maskBytes = intdiv($prefix, 8);
        $remain = $prefix % 8;
        if (substr($ipBin, 0, $maskBytes) !== substr($subnetBin, 0, $maskBytes)) {
            return false;
        }

        if ($remain === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remain)) & 0xFF;

        return (ord($ipBin[$maskBytes]) & $mask) === (ord($subnetBin[$maskBytes]) & $mask);
    }
}
