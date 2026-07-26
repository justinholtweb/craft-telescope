<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\RefreshTokenProvider;
use justinholtweb\telescope\auth\StaticTokenProvider;
use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\tests\Support\FakeHttpClient;
use justinholtweb\telescope\tests\Support\Ga4;

/**
 * @param callable(): int|null $clock
 */
function refreshProvider(FakeHttpClient $http, ?callable $clock = null): RefreshTokenProvider
{
    return new RefreshTokenProvider(
        'client-id.apps.googleusercontent.com',
        'client-secret',
        '1//refresh-token',
        $http,
        RefreshTokenProvider::TOKEN_URI,
        $clock ?? static fn(): int => 1_700_000_000,
    );
}

it('exchanges a refresh token for an access token', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token('ya29.refreshed', 3599));

    $token = refreshProvider($http)->getToken();

    expect($token->value)->toBe('ya29.refreshed')
        ->and($token->expiresAt)->toBe(1_700_003_599);
});

it('posts the refresh_token grant with the client credentials', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token());

    refreshProvider($http)->getToken();

    expect($http->requestForm())->toBe([
        'grant_type' => 'refresh_token',
        'client_id' => 'client-id.apps.googleusercontent.com',
        'client_secret' => 'client-secret',
        'refresh_token' => '1//refresh-token',
    ]);
});

it('caches the token until it nears expiry', function() {
    $now = 1_700_000_000;

    $http = (new FakeHttpClient())
        ->queueJson(Ga4::token('first', 3600))
        ->queueJson(Ga4::token('second', 3600));

    $provider = refreshProvider($http, static function() use (&$now): int {
        return $now;
    });

    $provider->getToken();
    $now = 1_700_001_000;
    $provider->getToken();

    expect($http->requestCount())->toBe(1);

    $now = 1_700_003_600;

    expect($provider->getToken()->value)->toBe('second');
});

it('refuses to be built without every credential', function(string $id, string $secret, string $refresh) {
    new RefreshTokenProvider($id, $secret, $refresh, new FakeHttpClient());
})->with([
    ['', 'secret', 'refresh'],
    ['id', '', 'refresh'],
    ['id', 'secret', '   '],
])->throws(AuthException::class, 'client ID, client secret and refresh token');

it('tells the user to re-authorise when the refresh token is dead', function() {
    $http = (new FakeHttpClient())->queueJson([
        'error' => 'invalid_grant',
        'error_description' => 'Token has been expired or revoked.',
    ], 400);

    refreshProvider($http)->getToken();
})->throws(AuthException::class, 'Re-authorise the connection');

it('fingerprints the credentials without exposing the refresh token', function() {
    $provider = refreshProvider(new FakeHttpClient());

    expect($provider->fingerprint())->toStartWith('oauth_')
        ->and($provider->fingerprint())->not->toContain('refresh-token');
});

it('hands back a static token unchanged', function() {
    $provider = StaticTokenProvider::of('preset-token', 4_000_000_000);

    expect($provider->getToken()->value)->toBe('preset-token')
        ->and($provider->getToken()->expiresAt)->toBe(4_000_000_000)
        ->and($provider->fingerprint())->toStartWith('static_');
});
