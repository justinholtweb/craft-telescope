<?php

declare(strict_types=1);

use justinholtweb\telescope\errors\ApiException;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\tests\Support\FakeHttpClient;
use justinholtweb\telescope\tests\Support\Ga4;

function reportRequest(): ReportRequest
{
    return ReportRequest::for(Period::preset('last7days'))->metrics('screenPageViews');
}

it('normalises whichever form of property ID was pasted in', function(string $input, string $expected) {
    expect(Client::normalizePropertyId($input))->toBe($expected);
})->with([
    ['123456789', 'properties/123456789'],
    ['properties/123456789', 'properties/123456789'],
    ['  123456789  ', 'properties/123456789'],
    ['properties/123456789/', 'properties/123456789'],
    ['', ''],
    ['   ', ''],
]);

it('recognises a valid property ID', function() {
    expect(Client::isValidPropertyId('123456789'))->toBeTrue()
        ->and(Client::isValidPropertyId('properties/123456789'))->toBeTrue();
});

it('rejects a measurement ID and other non-numeric values', function(string $value) {
    expect(Client::isValidPropertyId($value))->toBeFalse();
})->with(['G-ABC123', 'UA-12345-1', 'my-property', '']);

it('posts a report to the property runReport endpoint', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::overview());

    fakeClient($http)->runReport(reportRequest());

    $request = $http->lastRequest();

    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport')
        ->and($request['headers']['Authorization'])->toBe('Bearer test-token')
        ->and($request['headers']['Content-Type'])->toBe('application/json');
});

it('sends the request body the builder produced', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::overview());

    fakeClient($http)->runReport(reportRequest());

    expect($http->requestBody())->toBe([
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'dimensions' => [],
        'metrics' => [['name' => 'screenPageViews']],
    ]);
});

it('parses a successful response', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::overview(views: 4321));

    $response = fakeClient($http)->runReport(reportRequest());

    expect($response->metricInt(0, 'screenPageViews'))->toBe(4321);
});

it('refuses to report with no property ID configured', function() {
    $http = new FakeHttpClient();

    try {
        fakeClient($http, '')->runReport(reportRequest());
    } catch (ApiException $e) {
        expect($e->reason)->toBe('NO_PROPERTY')
            ->and($http->requestCount())->toBe(0);

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('surfaces the message Google returned on a permission error', function() {
    $http = (new FakeHttpClient())->queueJson(
        Ga4::error('User does not have sufficient permissions for this property.'),
        403,
    );

    try {
        fakeClient($http)->runReport(reportRequest());
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('sufficient permissions')
            ->and($e->statusCode)->toBe(403)
            ->and($e->reason)->toBe('PERMISSION_DENIED')
            ->and($e->isRetryable())->toBeFalse();

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('does not retry a client error', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::error('Bad request', 'INVALID_ARGUMENT'), 400);

    try {
        fakeClient($http)->runReport(reportRequest());
    } catch (ApiException) {
        expect($http->requestCount())->toBe(1);

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('retries a rate-limit response and returns the eventual success', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::error('Quota exceeded', 'RESOURCE_EXHAUSTED'), 429)
        ->queueJson(Ga4::overview(views: 7));

    $response = fakeClient($http)->runReport(reportRequest());

    expect($http->requestCount())->toBe(2)
        ->and($response->metricInt(0, 'screenPageViews'))->toBe(7);
});

it('retries a server error', function() {
    $http = (new FakeHttpClient())
        ->queueJson(Ga4::error('Internal error', 'INTERNAL'), 500)
        ->queueJson(Ga4::error('Internal error', 'INTERNAL'), 503)
        ->queueJson(Ga4::overview());

    fakeClient($http)->runReport(reportRequest());

    expect($http->requestCount())->toBe(3);
});

it('gives up after the configured number of attempts', function() {
    $http = (new FakeHttpClient())->alwaysJson(Ga4::error('Quota exceeded', 'RESOURCE_EXHAUSTED'), 429);

    try {
        fakeClient($http, '123456789', 3)->runReport(reportRequest());
    } catch (ApiException $e) {
        expect($http->requestCount())->toBe(3)
            ->and($e->statusCode)->toBe(429);

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('does not retry at all when configured for a single attempt', function() {
    $http = (new FakeHttpClient())->alwaysJson(Ga4::error('Internal', 'INTERNAL'), 500);

    try {
        fakeClient($http, '123456789', 1)->runReport(reportRequest());
    } catch (ApiException) {
        expect($http->requestCount())->toBe(1);

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('falls back to a generic message when the error body is unhelpful', function() {
    $http = (new FakeHttpClient())->queue('<html>502 Bad Gateway</html>', 502);

    try {
        fakeClient($http, '123456789', 1)->runReport(reportRequest());
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('Google Analytics Data API returned an error')
            ->and($e->reason)->toBeNull();

        return;
    }

    throw new RuntimeException('Expected an ApiException.');
});

it('exposes the normalised property ID it will query', function() {
    expect(fakeClient(new FakeHttpClient(), 'properties/999')->propertyId())->toBe('properties/999');
});
