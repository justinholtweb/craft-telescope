<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\reports\DashboardSection;
use justinholtweb\telescope\reports\ReportBuilder;
use justinholtweb\telescope\reports\ReportOptions;
use justinholtweb\telescope\reports\SiteReport;
use justinholtweb\telescope\tests\Support\FakeHttpClient;
use justinholtweb\telescope\tests\Support\Ga4;

/**
 * Queue one response per dashboard panel, in the order buildSiteReport asks
 * for them: totals, top pages, timeline, sources, countries, devices, browsers.
 */
function queueDashboard(FakeHttpClient $http): FakeHttpClient
{
    return $http
        ->queueJson(Ga4::overview(views: 5000, users: 3200, newUsers: 2100, sessions: 4100))
        ->queueJson(Ga4::response(['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers'], [
            [['/', 'Home'], [2000, 1500]],
            [['/about', 'About'], [900, 700]],
        ]))
        ->queueJson(Ga4::timeline([['20260101', 400, 300], ['20260102', 800, 500]]))
        ->queueJson(Ga4::response(['sessionSource'], ['sessions', 'activeUsers'], [
            [['google'], [600, 500]],
            [['(direct)'], [400, 380]],
        ]))
        ->queueJson(Ga4::response(['country'], ['activeUsers'], [
            [['United States'], [700]],
            [['(not set)'], [300]],
        ]))
        ->queueJson(Ga4::response(['deviceCategory'], ['sessions'], [
            [['desktop'], [750]],
            [['mobile'], [250]],
        ]))
        ->queueJson(Ga4::response(['browser'], ['sessions'], [
            [['Chrome'], [800]],
            [['Safari'], [200]],
        ]));
}

/**
 * Local rather than shared with ReportBuilderTest: a helper defined in another
 * test file is not loaded when this one runs on its own.
 */
function siteBuilder(FakeHttpClient $http, ?ReportOptions $options = null): ReportBuilder
{
    return new ReportBuilder(fakeClient($http), $options ?? new ReportOptions());
}

it('assembles every dashboard panel', function() {
    $http = queueDashboard(new FakeHttpClient());

    $report = siteBuilder($http)->buildSiteReport(Period::preset('last28days'));

    expect($http->requestCount())->toBe(7)
        ->and($report->periodLabel)->toBe('Last 28 days')
        ->and($report->hasErrors())->toBeFalse()
        ->and($report->hasData())->toBeTrue()
        ->and($report->totals->views)->toBe(5000)
        ->and($report->totals->newUsers)->toBe(2100)
        ->and($report->topPages)->toHaveCount(2)
        ->and($report->timeline)->toHaveCount(2)
        ->and($report->sources)->toHaveCount(2)
        ->and($report->countries)->toHaveCount(2)
        ->and($report->devices)->toHaveCount(2)
        ->and($report->browsers)->toHaveCount(2);
});

it('reports each row\'s share of the rows shown', function() {
    $report = siteBuilder(queueDashboard(new FakeHttpClient()))->buildSiteReport(Period::preset('last28days'));

    // 750 and 250 of 1000 sessions.
    expect($report->devices[0])->toMatchArray(['label' => 'desktop', 'sessions' => 750, 'share' => 75.0])
        ->and($report->devices[1]['share'])->toBe(25.0)
        // Shares are of the rows returned, not of the property total.
        ->and(array_sum(array_column($report->sources, 'share')))->toBe(100.0);
});

it('cleans GA4 placeholder labels', function() {
    $report = siteBuilder(queueDashboard(new FakeHttpClient()))->buildSiteReport(Period::preset('last28days'));

    expect($report->sources[1]['label'])->toBe('Direct')
        ->and($report->countries[1]['label'])->toBe('Unknown');
});

it('skips the panels that are switched off', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::overview())
        ->queueJson(Ga4::response(['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers'], [
            [['/', 'Home'], [2000, 1500]],
        ]))
        ->queueJson(Ga4::response(['deviceCategory'], ['sessions'], [[['desktop'], [750]]]));

    $report = siteBuilder($http)->buildSiteReport(Period::preset('last28days'), 10, [DashboardSection::DEVICES]);

    // Totals and top pages are never optional; only devices was asked for.
    expect($http->requestCount())->toBe(3)
        ->and($report->devices)->toHaveCount(1)
        ->and($report->timeline)->toBe([])
        ->and($report->sources)->toBe([])
        ->and($report->browsers)->toBe([]);
});

it('does not filter the site-wide requests by page path', function() {
    // The whole point of the dashboard: the same reports, unfiltered. Top pages
    // still groups *by* pagePath, so this checks the filter, not the dimension.
    $http = queueDashboard(new FakeHttpClient());

    siteBuilder($http)->buildSiteReport(Period::preset('last28days'));

    foreach ($http->requests as $request) {
        expect((string)$request['body'])->not->toContain('"fieldName":"pagePath"');
    }
});

it('still scopes site-wide requests to the hostname when that is on', function() {
    $http = queueDashboard(new FakeHttpClient());

    siteBuilder($http, new ReportOptions(hostname: 'example.com'))
        ->buildSiteReport(Period::preset('last28days'));

    expect((string)$http->requests[0]['body'])->toContain('hostName');
});

it('keeps the panels that worked when one of them fails', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::overview(views: 5000))
        ->queueJson(Ga4::response(['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers'], [
            [['/', 'Home'], [2000, 1500]],
        ]))
        ->queueJson(Ga4::error('Quota exceeded.', 'RESOURCE_EXHAUSTED'), 429)
        ->queueJson(Ga4::error('Quota exceeded.', 'RESOURCE_EXHAUSTED'), 429)
        ->queueJson(Ga4::error('Quota exceeded.', 'RESOURCE_EXHAUSTED'), 429)
        ->queueJson(Ga4::response(['sessionSource'], ['sessions', 'activeUsers'], [[['google'], [600, 500]]]))
        ->queueJson(Ga4::response(['country'], ['activeUsers'], [[['United States'], [700]]]))
        ->queueJson(Ga4::response(['deviceCategory'], ['sessions'], [[['desktop'], [750]]]))
        ->queueJson(Ga4::response(['browser'], ['sessions'], [[['Chrome'], [800]]]));

    $report = siteBuilder($http)->buildSiteReport(Period::preset('last28days'));

    expect($report->totals->views)->toBe(5000)
        ->and($report->sources)->toHaveCount(1)
        ->and($report->timeline)->toBe([])
        ->and($report->errors)->toHaveCount(1)
        ->and($report->errors[0])->toStartWith('Traffic over time:');
});

it('survives a round trip through the cache', function() {
    $report = siteBuilder(queueDashboard(new FakeHttpClient()))->buildSiteReport(Period::preset('last28days'));

    $restored = SiteReport::fromArray($report->toArray());

    expect($restored->toArray())->toBe($report->toArray())
        ->and($restored->totals->views)->toBe(5000)
        ->and($restored->devices[0]['label'])->toBe('desktop');
});

it('shrugs off a cache entry that is missing or malformed', function() {
    $restored = SiteReport::fromArray(['periodLabel' => 'Last 7 days', 'devices' => 'nonsense']);

    expect($restored->periodLabel)->toBe('Last 7 days')
        ->and($restored->devices)->toBe([])
        ->and($restored->totals->views)->toBe(0)
        ->and($restored->hasData())->toBeFalse();
});

it('builds cards for every headline number', function() {
    $report = siteBuilder(queueDashboard(new FakeHttpClient()))->buildSiteReport(Period::preset('last28days'));

    expect(array_column($report->cards(), 'key'))
        ->toBe(['views', 'users', 'newUsers', 'sessions', 'duration', 'engagement', 'bounce']);
});

it('keeps only recognised panel handles, in canonical order', function() {
    expect(DashboardSection::normalize(['browsers', 'nope', 'timeline']))
        ->toBe(['timeline', 'browsers'])
        ->and(DashboardSection::normalize('not an array'))->toBe(DashboardSection::ALL)
        ->and(DashboardSection::normalize([]))->toBe([]);
});
