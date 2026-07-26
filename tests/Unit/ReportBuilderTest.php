<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\AccessToken;
use justinholtweb\telescope\auth\TokenProviderInterface;
use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\reports\ReportBuilder;
use justinholtweb\telescope\reports\ReportOptions;
use justinholtweb\telescope\reports\ReportSection;
use justinholtweb\telescope\tests\Support\FakeHttpClient;
use justinholtweb\telescope\tests\Support\Ga4;

/**
 * Queue one response per section, in the order the builder asks for them.
 */
function queueFullReport(FakeHttpClient $http): FakeHttpClient
{
    return $http
        ->queueJson(Ga4::overview(views: 1200, users: 800, sessions: 950, duration: 134.0, engagement: 0.62))
        ->queueJson(Ga4::timeline([['20260101', 400, 300], ['20260102', 800, 500]]))
        ->queueJson(Ga4::response(['city', 'region', 'country'], ['activeUsers'], [
            [['Charlotte', 'North Carolina', 'United States'], [500]],
            [['(not set)', 'Ontario', 'Canada'], [40]],
        ]))
        ->queueJson(Ga4::response(['sessionSource', 'sessionMedium'], ['sessions', 'activeUsers'], [
            [['google', 'organic'], [600, 500]],
            [['(direct)', '(none)'], [200, 180]],
        ]))
        ->queueJson(Ga4::response(['pageReferrer'], ['screenPageViews'], [
            [['https://example.com/blog'], [120]],
            [['https://news.ycombinator.com/item?id=1'], [80]],
            [[''], [30]],
        ]))
        ->queueJson(Ga4::response(['landingPage'], ['sessions'], [
            [['/'], [400]],
            [['(not set)'], [50]],
        ]));
}

function builder(FakeHttpClient $http, ?ReportOptions $options = null): ReportBuilder
{
    return new ReportBuilder(fakeClient($http), $options ?? new ReportOptions(knownHosts: ['example.com']));
}

it('assembles every section of a report', function() {
    $http = queueFullReport(new FakeHttpClient());

    $report = builder($http)->build('/about', Period::preset('last28days'));

    expect($http->requestCount())->toBe(6)
        ->and($report->path)->toBe('/about')
        ->and($report->periodLabel)->toBe('Last 28 days')
        ->and($report->hasErrors())->toBeFalse()
        ->and($report->hasData())->toBeTrue();
});

it('reads the overview totals', function() {
    $report = builder(queueFullReport(new FakeHttpClient()))->build('/about', Period::preset('last28days'));

    expect($report->overview->views)->toBe(1200)
        ->and($report->overview->users)->toBe(800)
        ->and($report->overview->sessions)->toBe(950)
        ->and($report->overview->averageSessionDuration)->toBe(134.0)
        ->and($report->overview->engagementRate)->toBe(0.62);
});

it('converts timeline dates to ISO format', function() {
    $report = builder(queueFullReport(new FakeHttpClient()))->build('/about', Period::preset('last28days'));

    expect($report->timeline)->toBe([
        ['date' => '2026-01-01', 'views' => 400, 'users' => 300],
        ['date' => '2026-01-02', 'views' => 800, 'users' => 500],
    ]);
});

it('replaces GA4 placeholder dimension values with readable labels', function() {
    $report = builder(queueFullReport(new FakeHttpClient()))->build('/about', Period::preset('last28days'));

    expect($report->geography[1]['city'])->toBe('Unknown')
        ->and($report->sources[1]['source'])->toBe('Direct')
        ->and($report->sources[1]['medium'])->toBe('None')
        ->and($report->landingPages[1]['page'])->toBe('(direct entry)');
});

it('classifies referrers as internal or external', function() {
    $report = builder(queueFullReport(new FakeHttpClient()))->build('/about', Period::preset('last28days'));

    expect($report->referrers[0])->toMatchArray([
        'referrer' => '/blog',
        'isInternal' => true,
        'views' => 120,
    ])
        ->and($report->referrers[1]['isInternal'])->toBeFalse()
        ->and($report->referrers[1]['referrer'])->toBe('news.ycombinator.com/item')
        ->and($report->referrers[2]['referrer'])->toBe('Direct / none');
});

it('normalises the path before querying', function() {
    $http = queueFullReport(new FakeHttpClient());

    $report = builder($http)->build('/about/', Period::preset('last28days'));

    expect($report->path)->toBe('/about')
        ->and($http->requestBody(0)['dimensionFilter']['filter']['stringFilter']['value'])->toBe('/about');
});

it('applies the configured match type to every section', function() {
    $http = queueFullReport(new FakeHttpClient());
    $options = new ReportOptions(matchType: ReportRequest::MATCH_BEGINS_WITH);

    builder($http, $options)->build('/blog', Period::preset('last28days'));

    for ($i = 0; $i < 6; $i++) {
        expect($http->requestBody($i)['dimensionFilter']['filter']['stringFilter']['matchType'])
            ->toBe('BEGINS_WITH');
    }
});

it('adds a hostname filter when one is configured', function() {
    $http = queueFullReport(new FakeHttpClient());
    $options = new ReportOptions(hostname: 'WWW.Example.com');

    builder($http, $options)->build('/about', Period::preset('last28days'));

    $expressions = $http->requestBody(0)['dimensionFilter']['andGroup']['expressions'];

    expect($expressions[1]['filter']['fieldName'])->toBe('hostName')
        ->and($expressions[1]['filter']['stringFilter']['value'])->toBe('example.com');
});

it('honours the row limit on breakdown tables', function() {
    $http = queueFullReport(new FakeHttpClient());

    builder($http, new ReportOptions(rowLimit: 3))->build('/about', Period::preset('last28days'));

    expect($http->requestBody(2)['limit'])->toBe(3)
        ->and($http->requestBody(3)['limit'])->toBe(3)
        ->and($http->requestBody(4)['limit'])->toBe(3)
        ->and($http->requestBody(5)['limit'])->toBe(3);
});

it('orders the timeline by date and the tables by their headline metric', function() {
    $http = queueFullReport(new FakeHttpClient());

    builder($http)->build('/about', Period::preset('last28days'));

    expect($http->requestBody(1)['orderBys'])->toBe([['dimension' => ['dimensionName' => 'date']]])
        ->and($http->requestBody(2)['orderBys'])->toBe([['metric' => ['metricName' => 'activeUsers'], 'desc' => true]]);
});

it('only requests the sections that are switched on', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::overview())
        ->queueJson(Ga4::timeline([['20260101', 5, 4]]));

    $options = new ReportOptions(sections: [ReportSection::OVERVIEW, ReportSection::TIMELINE]);
    $report = builder($http, $options)->build('/about', Period::preset('last28days'));

    expect($http->requestCount())->toBe(2)
        ->and($report->geography)->toBe([])
        ->and($report->sources)->toBe([])
        ->and($report->timeline)->toHaveCount(1);
});

it('keeps the sections that worked when one section fails', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::overview(views: 500))
        ->queueJson(Ga4::error('Quota exceeded', 'RESOURCE_EXHAUSTED'), 403)
        ->queueJson(Ga4::response(['city', 'region', 'country'], ['activeUsers'], [[['Charlotte', 'NC', 'US'], [10]]]))
        ->queueJson(Ga4::response(['sessionSource', 'sessionMedium'], ['sessions', 'activeUsers'], []))
        ->queueJson(Ga4::response(['pageReferrer'], ['screenPageViews'], []))
        ->queueJson(Ga4::response(['landingPage'], ['sessions'], []));

    $report = builder($http)->build('/about', Period::preset('last28days'));

    expect($report->overview->views)->toBe(500)
        ->and($report->geography)->toHaveCount(1)
        ->and($report->hasErrors())->toBeTrue()
        ->and($report->errors[0])->toContain('Views over time')
        ->and($report->errors[0])->toContain('Quota exceeded');
});

it('reports a property-wide failure once rather than six times', function() {
    // Every section fails with the same message; repeating it buries the point.
    $http = (new FakeHttpClient())->alwaysJson(Ga4::error('Permission denied'), 403);

    $report = builder($http)->build('/about', Period::preset('last28days'));

    expect($report->overview->views)->toBe(0)
        ->and($report->hasData())->toBeFalse()
        ->and($report->errors)->toBe(['Permission denied']);
});

it('names the section when only one of them fails', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::overview(views: 500))
        ->queueJson(Ga4::error('Dimension not supported', 'INVALID_ARGUMENT'), 400)
        ->queueJson(Ga4::response(['city', 'region', 'country'], ['activeUsers'], []))
        ->queueJson(Ga4::response(['sessionSource', 'sessionMedium'], ['sessions', 'activeUsers'], []))
        ->queueJson(Ga4::response(['pageReferrer'], ['screenPageViews'], []))
        ->queueJson(Ga4::response(['landingPage'], ['sessions'], []));

    $report = builder($http)->build('/about', Period::preset('last28days'));

    expect($report->errors)->toBe(['Views over time: Dimension not supported']);
});

it('stops after an auth failure instead of repeating it per section', function() {
    // Bad credentials cannot be recovered from section by section, and five
    // more doomed round trips help nobody.
    $http = new FakeHttpClient();
    $client = new Client(
        '123456789',
        new class() implements TokenProviderInterface {
            public function getToken(): AccessToken
            {
                throw new AuthException('Google rejected the service account credentials.');
            }

            public function fingerprint(): string
            {
                return 'broken';
            }
        },
        $http,
    );

    $report = (new ReportBuilder($client))->build('/about', Period::preset('last28days'));

    expect($http->requestCount())->toBe(0)
        ->and($report->errors)->toBe(['Google rejected the service account credentials.'])
        ->and($report->hasData())->toBeFalse();
});

it('treats a page with no recorded traffic as empty, not broken', function() {
    $http = (new FakeHttpClient())->alwaysJson(Ga4::empty());

    $report = builder($http)->build('/brand-new', Period::preset('last28days'));

    expect($report->hasErrors())->toBeFalse()
        ->and($report->hasData())->toBeFalse()
        ->and($report->overview->views)->toBe(0)
        ->and($report->timeline)->toBe([]);
});

it('lists the property\'s top pages', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::response(['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers'], [
        [['/', 'Home'], [5000, 3800]],
        [['/about', 'About us'], [1200, 900]],
    ]));

    $pages = builder($http)->topPages(Period::preset('last28days'), 5);

    expect($pages)->toBe([
        ['path' => '/', 'title' => 'Home', 'views' => 5000, 'users' => 3800],
        ['path' => '/about', 'title' => 'About us', 'views' => 1200, 'users' => 900],
    ])
        ->and($http->requestBody(0)['limit'])->toBe(5)
        ->and($http->requestBody(0))->not->toHaveKey('dimensionFilter');
});

it('applies the hostname filter to the top pages query too', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::response(['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers'], []));

    builder($http, new ReportOptions(hostname: 'example.com'))->topPages(Period::preset('last28days'));

    expect($http->requestBody(0)['dimensionFilter']['filter']['fieldName'])->toBe('hostName');
});

it('fetches metrics for many paths in a single call', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::response(
        ['pagePath'],
        ['screenPageViews', 'activeUsers', 'newUsers', 'sessions', 'averageSessionDuration', 'engagementRate', 'bounceRate'],
        [
            [['/about'], [100, 80, 40, 90, 60.0, 0.5, 0.5]],
            [['/contact'], [20, 18, 9, 19, 30.0, 0.4, 0.6]],
            [['/unrelated'], [999, 900, 500, 950, 10.0, 0.1, 0.9]],
        ],
    ));

    $metrics = builder($http)->metricsForPaths(['/about', '/contact/', '/never-visited'], Period::preset('last28days'));

    expect($http->requestCount())->toBe(1)
        ->and($metrics)->toHaveKeys(['/about', '/contact'])
        ->and($metrics)->not->toHaveKey('/unrelated')
        ->and($metrics)->not->toHaveKey('/never-visited')
        ->and($metrics['/about']->views)->toBe(100)
        ->and($metrics['/contact']->users)->toBe(18);
});

it('makes no request when asked for metrics for no paths', function() {
    $http = new FakeHttpClient();

    expect(builder($http)->metricsForPaths([], Period::preset('last28days')))->toBe([])
        ->and($http->requestCount())->toBe(0);
});

it('deduplicates paths that normalise to the same page', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::response(['pagePath'], ['screenPageViews'], []));

    builder($http)->metricsForPaths(['/about', '/about/', '/about'], Period::preset('last28days'));

    // 1 unique path × 10 headroom, floored at 100.
    expect($http->requestBody(0)['limit'])->toBe(100);
});
