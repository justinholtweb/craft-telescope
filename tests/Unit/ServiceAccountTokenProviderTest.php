<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\Jwt;
use justinholtweb\telescope\auth\ServiceAccountCredentials;
use justinholtweb\telescope\auth\ServiceAccountTokenProvider;
use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\tests\Support\FakeHttpClient;
use justinholtweb\telescope\tests\Support\Ga4;

/**
 * @param callable(): int|null $clock
 */
function provider(FakeHttpClient $http, ?callable $clock = null): ServiceAccountTokenProvider
{
    return new ServiceAccountTokenProvider(
        ServiceAccountCredentials::fromArray(serviceAccountArray()),
        $http,
        ServiceAccountTokenProvider::SCOPE_READONLY,
        $clock ?? static fn(): int => 1_700_000_000,
        static fn(string $input): string => 'test-signature',
    );
}

it('exchanges a signed assertion for an access token', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token('ya29.fresh', 3600));

    $token = provider($http)->getToken();

    expect($token->value)->toBe('ya29.fresh')
        ->and($token->expiresAt)->toBe(1_700_003_600);
});

it('posts the JWT bearer grant to the credentials\' token URI', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token());

    provider($http)->getToken();

    $request = $http->lastRequest();
    $form = $http->requestForm();

    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://oauth2.googleapis.com/token')
        ->and($request['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded')
        ->and($form['grant_type'])->toBe(Jwt::GRANT_TYPE)
        ->and($form['assertion'])->toBeString();
});

it('requests the read-only analytics scope', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token());

    provider($http)->getToken();

    $assertion = $http->requestForm()['assertion'];
    $claims = json_decode(Jwt::base64UrlDecode(explode('.', $assertion)[1]), true);

    expect($claims['scope'])->toBe('https://www.googleapis.com/auth/analytics.readonly')
        ->and($claims['iss'])->toBe('telescope@telescope-test.iam.gserviceaccount.com')
        ->and($claims['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($claims['iat'])->toBe(1_700_000_000);
});

it('reuses a token that is still valid instead of hitting Google again', function() {
    $http = (new FakeHttpClient())->queueJson(Ga4::token('first', 3600));
    $provider = provider($http);

    $provider->getToken();
    $second = $provider->getToken();

    expect($http->requestCount())->toBe(1)
        ->and($second->value)->toBe('first');
});

it('fetches a new token once the cached one is close to expiring', function() {
    $now = 1_700_000_000;

    $http = (new FakeHttpClient())
        ->queueJson(Ga4::token('first', 3600))
        ->queueJson(Ga4::token('second', 3600));

    $provider = provider($http, static function() use (&$now): int {
        return $now;
    });

    expect($provider->getToken()->value)->toBe('first');

    // 30 seconds before expiry — inside the refresh leeway.
    $now = 1_700_003_570;

    expect($provider->getToken()->value)->toBe('second')
        ->and($http->requestCount())->toBe(2);
});

it('reports what Google said when credentials are rejected', function() {
    $http = (new FakeHttpClient())->queueJson([
        'error' => 'invalid_grant',
        'error_description' => 'Invalid JWT Signature.',
    ], 400);

    provider($http)->getToken();
})->throws(AuthException::class, 'Invalid JWT Signature');

it('suggests the usual cause of an invalid_grant', function() {
    $http = (new FakeHttpClient())->queueJson([
        'error' => 'invalid_grant',
        'error_description' => 'Invalid grant: account not found.',
    ], 400);

    try {
        provider($http)->getToken();
    } catch (AuthException $e) {
        expect($e->getMessage())->toContain('clock')
            ->and($e->getMessage())->toContain('revoked')
            // A description that already ends in a full stop must not produce "..".
            ->and($e->getMessage())->not->toContain('..');

        return;
    }

    throw new RuntimeException('Expected an AuthException.');
});

it('fails when the token endpoint is unreachable', function() {
    $http = (new FakeHttpClient())->queueJson(['error' => ['message' => 'down']], 503);

    provider($http)->getToken();
})->throws(AuthException::class, 'HTTP 503');

it('fails when the response has no access token', function() {
    $http = (new FakeHttpClient())->queueJson(['expires_in' => 3600]);

    provider($http)->getToken();
})->throws(AuthException::class, 'did not contain an access token');

it('fingerprints the credentials without exposing them', function() {
    $provider = provider(new FakeHttpClient());

    expect($provider->fingerprint())->toStartWith('sa_')
        ->and($provider->fingerprint())->not->toContain('telescope@');
});

it('signs the assertion with the real key when no signer is injected', function() {
    $provider = new ServiceAccountTokenProvider(
        ServiceAccountCredentials::fromArray(serviceAccountArray()),
        new FakeHttpClient(),
    );

    $assertion = $provider->buildAssertion(1_700_000_000);
    [$header, $claims, $signature] = explode('.', $assertion);

    $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(testPrivateKey()))['key'];

    expect(openssl_verify("{$header}.{$claims}", Jwt::base64UrlDecode($signature), $publicKey, OPENSSL_ALGO_SHA256))
        ->toBe(1);
});
