<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\Jwt;
use justinholtweb\telescope\errors\AuthException;

it('base64url-encodes without padding or URL-unsafe characters', function() {
    $encoded = Jwt::base64UrlEncode("\xfb\xff\xfe");

    expect($encoded)->not->toContain('=')
        ->and($encoded)->not->toContain('+')
        ->and($encoded)->not->toContain('/');
});

it('round-trips arbitrary bytes', function() {
    $value = random_bytes(64);

    expect(Jwt::base64UrlDecode(Jwt::base64UrlEncode($value)))->toBe($value);
});

it('builds the claim set Google expects', function() {
    $claims = Jwt::claimSet('svc@example.iam.gserviceaccount.com', 'scope-a', 'https://oauth2.googleapis.com/token', 1_700_000_000);

    expect($claims)->toBe([
        'iss' => 'svc@example.iam.gserviceaccount.com',
        'scope' => 'scope-a',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => 1_700_003_600,
        'iat' => 1_700_000_000,
    ]);
});

it('caps the assertion lifetime at the hour Google allows', function() {
    $claims = Jwt::claimSet('svc@example.com', 'scope', 'aud', 1000, 99999);

    expect($claims['exp'] - $claims['iat'])->toBe(Jwt::MAX_LIFETIME);
});

it('never issues an assertion that has already expired', function() {
    $claims = Jwt::claimSet('svc@example.com', 'scope', 'aud', 1000, 0);

    expect($claims['exp'])->toBeGreaterThan($claims['iat']);
});

it('refuses to build a claim set with no issuer', function() {
    Jwt::claimSet('  ', 'scope', 'aud', 1000);
})->throws(AuthException::class, 'missing its client email');

it('encodes a JWT as three dot-separated segments', function() {
    $jwt = Jwt::encode(['alg' => 'RS256', 'typ' => 'JWT'], ['iss' => 'a'], static fn(string $input): string => 'signature');

    $segments = explode('.', $jwt);

    expect($segments)->toHaveCount(3)
        ->and(json_decode(Jwt::base64UrlDecode($segments[0]), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode(Jwt::base64UrlDecode($segments[1]), true))->toBe(['iss' => 'a'])
        ->and(Jwt::base64UrlDecode($segments[2]))->toBe('signature');
});

it('signs over the header and claims, not the whole token', function() {
    $captured = null;

    $jwt = Jwt::encode(['alg' => 'RS256'], ['iss' => 'a'], function(string $input) use (&$captured): string {
        $captured = $input;

        return 'sig';
    });

    expect($captured)->toBe(substr($jwt, 0, strrpos($jwt, '.') ?: 0))
        ->and(substr_count($captured, '.'))->toBe(1);
});

it('fails loudly when the signer produces nothing', function() {
    Jwt::encode(['alg' => 'RS256'], [], static fn(): string => '');
})->throws(AuthException::class, 'Failed to sign');

it('produces a verifiable RS256 signature', function() {
    $privateKey = testPrivateKey();
    $signer = Jwt::rs256Signer($privateKey);

    $jwt = Jwt::encode(['alg' => 'RS256', 'typ' => 'JWT'], ['iss' => 'svc'], $signer);
    [$header, $claims, $signature] = explode('.', $jwt);

    $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['key'];
    $verified = openssl_verify(
        "{$header}.{$claims}",
        Jwt::base64UrlDecode($signature),
        $publicKey,
        OPENSSL_ALGO_SHA256,
    );

    expect($verified)->toBe(1);
});

it('explains itself when the private key is unreadable', function() {
    $signer = Jwt::rs256Signer('-----BEGIN PRIVATE KEY-----not a key-----END PRIVATE KEY-----');
    $signer('payload');
})->throws(AuthException::class, 'private key could not be read');
