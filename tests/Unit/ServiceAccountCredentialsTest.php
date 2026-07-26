<?php

declare(strict_types=1);

use justinholtweb\telescope\auth\ServiceAccountCredentials;
use justinholtweb\telescope\errors\AuthException;

it('parses a service account key', function() {
    $credentials = ServiceAccountCredentials::fromArray(serviceAccountArray());

    expect($credentials->clientEmail)->toBe('telescope@telescope-test.iam.gserviceaccount.com')
        ->and($credentials->projectId)->toBe('telescope-test')
        ->and($credentials->tokenUri)->toBe('https://oauth2.googleapis.com/token');
});

it('defaults the token URI when the key omits it', function() {
    $data = serviceAccountArray();
    unset($data['token_uri']);

    expect(ServiceAccountCredentials::fromArray($data)->tokenUri)
        ->toBe(ServiceAccountCredentials::DEFAULT_TOKEN_URI);
});

it('repairs escaped newlines in a key pasted through an .env file', function() {
    $mangled = str_replace("\n", '\\n', testPrivateKey());
    $credentials = ServiceAccountCredentials::fromArray(serviceAccountArray($mangled));

    expect($credentials->privateKey)->toBe(testPrivateKey())
        ->and(openssl_pkey_get_private($credentials->privateKey))->not->toBeFalse();
});

it('names the missing field when client_email is absent', function() {
    $data = serviceAccountArray();
    unset($data['client_email']);

    ServiceAccountCredentials::fromArray($data);
})->throws(AuthException::class, 'client_email');

it('names the missing field when private_key is absent', function() {
    $data = serviceAccountArray();
    $data['private_key'] = '   ';

    ServiceAccountCredentials::fromArray($data);
})->throws(AuthException::class, 'private_key');

it('parses a JSON key', function() {
    $credentials = ServiceAccountCredentials::fromJson((string)json_encode(serviceAccountArray()));

    expect($credentials->clientEmail)->toContain('telescope-test.iam.gserviceaccount.com');
});

it('rejects JSON that is not JSON', function() {
    ServiceAccountCredentials::fromJson('not json at all');
})->throws(AuthException::class, 'not valid JSON');

it('rejects OAuth client credentials mistaken for a service account key', function() {
    // Downloading the wrong JSON from Google Cloud is an easy mistake.
    ServiceAccountCredentials::fromJson((string)json_encode([
        'type' => 'authorized_user',
        'client_id' => 'x',
        'client_secret' => 'y',
    ]));
})->throws(AuthException::class, 'not a service account key');

it('reads a key from a file', function() {
    $path = tempnam(sys_get_temp_dir(), 'telescope') . '.json';
    file_put_contents($path, (string)json_encode(serviceAccountArray()));

    $credentials = ServiceAccountCredentials::fromFile($path);
    unlink($path);

    expect($credentials->clientEmail)->toContain('telescope');
});

it('says which file it could not read', function() {
    ServiceAccountCredentials::fromFile('/nowhere/telescope-key.json');
})->throws(AuthException::class, '/nowhere/telescope-key.json');

it('resolves inline JSON', function() {
    $credentials = ServiceAccountCredentials::resolve((string)json_encode(serviceAccountArray()));

    expect($credentials->clientEmail)->toContain('telescope');
});

it('resolves a file path', function() {
    $path = tempnam(sys_get_temp_dir(), 'telescope') . '.json';
    file_put_contents($path, (string)json_encode(serviceAccountArray()));

    $credentials = ServiceAccountCredentials::resolve(" {$path} ");
    unlink($path);

    expect($credentials->clientEmail)->toContain('telescope');
});

it('explains that nothing is configured when the setting is empty', function(?string $value) {
    ServiceAccountCredentials::resolve($value);
})->with([null, '', '   '])->throws(AuthException::class, 'No Google service account credentials');

it('fingerprints the account without exposing the key', function() {
    $credentials = ServiceAccountCredentials::fromArray(serviceAccountArray());
    $fingerprint = $credentials->fingerprint();

    expect($fingerprint)->toHaveLength(12)
        ->and($credentials->privateKey)->not->toContain($fingerprint);
});

it('gives different accounts different fingerprints', function() {
    $a = ServiceAccountCredentials::fromArray(serviceAccountArray());

    $other = serviceAccountArray();
    $other['client_email'] = 'someone-else@telescope-test.iam.gserviceaccount.com';
    $b = ServiceAccountCredentials::fromArray($other);

    expect($a->fingerprint())->not->toBe($b->fingerprint());
});
