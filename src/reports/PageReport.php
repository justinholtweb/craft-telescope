<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

/**
 * Everything the control panel shows for one page over one period.
 *
 * Serialisable both ways so a whole report can be stored in Craft's cache and
 * restored without re-running six API calls.
 */
final class PageReport
{
    /**
     * @param list<array{date: string, views: int, users: int}> $timeline
     * @param list<array{city: string, region: string, country: string, users: int}> $geography
     * @param list<array{source: string, medium: string, sessions: int, users: int}> $sources
     * @param list<array{referrer: string, url: string, views: int, isInternal: bool}> $referrers
     * @param list<array{page: string, sessions: int}> $landingPages
     * @param list<string> $errors messages for sections that could not be loaded
     */
    public function __construct(
        public readonly string $path,
        public readonly string $periodLabel,
        public readonly PageMetrics $overview,
        public readonly array $timeline = [],
        public readonly array $geography = [],
        public readonly array $sources = [],
        public readonly array $referrers = [],
        public readonly array $landingPages = [],
        public readonly array $errors = [],
    ) {
    }

    public static function empty(string $path = '', string $periodLabel = ''): self
    {
        return new self($path, $periodLabel, PageMetrics::empty());
    }

    public function hasData(): bool
    {
        return $this->overview->hasData() || $this->timeline !== [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The peak day in the timeline, or null when there is no timeline.
     *
     * @return array{date: string, views: int, users: int}|null
     */
    public function busiestDay(): ?array
    {
        $busiest = null;

        foreach ($this->timeline as $point) {
            if ($busiest === null || $point['views'] > $busiest['views']) {
                $busiest = $point;
            }
        }

        return $busiest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'periodLabel' => $this->periodLabel,
            'overview' => $this->overview->toArray(),
            'timeline' => $this->timeline,
            'geography' => $this->geography,
            'sources' => $this->sources,
            'referrers' => $this->referrers,
            'landingPages' => $this->landingPages,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string)($data['path'] ?? ''),
            periodLabel: (string)($data['periodLabel'] ?? ''),
            overview: PageMetrics::fromArray(is_array($data['overview'] ?? null) ? $data['overview'] : []),
            timeline: is_array($data['timeline'] ?? null) ? array_values($data['timeline']) : [],
            geography: is_array($data['geography'] ?? null) ? array_values($data['geography']) : [],
            sources: is_array($data['sources'] ?? null) ? array_values($data['sources']) : [],
            referrers: is_array($data['referrers'] ?? null) ? array_values($data['referrers']) : [],
            landingPages: is_array($data['landingPages'] ?? null) ? array_values($data['landingPages']) : [],
            errors: is_array($data['errors'] ?? null) ? array_values($data['errors']) : [],
        );
    }

    /**
     * A copy of this report with extra error messages attached.
     *
     * @param list<string> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self(
            $this->path,
            $this->periodLabel,
            $this->overview,
            $this->timeline,
            $this->geography,
            $this->sources,
            $this->referrers,
            $this->landingPages,
            array_values(array_unique([...$this->errors, ...$errors])),
        );
    }
}
