<?php

declare(strict_types=1);

namespace justinholtweb\telescope\ga4;

use InvalidArgumentException;

/**
 * A date range for a GA4 report.
 *
 * GA4's Data API accepts three flavours of date string — `NdaysAgo`, the
 * keywords `today`/`yesterday`, and absolute `YYYY-MM-DD` dates — so this class
 * validates and carries them rather than converting everything to absolute
 * dates (which would make the cache key change every day for no reason).
 */
final class Period
{
    /**
     * The earliest date the GA4 Data API accepts.
     *
     * @see https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/DateRange
     */
    public const EARLIEST_DATE = '2015-08-14';

    /**
     * Preset periods offered in the control panel, keyed by handle.
     *
     * @var array<string, array{label: string, startDate: string, endDate: string}>
     */
    private const PRESETS = [
        'last7days' => ['label' => 'Last 7 days', 'startDate' => '7daysAgo', 'endDate' => 'today'],
        'last14days' => ['label' => 'Last 14 days', 'startDate' => '14daysAgo', 'endDate' => 'today'],
        'last28days' => ['label' => 'Last 28 days', 'startDate' => '28daysAgo', 'endDate' => 'today'],
        'last30days' => ['label' => 'Last 30 days', 'startDate' => '30daysAgo', 'endDate' => 'today'],
        'last90days' => ['label' => 'Last 90 days', 'startDate' => '90daysAgo', 'endDate' => 'today'],
        'last365days' => ['label' => 'Last 365 days', 'startDate' => '365daysAgo', 'endDate' => 'today'],
        'allTime' => ['label' => 'All time', 'startDate' => self::EARLIEST_DATE, 'endDate' => 'today'],
    ];

    private function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $label,
        public readonly ?string $handle = null,
    ) {
    }

    /**
     * Build a period from two GA4 date strings.
     *
     * @throws InvalidArgumentException if either date is not a valid GA4 date expression
     */
    public static function create(string $startDate, string $endDate = 'today', ?string $label = null): self
    {
        self::assertValidDate($startDate, 'start');
        self::assertValidDate($endDate, 'end');

        return new self($startDate, $endDate, $label ?? "{$startDate} – {$endDate}");
    }

    /**
     * Build a period from a preset handle, e.g. `last28days`.
     *
     * @throws InvalidArgumentException if the handle is not a known preset
     */
    public static function preset(string $handle): self
    {
        if (!isset(self::PRESETS[$handle])) {
            throw new InvalidArgumentException("Unknown period preset: {$handle}");
        }

        $preset = self::PRESETS[$handle];

        return new self($preset['startDate'], $preset['endDate'], $preset['label'], $handle);
    }

    /**
     * The period used when nothing else resolves.
     */
    public const DEFAULT_PRESET = 'last28days';

    /**
     * Resolve a value that may be a preset handle or a raw GA4 start date.
     *
     * Both the value and the fallback are treated as untrusted — either can
     * come from settings a site owner typed by hand — so an unusable value
     * degrades to the default period rather than throwing mid-render.
     */
    public static function resolve(?string $value, ?string $fallback = null): self
    {
        foreach ([$value, $fallback] as $candidate) {
            $candidate = $candidate !== null ? trim($candidate) : '';

            if ($candidate === '') {
                continue;
            }

            if (isset(self::PRESETS[$candidate])) {
                return self::preset($candidate);
            }

            try {
                return self::create($candidate);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return self::preset(self::DEFAULT_PRESET);
    }

    /**
     * All preset handles mapped to their human labels, for CP dropdowns.
     *
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return array_map(static fn(array $preset): string => $preset['label'], self::PRESETS);
    }

    public static function isValidDate(string $date): bool
    {
        if ($date === 'today' || $date === 'yesterday') {
            return true;
        }

        if (preg_match('/^(\d+)daysAgo$/', $date) === 1) {
            return true;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1) {
            return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
        }

        return false;
    }

    /**
     * The date range in the shape the Data API expects.
     *
     * @return array{startDate: string, endDate: string}
     */
    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ];
    }

    /**
     * A stable, filesystem/cache-safe identifier for this range.
     */
    public function cacheKey(): string
    {
        return $this->handle ?? "{$this->startDate}_{$this->endDate}";
    }

    private static function assertValidDate(string $date, string $which): void
    {
        if (!self::isValidDate($date)) {
            throw new InvalidArgumentException("Invalid GA4 {$which} date: {$date}");
        }
    }
}
