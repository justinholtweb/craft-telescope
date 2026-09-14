<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

use justinholtweb\telescope\helpers\Format;

/**
 * The whole property at a glance: the numbers behind the control panel
 * dashboard.
 *
 * The page-level counterpart is {@see PageReport}. This one is not filtered to
 * a path, so its breakdowns answer "where is my traffic coming from" rather
 * than "who read this entry".
 */
final class SiteReport
{
    /**
     * @param list<array{date: string, views: int, users: int}> $timeline
     * @param list<array{label: string, sessions: int, users: int, share: float}> $sources
     * @param list<array{label: string, users: int, share: float}> $countries
     * @param list<array{label: string, sessions: int, share: float}> $devices
     * @param list<array{label: string, sessions: int, share: float}> $browsers
     * @param list<array{path: string, title: string, views: int, users: int}> $topPages
     * @param list<string> $errors
     */
    public function __construct(
        public readonly string $periodLabel = '',
        public readonly PageMetrics $totals = new PageMetrics(),
        public readonly array $timeline = [],
        public readonly array $sources = [],
        public readonly array $countries = [],
        public readonly array $devices = [],
        public readonly array $browsers = [],
        public readonly array $topPages = [],
        public readonly array $errors = [],
    ) {
    }

    public static function empty(string $periodLabel = ''): self
    {
        return new self(periodLabel: $periodLabel);
    }

    /**
     * @param list<string> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self(
            $this->periodLabel,
            $this->totals,
            $this->timeline,
            $this->sources,
            $this->countries,
            $this->devices,
            $this->browsers,
            $this->topPages,
            [...$this->errors, ...$errors],
        );
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasData(): bool
    {
        return $this->totals->hasData() || $this->topPages !== [];
    }

    /**
     * The headline stat tiles.
     *
     * A wider set than the page report's: the dashboard has the room, and new
     * visitors is a number that only means something property-wide.
     *
     * @return list<array{key: string, label: string, value: string, raw: int|float}>
     */
    public function cards(): array
    {
        $totals = $this->totals;

        return [
            ['key' => 'views', 'label' => 'Page views', 'value' => Format::number($totals->views), 'raw' => $totals->views],
            ['key' => 'users', 'label' => 'Visitors', 'value' => Format::number($totals->users), 'raw' => $totals->users],
            ['key' => 'newUsers', 'label' => 'New visitors', 'value' => Format::number($totals->newUsers), 'raw' => $totals->newUsers],
            ['key' => 'sessions', 'label' => 'Sessions', 'value' => Format::number($totals->sessions), 'raw' => $totals->sessions],
            ['key' => 'duration', 'label' => 'Avg. session', 'value' => Format::duration($totals->averageSessionDuration), 'raw' => $totals->averageSessionDuration],
            ['key' => 'engagement', 'label' => 'Engagement rate', 'value' => Format::percent($totals->engagementRate), 'raw' => $totals->engagementRate],
            ['key' => 'bounce', 'label' => 'Bounce rate', 'value' => Format::percent($totals->bounceRate), 'raw' => $totals->bounceRate],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'periodLabel' => $this->periodLabel,
            'totals' => $this->totals->toArray(),
            'timeline' => $this->timeline,
            'sources' => $this->sources,
            'countries' => $this->countries,
            'devices' => $this->devices,
            'browsers' => $this->browsers,
            'topPages' => $this->topPages,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            periodLabel: (string)($data['periodLabel'] ?? ''),
            totals: PageMetrics::fromArray(is_array($data['totals'] ?? null) ? $data['totals'] : []),
            timeline: self::rows($data, 'timeline'),
            sources: self::rows($data, 'sources'),
            countries: self::rows($data, 'countries'),
            devices: self::rows($data, 'devices'),
            browsers: self::rows($data, 'browsers'),
            topPages: self::rows($data, 'topPages'),
            errors: array_values(array_map(
                strval(...),
                is_array($data['errors'] ?? null) ? $data['errors'] : [],
            )),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? null;

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }
}
