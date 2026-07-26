<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\ReportResponse;
use justinholtweb\telescope\tests\Support\Ga4;

it('reads dimensions and metrics by name, not by position', function() {
    $response = ReportResponse::fromArray(Ga4::response(
        ['city', 'region'],
        ['activeUsers', 'sessions'],
        [[['Charlotte', 'North Carolina'], [42, 55]]],
    ));

    expect($response->dimension(0, 'region'))->toBe('North Carolina')
        ->and($response->dimension(0, 'city'))->toBe('Charlotte')
        ->and($response->metricInt(0, 'sessions'))->toBe(55)
        ->and($response->metricInt(0, 'activeUsers'))->toBe(42);
});

it('survives a response with no rows', function() {
    $response = ReportResponse::fromArray(Ga4::empty());

    expect($response->isEmpty())->toBeTrue()
        ->and($response->rowCount)->toBe(0)
        ->and($response->dimension(0, 'city'))->toBe('')
        ->and($response->metricInt(0, 'sessions'))->toBe(0);
});

it('survives a completely empty payload', function() {
    $response = ReportResponse::fromArray([]);

    expect($response->isEmpty())->toBeTrue()
        ->and($response->dimensionHeaders)->toBe([])
        ->and($response->metricHeaders)->toBe([]);
});

it('returns the default for a column that is not in the response', function() {
    $response = ReportResponse::fromArray(Ga4::response(['city'], ['sessions'], [[['Charlotte'], [3]]]));

    expect($response->dimension(0, 'country', 'Unknown'))->toBe('Unknown')
        ->and($response->metric(0, 'bounceRate', 0.5))->toBe(0.5);
});

it('returns the default for a row that does not exist', function() {
    $response = ReportResponse::fromArray(Ga4::response(['city'], ['sessions'], [[['Charlotte'], [3]]]));

    expect($response->dimension(7, 'city', '—'))->toBe('—')
        ->and($response->metricInt(7, 'sessions', -1))->toBe(-1);
});

it('reads fractional metrics as floats', function() {
    $response = ReportResponse::fromArray(Ga4::overview(engagement: 0.6234));

    expect($response->metric(0, 'engagementRate'))->toBe(0.6234);
});

it('rounds rather than truncates when reading a metric as an int', function() {
    $response = ReportResponse::fromArray(Ga4::response([], ['sessions'], [[[], ['9.7']]]));

    expect($response->metricInt(0, 'sessions'))->toBe(10);
});

it('falls back to the default for a non-numeric metric value', function() {
    $response = ReportResponse::fromArray(Ga4::response([], ['sessions'], [[[], ['n/a']]]));

    expect($response->metric(0, 'sessions', 0.0))->toBe(0.0);
});

it('uses rowCount from the payload when present', function() {
    $raw = Ga4::response(['date'], ['sessions'], [[['20260101'], [1]]]);
    $raw['rowCount'] = 500;

    expect(ReportResponse::fromArray($raw)->rowCount)->toBe(500);
});

it('maps every row', function() {
    $response = ReportResponse::fromArray(Ga4::timeline([
        ['20260101', 10, 8],
        ['20260102', 20, 15],
    ]));

    $mapped = $response->map(static fn(ReportResponse $r, int $row): array => [
        $r->dimension($row, 'date'),
        $r->metricInt($row, 'screenPageViews'),
    ]);

    expect($mapped)->toBe([['20260101', 10], ['20260102', 20]]);
});

it('totals a metric across rows', function() {
    $response = ReportResponse::fromArray(Ga4::timeline([
        ['20260101', 10, 8],
        ['20260102', 20, 15],
        ['20260103', 5, 4],
    ]));

    expect($response->total('screenPageViews'))->toBe(35.0)
        ->and($response->total('activeUsers'))->toBe(27.0);
});

it('totals zero for a metric that is not present', function() {
    expect(ReportResponse::fromArray(Ga4::empty())->total('screenPageViews'))->toBe(0.0);
});

it('builds an explicitly empty response', function() {
    expect(ReportResponse::empty()->isEmpty())->toBeTrue();
});
