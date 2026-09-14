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
     * The whole property for one period — the control panel dashboard.
     *
     * Like {@see build()}, each panel is fetched independently and failures are
     * collected rather than thrown: a quota error on the browser breakdown
     * should not blank out the page-view count.
     *
     * @param list<string>|null $sections which optional panels to fetch;
     *                                    null means all of them. The totals and
     *                                    the top-pages table are always fetched.
     */
    public function buildSiteReport(Period $period, int $topPagesLimit = 10, ?array $sections = null): SiteReport
    {
        $sections = DashboardSection::normalize($sections ?? DashboardSection::ALL);

        $totals = PageMetrics::empty();
        $timeline = [];
        $sources = [];
        $countries = [];
        $devices = [];
        $browsers = [];
        $topPages = [];

        $fetchers = [
            // Not a DashboardSection: the dashboard without these is a blank page.
            'totals' => function() use ($period, &$totals): void {
                $totals = PageMetrics::fromResponse($this->run($this->siteTotalsRequest($period)));
            },
            'topPages' => function() use ($period, $topPagesLimit, &$topPages): void {
                $topPages = $this->topPages($period, $topPagesLimit);
            },
            DashboardSection::TIMELINE => function() use ($period, &$timeline): void {
                $timeline = $this->parseTimeline($this->run($this->siteTimelineRequest($period)));
            },
            DashboardSection::SOURCES => function() use ($period, &$sources): void {
                $sources = $this->parseShare(
                    $this->run($this->siteSourcesRequest($period)),
                    'sessionSource',
                    'sessions',
                    ['users' => 'activeUsers'],
                    'Direct',
                );
            },
            DashboardSection::GEOGRAPHY => function() use ($period, &$countries): void {
                $countries = $this->parseShare(
                    $this->run($this->siteCountriesRequest($period)),
                    'country',
                    'activeUsers',
                    [],
                    'Unknown',
                    'users',
                );
            },
            DashboardSection::DEVICES => function() use ($period, &$devices): void {
                $devices = $this->parseShare(
                    $this->run($this->siteDevicesRequest($period)),
                    'deviceCategory',
                    'sessions',
                    [],
                    'Unknown',
                );
            },
            DashboardSection::BROWSERS => function() use ($period, &$browsers): void {
                $browsers = $this->parseShare(
                    $this->run($this->siteBrowsersRequest($period)),
                    'browser',
                    'sessions',
                    [],
                    'Unknown',
                );
            },
        ];

        /** @var array<string, string> $failures */
        $failures = [];

        foreach ($fetchers as $section => $fetch) {
            if (!in_array($section, ['totals', 'topPages'], true) && !in_array($section, $sections, true)) {
                continue;
            }

            try {
                $fetch();
            } catch (AuthException $e) {
                $failures = ['' => $e->getMessage()];
                break;
            } catch (TelescopeException $e) {
                $failures[$section] = $e->getMessage();
            }
        }

        return new SiteReport(
            periodLabel: $period->label,
            totals: $totals,
            timeline: $timeline,
            sources: $sources,
            countries: $countries,
            devices: $devices,
            browsers: $browsers,
            topPages: $topPages,
            errors: $this->describeSiteFailures($failures),
        );
    }

    /**
     * @param array<string, string> $failures
     * @return list<string>
     */
    private function describeSiteFailures(array $failures): array
    {
        if ($failures === []) {
            return [];
        }

        $labels = DashboardSection::options() + [
            'totals' => 'Totals',
            'topPages' => 'Top pages',
        ];

        $counts = array_count_values($failures);
        $messages = [];

        foreach ($failures as $section => $message) {
            if (($counts[$message] ?? 0) > 1 || $section === '') {
                $messages[$message] = $message;
                continue;
            }

            $messages[$message] = ($labels[$section] ?? $section) . ": {$message}";
        }

        return array_values($messages);
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

    // Site-wide request builders
    //
    // The same reports without the `pagePath` filter. The hostname filter still
    // applies — on a property shared by several Craft sites, "site-wide" has to
    // mean one site.
    // =========================================================================

    public function siteTotalsRequest(Period $period): ReportRequest
    {
        return $this->siteRequest($period)->metrics(...PageMetrics::METRICS);
    }

    public function siteTimelineRequest(Period $period): ReportRequest
    {
        return $this->siteRequest($period)
            ->dimensions('date')
            ->metrics('screenPageViews', 'activeUsers')
            ->orderByDimension('date');
    }

    public function siteSourcesRequest(Period $period): ReportRequest
    {
        return $this->siteRequest($period)
            ->dimensions('sessionSource')
            ->metrics('sessions', 'activeUsers')
            ->orderByMetric('sessions')
            ->limit($this->options->rowLimit);
    }

    public function siteCountriesRequest(Period $period): ReportRequest
    {
        return $this->siteRequest($period)
            ->dimensions('country')
            ->metrics('activeUsers')
            ->orderByMetric('activeUsers')
            ->limit($this->options->rowLimit);
    }

    public function siteDevicesRequest(Period $period): ReportRequest
    {
        // Three categories exist — desktop, mobile, tablet — so the row limit
        // is fixed rather than taken from settings.
        return $this->siteRequest($period)
            ->dimensions('deviceCategory')
            ->metrics('sessions')
            ->orderByMetric('sessions')
            ->limit(10);
    }

    public function siteBrowsersRequest(Period $period): ReportRequest
    {
        return $this->siteRequest($period)
            ->dimensions('browser')
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

    /**
     * A one-dimension breakdown, with each row's share of the total.
     *
     * The share is what the charts are drawn from, and computing it here rather
     * than in JavaScript means the print view and the widget get it too.
     *
     * Note the total is the sum of the rows returned, not the property total:
     * these reports are capped at `rowLimit`, so shares are "of the top N" and
     * a long tail is excluded by design rather than silently mis-attributed.
     *
     * @param array<string, string> $extraMetrics result key => GA4 metric name
     * @return list<array<string, mixed>>
     */
    public function parseShare(
        ReportResponse $response,
        string $dimension,
        string $metric,
        array $extraMetrics = [],
        string $fallback = 'Unknown',
        string $key = 'sessions',
    ): array {
        $rows = $response->map(static fn(ReportResponse $r, int $row): array => [
            'label' => self::clean($r->dimension($row, $dimension), $fallback),
            $key => $r->metricInt($row, $metric),
        ] + array_map(
            static fn(string $name): int => $r->metricInt($row, $name),
            $extraMetrics,
        ));

        $total = array_sum(array_column($rows, $key));

        return array_map(
            static fn(array $row): array => $row + [
                'share' => $total > 0 ? round($row[$key] / $total * 100, 1) : 0.0,
            ],
            $rows,
        );
    }

    // Internals
    // =========================================================================

    private function baseRequest(string $path, Period $period): ReportRequest
    {
        $request = ReportRequest::for($period)->pagePath($path, $this->options->matchType);

        $this->applyHostname($request);

        return $request;
    }

    /**
     * A property-wide request: no page filter, but still scoped to this site's
     * hostname when the setting is on.
     */
    private function siteRequest(Period $period): ReportRequest
    {
        $request = ReportRequest::for($period);

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
