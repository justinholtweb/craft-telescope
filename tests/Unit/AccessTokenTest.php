<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\AccessToken;
use justinholtweb\telescope\errors\AuthException;

it('builds a token from a Google token response', function() {
    $token = AccessToken::fromResponse(['access_token' => 'ya29.abc', 'expires_in' => 3600], 1000);

    expect($token->value)->toBe('ya29.abc')
        ->and($token->expiresAt)->toBe(4600);
});

it('assumes the documented hour when expires_in is missing', function() {
    expect(AccessToken::fromResponse(['access_token' => 'x'], 0)->expiresAt)->toBe(3600);
});

it('rejects a response with no access token', function(array $response) {
    AccessToken::fromResponse($response, 0);
})->with([
    [[]],
    [['access_token' => '']],
    [['access_token' => '   ']],
    [['access_token' => null]],
])->throws(AuthException::class);

it('rejects an empty token value', function() {
    new AccessToken('  ', time() + 60);
})->throws(AuthException::class, 'empty access token');

it('is not expired well before its expiry', function() {
    expect((new AccessToken('t', 1000))->isExpired(500))->toBeFalse();
});

it('is expired at and after its expiry', function() {
    expect((new AccessToken('t', 1000))->isExpired(1000))->toBeTrue()
        ->and((new AccessToken('t', 1000))->isExpired(1500))->toBeTrue();
});

it('treats a token inside the leeway window as expired', function() {
    // A token with 30 seconds left could die mid-request, so it is refreshed early.
    expect((new AccessToken('t', 1000))->isExpired(970))->toBeTrue()
        ->and((new AccessToken('t', 1000))->isExpired(939))->toBeFalse();
});

it('reports how long it can still be cached, minus the leeway', function() {
    expect((new AccessToken('t', 1000))->secondsRemaining(0))->toBe(940);
});

it('never reports negative remaining seconds', function() {
    expect((new AccessToken('t', 1000))->secondsRemaining(5000))->toBe(0);
});
