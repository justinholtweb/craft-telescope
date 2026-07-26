<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use justinholtweb\telescope\errors\ApiException;
use justinholtweb\telescope\http\GuzzleHttpClient;
use justinholtweb\telescope\http\HttpResponse;

it('treats 2xx as successful and everything else as not', function(int $status, bool $successful) {
    expect((new HttpResponse($status, ''))->isSuccessful())->toBe($successful);
})->with([
    [200, true],
    [204, true],
    [299, true],
    [300, false],
    [400, false],
    [500, false],
    [0, false],
]);

it('decodes a JSON body', function() {
    expect((new HttpResponse(200, '{"a":1}'))->json())->toBe(['a' => 1]);
});

it('returns an empty array for a body that is not a JSON object', function(string $body) {
    expect((new HttpResponse(200, $body))->json())->toBe([]);
})->with(['', 'not json', 'null', '"a string"']);

it('builds an API exception from an error body', function() {
    $exception = ApiException::fromResponse(403, [
        'error' => ['code' => 403, 'message' => 'No access.', 'status' => 'PERMISSION_DENIED'],
    ]);

    expect($exception->getMessage())->toBe('No access.')
        ->and($exception->statusCode)->toBe(403)
        ->and($exception->reason)->toBe('PERMISSION_DENIED');
});

it('falls back to a generic message when the body has no error', function() {
    $exception = ApiException::fromResponse(500, []);

    expect($exception->getMessage())->toContain('returned an error')
        ->and($exception->reason)->toBeNull();
});

it('knows which failures are worth retrying', function(int $status, bool $retryable) {
    expect(ApiException::fromResponse($status, [])->isRetryable())->toBe($retryable);
})->with([
    [400, false],
    [401, false],
    [403, false],
    [404, false],
    [429, true],
    [500, true],
    [503, true],
]);

it('passes a Guzzle response straight through', function() {
    $mock = new MockHandler([new Response(200, [], '{"ok":true}')]);
    $client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create($mock)]));

    $response = $client->request('POST', 'https://example.com', ['X-Test' => '1'], '{}');

    expect($response->statusCode)->toBe(200)
        ->and($response->json())->toBe(['ok' => true]);
});

it('returns a non-2xx response rather than throwing, so callers handle errors in one place', function() {
    $mock = new MockHandler([new Response(403, [], '{"error":{"message":"nope"}}')]);
    $client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create($mock)]));

    $response = $client->request('POST', 'https://example.com');

    expect($response->statusCode)->toBe(403)
        ->and($response->json()['error']['message'])->toBe('nope');
});

it('turns a connection failure into a 503 the retry logic understands', function() {
    $mock = new MockHandler([
        new ConnectException('Connection timed out', new Request('POST', 'https://example.com')),
    ]);
    $client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create($mock)]));

    $response = $client->request('POST', 'https://example.com');

    expect($response->statusCode)->toBe(503)
        ->and($response->json()['error']['status'])->toBe('UNAVAILABLE')
        ->and(ApiException::fromResponse($response->statusCode, $response->json())->isRetryable())->toBeTrue();
});
