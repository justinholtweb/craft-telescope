<?php

declare(strict_types=1);

namespace justinholtweb\telescope\helpers;

/**
 * Presentation helpers for analytics figures.
 *
 * GA4 hands back raw seconds and 0–1 ratios; the control panel wants "2m 14s"
 * and "43.2%". Keeping the conversions here means the field template, the
 * widget, the print view and the console command all read the same.
 */
final class Format
{
    /**
     * Seconds → a compact human duration, e.g. `0s`, `47s`, `2m 14s`, `1h 03m`.
     */
    public static function duration(float $seconds): string
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            return '0s';
        }

        $seconds = (int)round($seconds);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            $remainder = $seconds % 60;

            return "{$minutes}m {$remainder}s";
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%dh %02dm', $hours, $minutes);
    }

    /**
     * A 0–1 ratio → a percentage string. Values above 1 are assumed to already
     * be percentages, which is how GA4 reports a couple of its rate metrics.
     */
    public static function percent(float $ratio, int $decimals = 1): string
    {
        if (!is_finite($ratio)) {
            return '0%';
        }

        $value = $ratio > 1 ? $ratio : $ratio * 100;

        return number_format($value, $decimals) . '%';
    }

    /**
     * Thousands-separated integer.
     */
    public static function number(float|int $value): string
    {
        return number_format((float)$value, 0);
    }

    /**
     * Large numbers shortened for cramped card layouts: `9,999`, `12.3K`, `1.4M`.
     */
    public static function compact(float|int $value): string
    {
        $value = (float)$value;
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        if ($abs < 10000) {
            return $sign . number_format($abs, 0);
        }

        if ($abs < 1000000) {
            return $sign . rtrim(rtrim(number_format($abs / 1000, 1, '.', ''), '0'), '.') . 'K';
        }

        if ($abs < 1000000000) {
            return $sign . rtrim(rtrim(number_format($abs / 1000000, 1, '.', ''), '0'), '.') . 'M';
        }

        return $sign . rtrim(rtrim(number_format($abs / 1000000000, 1, '.', ''), '0'), '.') . 'B';
    }

    /**
     * GA4's `YYYYMMDD` date dimension → `YYYY-MM-DD`. Anything unexpected is
     * passed straight through so a format change upstream shows as odd labels
     * rather than blank ones.
     */
    public static function date(string $ga4Date): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ga4Date, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $ga4Date;
    }

    /**
     * The percentage change between two values, or null when there is no
     * meaningful baseline to compare against.
     */
    public static function change(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? null : 0.0;
        }

        return (($current - $previous) / $previous) * 100;
    }
}
