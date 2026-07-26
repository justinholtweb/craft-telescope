<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\errors\TelescopeException;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\ga4\ReportResponse;
use justinholtweb\telescope\helpers\Format;
use justinholtweb\telescope\helpers\PathHelper;

/**
 * Turns a page path into a full {@see PageReport}.
 *
 * Every section is fetched independently and failures are collected rather than
 * thrown: a quota error on the geography breakdown should not blank out the
 * page-view count an editor came to see.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly Client $client,
        private readonly ReportOptions $options = new ReportOptions(),
    ) {
    }

    public function build(string $path, Period $period): PageReport
    {
        $path = PathHelper::normalize($path);

        $overview = PageMetrics::empty();
        $timeline = [];
        $geography = [];
        $sources = [];
        $referrers = [];
        $landingPages = [];

        $fetchers = [
            ReportSection::OVERVIEW => function() use ($path, $period, &$overview): void {
                $overview = PageMetrics::fromResponse($this->run($this->overviewRequest($path, $period)));
            },
            ReportSection::TIMELINE => function() use ($path, $period, &$timeline): void {
                $timeline = $this->parseTimeline($this->run($this->timelineRequest($path, $period)));
            },
            ReportSection::GEOGRAPHY => function() use ($path, $period, &$geography): void {
                $geography = $this->parseGeography($this->run($this->geographyRequest($path, $period)));
            },
            ReportSection::SOURCES => function() use ($path, $period, &$sources): void {
                $sources = $this->parseSources($this->run($this->sourcesRequest($path, $period)));
            },
            ReportSection::REFERRERS => function() use ($path, $period, &$referrers): void {
                $referrers = $this->parseReferrers($this->run($this->referrersRequest($path, $period)));
            },
            ReportSection::LANDING_PAGES => function() use ($path, $period, &$landingPages): void {
                $landingPages = $this->parseLandingPages($this->run($this->landingPagesRequest($path, $period)));
            },
        ];

        /** @var array<string, string> $failures section handle => message */
        $failures = [];

        foreach ($fetchers as $section => $fetch) {
            if (!$this->options->wants($section)) {
                continue;
            }

            try {
                $fetch();
            } catch (AuthException $e) {
                // Credentials are not a per-section problem: the remaining five
                // calls would fail identically, so stop and say so once.
                $failures = ['' => $e->getMessage()];
                break;
            } catch (TelescopeException $e) {
                $failures[$section] = $e->getMessage();
            }
        }

        return new PageReport(
            path: $path,
            periodLabel: $period->label,
            overview: $overview,
            timeline: $timeline,
            geography: $geography,
            sources: $sources,
            referrers: $referrers,
            landingPages: $landingPages,
            errors: $this->describeFailures($failures),
        );
    }

    /**
     * Turn per-section failures into messages worth reading.
     *
     * A property-wide problem — no access, quota exhausted — fails every
     * section with the same message; repeating it six times buries the point.
     *
     * @param array<string, string> $failures section handle => message
     * @return list<string>
     */
    private function describeFailures(array $failures): array
    {
        if ($failures === []) {
            return [];
        }

        $counts = array_count_values($failures);
        $messages = [];

        foreach ($failures as $section => $message) {
            if (($counts[$message] ?? 0) > 1 || $section === '') {
                $messages[$message] = $message;
                continue;
            }

            $label = ReportSection::options()[$section] ?? $section;
            $messages[$message] = "{$label}: {$message}";
        }

        return array_values($messages);
    }

    /**
     * The most-viewed pages across the whole property, for the dashboard widget
     * and the CP overview.
     *
     * @return list<array{path: string, title: string, views: int, users: int}>
     */
    public function topPages(Period $period, int $limit = 10): array
    {
        $request = ReportRequest::for($period)
            ->dimensions('pagePath', 'pageTitle')
            ->metrics('screenPageViews', 'activeUsers')
            ->orderByMetric('screenPageViews')
            ->limit($limit);

        $this->applyHostname($request);

        return $this->run($request)->map(static fn(ReportResponse $response, int $row): array => [
            'path' => $response->dimension($row, 'pagePath'),
            'title' => $response->dimension($row, 'pageTitle'),
            'views' => $response->metricInt($row, 'screenPageViews'),
            'users' => $response->metricInt($row, 'activeUsers'),
        ]);
    }

    /**
     * Overview metrics for many paths in one call — used to decorate element
     * indexes without firing one request per row.
     *
     * @param list<string> $paths
     * @return array<string, PageMetrics> keyed by normalised path
     */
    public function metricsForPaths(array $paths, Period $period): array
    {
        $paths = array_values(array_unique(array_map(
            static fn(string $path): string => PathHelper::normalize($path),
            $paths,
        )));

        if ($paths === []) {
            return [];
        }

        $request = ReportRequest::for($period)
            ->dimensions('pagePath')
            ->metrics(...PageMetrics::METRICS)
            ->orderByMetric('screenPageViews')
            // Ask for headroom: the property may contain far more paths than we
            // asked about, and there is no `IN LIST` filter for a single call.
            ->limit(max(count($paths) * 10, 100));

        $this->applyHostname($request);

        $response = $this->run($request);
        $wanted = array_flip($paths);
        $results = [];

        foreach (array_keys($response->rows) as $row) {
            $path = PathHelper::normalize($response->dimension($row, 'pagePath'));

            if (!isset($wanted[$path])) {
                continue;
            }

            $results[$path] = new PageMetrics(
                views: $response->metricInt($row, 'screenPageViews'),
                users: $response->metricInt($row, 'activeUsers'),
                newUsers: $response->metricInt($row, 'newUsers'),
                sessions: $response->metricInt($row, 'sessions'),
                averageSessionDuration: $response->metric($row, 'averageSessionDuration'),
                engagementRate: $response->metric($row, 'engagementRate'),
                bounceRate: $response->metric($row, 'bounceRate'),
            );
        }

        return $results;
    }

    // Request builders
    // =========================================================================

    public function overviewRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)->metrics(...PageMetrics::METRICS);
    }

    public function timelineRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)
            ->dimensions('date')
            ->metrics('screenPageViews', 'activeUsers')
            ->orderByDimension('date');
    }

    public function geographyRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)
            ->dimensions('city', 'region', 'country')
            ->metrics('activeUsers')
            ->orderByMetric('activeUsers')
            ->limit($this->options->rowLimit);
    }

    public function sourcesRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)
            ->dimensions('sessionSource', 'sessionMedium')
            ->metrics('sessions', 'activeUsers')
            ->orderByMetric('sessions')
            ->limit($this->options->rowLimit);
    }

    public function referrersRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)
            ->dimensions('pageReferrer')
            ->metrics('screenPageViews')
            ->orderByMetric('screenPageViews')
            ->limit($this->options->rowLimit);
    }

    public function landingPagesRequest(string $path, Period $period): ReportRequest
    {
        return $this->baseRequest($path, $period)
            ->dimensions('landingPage')
            ->metrics('sessions')
            ->orderByMetric('sessions')
            ->limit($this->options->rowLimit);
    }

    // Parsers
    // =========================================================================

    /**
     * @return list<array{date: string, views: int, users: int}>
     */
    public function parseTimeline(ReportResponse $response): array
    {
        return $response->map(static fn(ReportResponse $r, int $row): array => [
            'date' => Format::date($r->dimension($row, 'date')),
            'views' => $r->metricInt($row, 'screenPageViews'),
            'users' => $r->metricInt($row, 'activeUsers'),
        ]);
    }

    /**
     * @return list<array{city: string, region: string, country: string, users: int}>
     */
    public function parseGeography(ReportResponse $response): array
    {
        return $response->map(static fn(ReportResponse $r, int $row): array => [
            'city' => self::clean($r->dimension($row, 'city')),
            'region' => self::clean($r->dimension($row, 'region')),
            'country' => self::clean($r->dimension($row, 'country')),
            'users' => $r->metricInt($row, 'activeUsers'),
        ]);
    }

    /**
     * @return list<array{source: string, medium: string, sessions: int, users: int}>
     */
    public function parseSources(ReportResponse $response): array
    {
        return $response->map(static fn(ReportResponse $r, int $row): array => [
            'source' => self::clean($r->dimension($row, 'sessionSource'), 'Direct'),
            'medium' => self::clean($r->dimension($row, 'sessionMedium'), 'None'),
            'sessions' => $r->metricInt($row, 'sessions'),
            'users' => $r->metricInt($row, 'activeUsers'),
        ]);
    }

    /**
     * @return list<array{referrer: string, url: string, views: int, isInternal: bool}>
     */
    public function parseReferrers(ReportResponse $response): array
    {
        $knownHosts = $this->options->knownHosts;

        return $response->map(static function(ReportResponse $r, int $row) use ($knownHosts): array {
            $referrer = $r->dimension($row, 'pageReferrer');

            return [
                'referrer' => PathHelper::referrerLabel($referrer, $knownHosts),
                'url' => $referrer,
                'views' => $r->metricInt($row, 'screenPageViews'),
                'isInternal' => PathHelper::isInternalReferrer($referrer, $knownHosts),
            ];
        });
    }

    /**
     * @return list<array{page: string, sessions: int}>
     */
    public function parseLandingPages(ReportResponse $response): array
    {
        return $response->map(static fn(ReportResponse $r, int $row): array => [
            'page' => self::clean($r->dimension($row, 'landingPage'), '(direct entry)'),
            'sessions' => $r->metricInt($row, 'sessions'),
        ]);
    }

    // Internals
    // =========================================================================

    private function baseRequest(string $path, Period $period): ReportRequest
    {
        $request = ReportRequest::for($period)->pagePath($path, $this->options->matchType);

        $this->applyHostname($request);

        return $request;
    }

    private function applyHostname(ReportRequest $request): void
    {
        $hostname = $this->options->hostname;

        if ($hostname !== null && trim($hostname) !== '') {
            $request->hostname(PathHelper::normalizeHost($hostname));
        }
    }

    private function run(ReportRequest $request): ReportResponse
    {
        return $this->client->runReport($request);
    }

    /**
     * GA4's placeholders for "we don't know" and "there wasn't one".
     */
    private const PLACEHOLDERS = ['(not set)', '(none)', '(other)', '(direct)'];

    /**
     * GA4 uses `(not set)` and friends for unknown dimension values; showing
     * those verbatim in a table looks like a bug.
     */
    private static function clean(string $value, string $fallback = 'Unknown'): string
    {
        $value = trim($value);

        if ($value === '' || in_array($value, self::PLACEHOLDERS, true)) {
            return $fallback;
        }

        return $value;
    }
}
