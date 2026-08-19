<?php

declare(strict_types=1);

namespace WgPanel;

final class Jalali
{
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
}
