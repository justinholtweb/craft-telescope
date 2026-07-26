<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;

function request(): ReportRequest
{
    return ReportRequest::for(Period::preset('last28days'));
}

it('builds the minimum viable report body', function() {
    $payload = request()->metrics('screenPageViews')->toArray();

    expect($payload)->toBe([
        'dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'today']],
        'dimensions' => [],
        'metrics' => [['name' => 'screenPageViews']],
    ]);
});

it('refuses to build a report with no metrics', function() {
    request()->dimensions('date')->toArray();
})->throws(InvalidArgumentException::class, 'at least one metric');

it('names dimensions and metrics in the order given', function() {
    $payload = request()
        ->dimensions('date', 'city')
        ->metrics('screenPageViews', 'activeUsers')
        ->toArray();

    expect($payload['dimensions'])->toBe([['name' => 'date'], ['name' => 'city']])
        ->and($payload['metrics'])->toBe([['name' => 'screenPageViews'], ['name' => 'activeUsers']]);
});

it('does not repeat a dimension or metric added twice', function() {
    $payload = request()
        ->dimensions('date')
        ->dimensions('date', 'city')
        ->metrics('sessions')
        ->metrics('sessions')
        ->toArray();

    expect($payload['dimensions'])->toHaveCount(2)
        ->and($payload['metrics'])->toHaveCount(1);
});

it('filters on an exact page path', function() {
    $payload = request()->metrics('sessions')->pagePath('/about')->toArray();

    expect($payload['dimensionFilter'])->toBe([
        'filter' => [
            'fieldName' => 'pagePath',
            'stringFilter' => ['matchType' => 'EXACT', 'value' => '/about'],
        ],
    ]);
});

it('supports the other match types', function(string $matchType) {
    $payload = request()->metrics('sessions')->pagePath('/blog', $matchType)->toArray();

    expect($payload['dimensionFilter']['filter']['stringFilter']['matchType'])->toBe($matchType);
})->with([
    ReportRequest::MATCH_BEGINS_WITH,
    ReportRequest::MATCH_CONTAINS,
]);

it('rejects a match type GA4 does not support here', function() {
    request()->metrics('sessions')->pagePath('/blog', 'REGEXP');
})->throws(InvalidArgumentException::class, 'Unsupported string match type');

it('combines a path and a hostname into an AND group', function() {
    $payload = request()->metrics('sessions')->pagePath('/about')->hostname('example.com')->toArray();

    expect($payload['dimensionFilter'])->toHaveKey('andGroup')
        ->and($payload['dimensionFilter']['andGroup']['expressions'])->toHaveCount(2)
        ->and($payload['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'])->toBe('hostName')
        ->and($payload['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'])->toBe('example.com');
});

it('omits the filter entirely when nothing is filtered', function() {
    expect(request()->metrics('sessions')->toArray())->not->toHaveKey('dimensionFilter');
});

it('orders by a metric descending by default', function() {
    $payload = request()->metrics('sessions')->orderByMetric('sessions')->toArray();

    expect($payload['orderBys'])->toBe([['metric' => ['metricName' => 'sessions'], 'desc' => true]]);
});

it('omits desc when ordering ascending', function() {
    $payload = request()->metrics('sessions')->orderByDimension('date')->toArray();

    expect($payload['orderBys'])->toBe([['dimension' => ['dimensionName' => 'date']]]);
});

it('replaces the sort rather than accumulating sorts', function() {
    $payload = request()
        ->metrics('sessions')
        ->orderByDimension('date')
        ->orderByMetric('sessions')
        ->toArray();

    expect($payload['orderBys'])->toHaveCount(1)
        ->and($payload['orderBys'][0])->toHaveKey('metric');
});

it('carries limit, offset and keepEmptyRows', function() {
    $payload = request()->metrics('sessions')->limit(25)->offset(10)->keepEmptyRows()->toArray();

    expect($payload['limit'])->toBe(25)
        ->and($payload['offset'])->toBe(10)
        ->and($payload['keepEmptyRows'])->toBeTrue();
});

it('omits limit, offset and keepEmptyRows when unset', function() {
    $payload = request()->metrics('sessions')->toArray();

    expect($payload)->not->toHaveKey('limit')
        ->and($payload)->not->toHaveKey('offset')
        ->and($payload)->not->toHaveKey('keepEmptyRows');
});

it('rejects a limit below one', function() {
    request()->limit(0);
})->throws(InvalidArgumentException::class, 'at least 1');

it('rejects a negative offset', function() {
    request()->offset(-1);
})->throws(InvalidArgumentException::class, 'cannot be negative');

it('fingerprints identical requests identically', function() {
    $a = request()->metrics('sessions')->pagePath('/about')->fingerprint();
    $b = request()->metrics('sessions')->pagePath('/about')->fingerprint();

    expect($a)->toBe($b);
});

it('fingerprints different requests differently', function() {
    $a = request()->metrics('sessions')->pagePath('/about')->fingerprint();
    $b = request()->metrics('sessions')->pagePath('/contact')->fingerprint();

    expect($a)->not->toBe($b);
});
