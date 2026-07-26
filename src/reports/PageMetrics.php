<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

use justinholtweb\telescope\ga4\ReportResponse;
use justinholtweb\telescope\helpers\Format;

/**
 * The headline numbers for a single page.
 */
final class PageMetrics
{
    /**
     * The metrics requested for the overview, in the order they are displayed.
     */
    public const METRICS = [
        'screenPageViews',
        'activeUsers',
        'newUsers',
        'sessions',
        'averageSessionDuration',
        'engagementRate',
        'bounceRate',
    ];

    public function __construct(
        public readonly int $views = 0,
        public readonly int $users = 0,
        public readonly int $newUsers = 0,
        public readonly int $sessions = 0,
        public readonly float $averageSessionDuration = 0.0,
        public readonly float $engagementRate = 0.0,
        public readonly float $bounceRate = 0.0,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * A totals-only report has exactly one row; anything else means the filter
     * matched nothing and the zeroed metrics are the honest answer.
     */
    public static function fromResponse(ReportResponse $response): self
    {
        if ($response->isEmpty()) {
            return self::empty();
        }

        return new self(
            views: $response->metricInt(0, 'screenPageViews'),
            users: $response->metricInt(0, 'activeUsers'),
            newUsers: $response->metricInt(0, 'newUsers'),
            sessions: $response->metricInt(0, 'sessions'),
            averageSessionDuration: $response->metric(0, 'averageSessionDuration'),
            engagementRate: $response->metric(0, 'engagementRate'),
            bounceRate: $response->metric(0, 'bounceRate'),
        );
    }

    public function hasData(): bool
    {
        return $this->views > 0 || $this->users > 0 || $this->sessions > 0;
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'views' => $this->views,
            'users' => $this->users,
            'newUsers' => $this->newUsers,
            'sessions' => $this->sessions,
            'averageSessionDuration' => $this->averageSessionDuration,
            'engagementRate' => $this->engagementRate,
            'bounceRate' => $this->bounceRate,
        ];
    }

    /**
     * @param array<string, int|float> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            views: (int)($data['views'] ?? 0),
            users: (int)($data['users'] ?? 0),
            newUsers: (int)($data['newUsers'] ?? 0),
            sessions: (int)($data['sessions'] ?? 0),
            averageSessionDuration: (float)($data['averageSessionDuration'] ?? 0),
            engagementRate: (float)($data['engagementRate'] ?? 0),
            bounceRate: (float)($data['bounceRate'] ?? 0),
        );
    }

    /**
     * The overview cards, ready for the template.
     *
     * @return list<array{key: string, label: string, value: string, raw: int|float}>
     */
    public function cards(): array
    {
        return [
            ['key' => 'views', 'label' => 'Page views', 'value' => Format::number($this->views), 'raw' => $this->views],
            ['key' => 'users', 'label' => 'Unique visitors', 'value' => Format::number($this->users), 'raw' => $this->users],
            ['key' => 'sessions', 'label' => 'Sessions', 'value' => Format::number($this->sessions), 'raw' => $this->sessions],
            ['key' => 'duration', 'label' => 'Avg. session', 'value' => Format::duration($this->averageSessionDuration), 'raw' => $this->averageSessionDuration],
            ['key' => 'engagement', 'label' => 'Engagement rate', 'value' => Format::percent($this->engagementRate), 'raw' => $this->engagementRate],
        ];
    }
}
