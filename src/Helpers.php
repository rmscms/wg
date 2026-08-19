<?php

declare(strict_types=1);

namespace WgPanel;

final class Helpers
{
    public static function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, (float) $bytes);

        if ($bytes === 0.0) {
            return '0 B';
        }

        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }

    public static function parseSize(string $value): int
    {
        $value = trim(strtoupper($value));

        if ($value === '' || $value === '0') {
            return 0;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)?$/', $value, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid size format.');
        }

        $number = (float) $matches[1];
        $unit = $matches[2] ?? 'B';

        $multipliers = [
            'B' => 1,
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
        ];

        return (int) round($number * $multipliers[$unit]);
    }

    public static function statusBadge(array $account): array
    {
        if ((int) $account['is_active'] !== 1) {
            return ['label' => 'غیرفعال', 'class' => 'badge-muted'];
        }

        if (self::isFirstConnectExpiry($account) && empty($account['first_connected_at'])) {
            $days = (int) ($account['expiry_duration_days'] ?? 0);
            if ($days > 0) {
                return ['label' => 'در انتظار اتصال', 'class' => 'badge-info'];
            }
        }

        if (!empty($account['expires_at']) && strtotime((string) $account['expires_at']) <= time()) {
            return ['label' => 'منقضی', 'class' => 'badge-danger'];
        }

        $limit = (int) $account['volume_limit_bytes'];
        $used = (int) $account['volume_used_bytes'];

        if ($limit > 0 && $used >= $limit) {
            return ['label' => 'حجم تمام', 'class' => 'badge-warning'];
        }

        return ['label' => 'فعال', 'class' => 'badge-success'];
    }

    public static function isFirstConnectExpiry(array $account): bool
    {
        return ($account['expiry_mode'] ?? 'fixed') === 'first_connect';
    }

    public static function formatExpiryDisplay(array $account): string
    {
        if (!self::isFirstConnectExpiry($account)) {
            return self::formatExpiry($account['expires_at'] ?? null);
        }

        $days = (int) ($account['expiry_duration_days'] ?? 0);
        if ($days <= 0) {
            return 'بدون انقضا';
        }

        if (!empty($account['first_connected_at']) && !empty($account['expires_at'])) {
            return self::formatDateTime((string) $account['expires_at']);
        }

        return $days . ' روز پس از اولین اتصال';
    }

    public static function daysUntilExpiryForAccount(array $account): ?int
    {
        if (self::isFirstConnectExpiry($account) && empty($account['first_connected_at'])) {
            return null;
        }

        return self::daysUntilExpiry($account['expires_at'] ?? null);
    }

    public static function formatSpeed(int $kbps): string
    {
        if ($kbps <= 0) {
            return 'نامحدود';
        }

        if ($kbps >= 1000 && $kbps % 1000 === 0) {
            return ($kbps / 1000) . ' Mbps';
        }

        if ($kbps >= 1000) {
            return round($kbps / 1000, 1) . ' Mbps';
        }

        return $kbps . ' Kbps';
    }

    public static function volumePercent(array $account): ?float
    {
        $limit = (int) $account['volume_limit_bytes'];

        if ($limit <= 0) {
            return null;
        }

        $used = (int) $account['volume_used_bytes'];

        return min(100.0, round(($used / $limit) * 100, 1));
    }

    public static function daysUntilExpiry(?string $expiresAt): ?int
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        $timestamp = strtotime($expiresAt);

        if ($timestamp === false) {
            return null;
        }

        return (int) floor(($timestamp - time()) / 86400);
    }

    public static function formatExpiry(?string $expiresAt): string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return 'بدون انقضا';
        }

        return self::formatDateTime($expiresAt);
    }

    public static function formatDateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }

        return Jalali::formatDateTime($value);
    }

    public static function formatDate(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }

        return Jalali::formatDate($value);
    }

    public static function changeNumberToEn(string $value): string
    {
        return Jalali::changeNumberToEn($value);
    }

    /** Wrap Latin/numeric text for correct display inside RTL layouts. */
    public static function ltrIsolate(string $text): string
    {
        return '<span class="bidi-ltr" dir="ltr">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function formatExpiryDisplayHtml(array $account): string
    {
        $text = self::formatExpiryDisplay($account);

        if (preg_match('/^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}/', $text)) {
            return self::ltrIsolate($text);
        }

        if (preg_match('/^(\d+)\s+(.+)$/u', $text, $matches)) {
            return self::ltrIsolate($matches[1]) . ' ' . htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public static function formatSpeedHtml(int $kbps): string
    {
        if ($kbps <= 0) {
            return htmlspecialchars('نامحدود', ENT_QUOTES, 'UTF-8');
        }

        return self::ltrIsolate(self::formatSpeed($kbps));
    }

    public static function formatSpeedHintHtml(int $kbps): string
    {
        if ($kbps <= 0) {
            return '';
        }

        return self::ltrIsolate(number_format($kbps) . ' Kbps');
    }

    public static function formatVolumeRangeHtml(int $used, int $limit): string
    {
        if ($limit > 0) {
            return self::ltrIsolate(self::formatBytes($used) . ' / ' . self::formatBytes($limit));
        }

        return self::ltrIsolate(self::formatBytes($used)) . ' / ' . htmlspecialchars('نامحدود', ENT_QUOTES, 'UTF-8');
    }

    public static function formatDaysLeftHintHtml(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return '';
        }

        if ($daysLeft >= 0) {
            return self::ltrIsolate((string) $daysLeft) . ' روز باقی‌مانده';
        }

        return htmlspecialchars('منقضی شده', ENT_QUOTES, 'UTF-8');
    }

    public static function formatVolumePercentHtml(?float $percent): string
    {
        if ($percent === null) {
            return '';
        }

        $label = fmod($percent, 1.0) === 0.0
            ? (string) (int) $percent
            : rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');

        return self::ltrIsolate($label . '٪') . ' مصرف شده';
    }

    public static function formatRelativeTime(?int $secondsAgo): string
    {
        if ($secondsAgo === null) {
            return 'هرگز';
        }

        if ($secondsAgo < 5) {
            return 'همین الان';
        }

        if ($secondsAgo < 60) {
            return $secondsAgo . ' ثانیه پیش';
        }

        if ($secondsAgo < 3600) {
            return (int) floor($secondsAgo / 60) . ' دقیقه پیش';
        }

        if ($secondsAgo < 86400) {
            return (int) floor($secondsAgo / 3600) . ' ساعت پیش';
        }

        return (int) floor($secondsAgo / 86400) . ' روز پیش';
    }
}
