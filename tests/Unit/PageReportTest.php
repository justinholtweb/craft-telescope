<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\ReportResponse;
use justinholtweb\telescope\reports\PageMetrics;
use justinholtweb\telescope\reports\PageReport;
use justinholtweb\telescope\tests\Support\Ga4;

it('reads overview metrics from a totals response', function() {
    $metrics = PageMetrics::fromResponse(ReportResponse::fromArray(Ga4::overview(
        views: 1200,
        users: 800,
        newUsers: 500,
        sessions: 950,
        duration: 134.5,
        engagement: 0.62,
        bounce: 0.38,
    )));

    expect($metrics->views)->toBe(1200)
        ->and($metrics->users)->toBe(800)
        ->and($metrics->newUsers)->toBe(500)
        ->and($metrics->sessions)->toBe(950)
        ->and($metrics->averageSessionDuration)->toBe(134.5)
        ->and($metrics->engagementRate)->toBe(0.62)
        ->and($metrics->bounceRate)->toBe(0.38);
});

it('reads zeroes from an empty response', function() {
    $metrics = PageMetrics::fromResponse(ReportResponse::fromArray(Ga4::empty()));

    expect($metrics->views)->toBe(0)
        ->and($metrics->hasData())->toBeFalse();
});

it('counts a page with sessions but no views as having data', function() {
    expect((new PageMetrics(sessions: 3))->hasData())->toBeTrue()
        ->and((new PageMetrics(users: 1))->hasData())->toBeTrue()
        ->and(PageMetrics::empty()->hasData())->toBeFalse();
});

it('round-trips metrics through an array', function() {
    $metrics = new PageMetrics(1200, 800, 500, 950, 134.5, 0.62, 0.38);
    $restored = PageMetrics::fromArray($metrics->toArray());

    expect($restored)->toEqual($metrics);
});

it('restores metrics from a partial array', function() {
    $metrics = PageMetrics::fromArray(['views' => 5]);

    expect($metrics->views)->toBe(5)
        ->and($metrics->sessions)->toBe(0);
});

it('formats the overview cards for display', function() {
    $cards = (new PageMetrics(1200, 800, 500, 950, 134.0, 0.6234))->cards();

    expect($cards)->toHaveCount(5)
        ->and(array_column($cards, 'value'))->toBe(['1,200', '800', '950', '2m 14s', '62.3%']);
});

it('reports whether it has data or errors', function() {
    $empty = PageReport::empty('/about', 'Last 28 days');

    expect($empty->hasData())->toBeFalse()
        ->and($empty->hasErrors())->toBeFalse()
        ->and($empty->path)->toBe('/about');
});

it('counts a report with only a timeline as having data', function() {
    $report = new PageReport('/a', 'Last 7 days', PageMetrics::empty(), [
        ['date' => '2026-01-01', 'views' => 0, 'users' => 0],
    ]);

    expect($report->hasData())->toBeTrue();
});

it('finds the busiest day', function() {
    $report = new PageReport('/a', 'Last 7 days', PageMetrics::empty(), [
        ['date' => '2026-01-01', 'views' => 10, 'users' => 8],
        ['date' => '2026-01-02', 'views' => 45, 'users' => 30],
        ['date' => '2026-01-03', 'views' => 12, 'users' => 9],
    ]);

    expect($report->busiestDay())->toBe(['date' => '2026-01-02', 'views' => 45, 'users' => 30]);
});

it('has no busiest day without a timeline', function() {
    expect(PageReport::empty()->busiestDay())->toBeNull();
});

it('round-trips a whole report through an array, the way the cache stores it', function() {
    $report = new PageReport(
        path: '/about',
        periodLabel: 'Last 28 days',
        overview: new PageMetrics(1200, 800, 500, 950, 134.5, 0.62, 0.38),
        timeline: [['date' => '2026-01-01', 'views' => 400, 'users' => 300]],
        geography: [['city' => 'Charlotte', 'region' => 'NC', 'country' => 'US', 'users' => 500]],
        sources: [['source' => 'google', 'medium' => 'organic', 'sessions' => 600, 'users' => 500]],
        referrers: [['referrer' => '/blog', 'url' => 'https://example.com/blog', 'views' => 120, 'isInternal' => true]],
        landingPages: [['page' => '/', 'sessions' => 400]],
    );

    $restored = PageReport::fromArray($report->toArray());

    expect($restored->toArray())->toBe($report->toArray())
        ->and($restored->overview->views)->toBe(1200)
        ->and($restored->referrers[0]['isInternal'])->toBeTrue();
});

it('restores a report from a malformed cache payload without fatalling', function() {
    $restored = PageReport::fromArray(['path' => '/a', 'timeline' => 'not an array']);

    expect($restored->path)->toBe('/a')
        ->and($restored->timeline)->toBe([])
        ->and($restored->overview->views)->toBe(0);
});

it('attaches errors without losing the data already fetched', function() {
    $report = new PageReport('/a', 'Last 7 days', new PageMetrics(views: 10));
    $withError = $report->withErrors(['Top locations: quota exceeded']);

    expect($withError->overview->views)->toBe(10)
        ->and($withError->errors)->toBe(['Top locations: quota exceeded'])
        ->and($report->errors)->toBe([]);
});

it('does not repeat the same error twice', function() {
    $report = PageReport::empty()->withErrors(['boom'])->withErrors(['boom', 'bang']);

    expect($report->errors)->toBe(['boom', 'bang']);
});
