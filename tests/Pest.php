<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\StaticTokenProvider;
use justinholtweb\telescope\ga4\Client;
use justinholtweb\telescope\tests\Support\FakeHttpClient;

/*
 |--------------------------------------------------------------------------
 | Suites
 |--------------------------------------------------------------------------
 |
 | Unit — plain PHP, no Craft application. Feature — exercises Craft base
 | classes (settings model, field validation) that work without a booted app.
 |
 */

uses()->group('unit')->in('Unit');
uses()->group('feature')->in('Feature');

/*
 |--------------------------------------------------------------------------
 | Helpers
 |--------------------------------------------------------------------------
 */

/**
 * A GA4 client wired to a scripted transport and a fixed bearer token.
 */
function fakeClient(FakeHttpClient $http, string $propertyId = '123456789', int $maxAttempts = 3): Client
{
    return new Client(
        $propertyId,
        StaticTokenProvider::of('test-token', PHP_INT_MAX),
        $http,
        $maxAttempts,
        static fn(int $microseconds) => null,
    );
}

/**
 * A syntactically valid service account key, with a real (throwaway) RSA key
 * so the signing path can be exercised end to end.
 *
 * @return array<string, string>
 */
function serviceAccountArray(?string $privateKey = null): array
{
    return [
        'type' => 'service_account',
        'project_id' => 'telescope-test',
        'client_email' => 'telescope@telescope-test.iam.gserviceaccount.com',
        'private_key' => $privateKey ?? testPrivateKey(),
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ];
}

/**
 * A throwaway 2048-bit RSA key, generated once per test run.
 */
function testPrivateKey(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $exported);

    return $key = $exported;
}
