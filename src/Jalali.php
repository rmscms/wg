<?php

declare(strict_types=1);

namespace WgPanel;

final class Jalali
{
    /**
     * Convert Persian/Arabic digits to English — same as \RMS\Helper\changeNumberToEn.
     */
    public static function changeNumberToEn(string $string): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];

        return strtr($string, $map);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + $gDaysInMonth[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
        $salA = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;

        for ($i = 1; $i <= 12 && $gd > $salA[$i]; $i++) {
            $gd -= $salA[$i];
            $gm = $i;
        }

        $gm++;

        return [$gy, $gm, $gd];
    }

    public static function format(?string $gregorian, string $pattern = 'Y/m/d H:i'): string
    {
        if ($gregorian === null || trim($gregorian) === '') {
            return '';
        }

        $timestamp = strtotime($gregorian);

        if ($timestamp === false) {
            return trim($gregorian);
        }

        [$jy, $jm, $jd] = self::toJalali(
            (int) date('Y', $timestamp),
            (int) date('n', $timestamp),
            (int) date('j', $timestamp),
        );

        $map = [
            'Y' => sprintf('%04d', $jy),
            'm' => sprintf('%02d', $jm),
            'd' => sprintf('%02d', $jd),
            'H' => date('H', $timestamp),
            'i' => date('i', $timestamp),
            's' => date('s', $timestamp),
        ];

        return str_replace(array_keys($map), array_values($map), $pattern);
    }

    public static function formatDate(?string $gregorian): string
    {
        $formatted = self::format($gregorian, 'Y/m/d');

        return $formatted !== '' ? $formatted : '—';
    }

    public static function formatDateTime(?string $gregorian): string
    {
        $formatted = self::format($gregorian, 'Y/m/d H:i');

        return $formatted !== '' ? $formatted : '—';
    }

    public static function formatInputDate(?string $gregorian): string
    {
        return self::format($gregorian, 'Y/m/d');
    }

    public static function formatInputDateTime(?string $gregorian): string
    {
        return self::format($gregorian, 'Y/m/d H:i');
    }

    /** Parse Shamsi or Gregorian date to Gregorian Y-m-d. */
    public static function parseDate(?string $value): ?string
    {
        $parsed = self::parse($value);

        return $parsed['date'] ?? null;
    }

    /** Parse Shamsi or Gregorian datetime to Gregorian Y-m-d H:i:s. */
    public static function parseDateTime(?string $value): ?string
    {
        $parsed = self::parse($value);

        return $parsed['datetime'] ?? null;
    }

    /**
     * @return array{date: string, datetime: string}|null
     */
    private static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(self::changeNumberToEn($value));
        if ($clean === '') {
            return null;
        }

        $clean = str_replace('T', ' ', $clean);

        if (!preg_match(
            '/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/',
            $clean,
            $matches
        )) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = isset($matches[4]) ? (int) $matches[4] : 0;
        $minute = isset($matches[5]) ? (int) $matches[5] : 0;
        $second = isset($matches[6]) ? (int) $matches[6] : 0;

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        if ($year >= 1600) {
            if (!checkdate($month, $day, $year)) {
                return null;
            }

            return [
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'datetime' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            ];
        }

        if ($year < 1200 || $year > 1599 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        [$gy, $gm, $gd] = self::toGregorian($year, $month, $day);
        [$jy, $jm, $jd] = self::toJalali($gy, $gm, $gd);

        if ($jy !== $year || $jm !== $month || $jd !== $day || !checkdate($gm, $gd, $gy)) {
            return null;
        }

        return [
            'date' => sprintf('%04d-%02d-%02d', $gy, $gm, $gd),
            'datetime' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $gy, $gm, $gd, $hour, $minute, $second),
        ];
    }
}
