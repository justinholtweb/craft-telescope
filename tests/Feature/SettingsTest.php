<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\models\Settings;
use justinholtweb\telescope\reports\ReportSection;

function settings(array $attributes = []): Settings
{
    $settings = new Settings();
    $settings->setAttributes($attributes, false);

    return $settings;
}

function configuredSettings(array $attributes = []): Settings
{
    return settings(array_merge([
        'propertyId' => '123456789',
        'credentials' => (string)json_encode(serviceAccountArray()),
    ], $attributes));
}

it('ships with sensible defaults', function() {
    $settings = new Settings();

    expect($settings->authMode)->toBe(Settings::AUTH_SERVICE_ACCOUNT)
        ->and($settings->cacheDuration)->toBe(600)
        ->and($settings->defaultPeriod)->toBe('last28days')
        ->and($settings->pathMatchType)->toBe(ReportRequest::MATCH_EXACT)
        ->and($settings->includeQueryString)->toBeFalse()
        ->and($settings->rowLimit)->toBe(10)
        ->and($settings->sections)->toBe(ReportSection::ALL);
});

it('validates a complete service account configuration', function() {
    expect(configuredSettings()->validate())->toBeTrue();
});

it('saves cleanly before any credentials exist', function() {
    // A fresh install must be able to save its other settings before anyone
    // has been to Google Cloud to create a key.
    expect((new Settings())->validate())->toBeTrue();
});

it('rejects a service account key that will not parse', function() {
    $settings = settings(['propertyId' => '123456789', 'credentials' => '{"nope": true}']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('credentials')[0])->toContain('client_email');
});

it('does not try to parse credentials in OAuth mode', function() {
    $settings = settings([
        'authMode' => Settings::AUTH_OAUTH,
        'propertyId' => '123456789',
        'credentials' => 'left over from before',
        'clientId' => 'id',
        'clientSecret' => 'secret',
        'refreshToken' => 'refresh',
    ]);

    expect($settings->validate())->toBeTrue();
});

it('accepts a complete OAuth configuration', function() {
    $settings = settings([
        'authMode' => Settings::AUTH_OAUTH,
        'propertyId' => '123456789',
        'clientId' => 'id',
        'clientSecret' => 'secret',
        'refreshToken' => 'refresh',
    ]);

    expect($settings->validate())->toBeTrue();
});

it('rejects an unknown authentication mode', function() {
    $settings = configuredSettings(['authMode' => 'magic']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('authMode'))->not->toBeEmpty();
});

it('explains that a measurement ID is not a property ID', function() {
    $settings = configuredSettings(['propertyId' => 'G-ABC12345']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('propertyId')[0])->toContain('measurement ID');
});

it('rejects a non-numeric property ID', function() {
    $settings = configuredSettings(['propertyId' => 'my-property']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('propertyId'))->not->toBeEmpty();
});

it('accepts a property ID with or without the properties/ prefix', function(string $value) {
    expect(configuredSettings(['propertyId' => $value])->validate())->toBeTrue();
})->with(['123456789', 'properties/123456789']);

it('rejects an unusable default period', function() {
    $settings = configuredSettings(['defaultPeriod' => 'last fortnight']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('defaultPeriod'))->not->toBeEmpty();
});

it('accepts a raw GA4 date as the default period', function() {
    expect(configuredSettings(['defaultPeriod' => '2026-01-01'])->validate())->toBeTrue();
});

it('rejects out-of-range numeric settings', function(string $attribute, int $value) {
    $settings = configuredSettings([$attribute => $value]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors($attribute))->not->toBeEmpty();
})->with([
    ['rowLimit', 0],
    ['rowLimit', 500],
    ['widgetLimit', 0],
    ['cacheDuration', -1],
]);

it('normalises the property ID it hands to the client', function() {
    expect(configuredSettings(['propertyId' => ' 123456789 '])->getPropertyIdForSite())
        ->toBe('properties/123456789');
});

it('uses a per-site property override when one is set', function() {
    $settings = configuredSettings(['sitePropertyIds' => ['shop' => '987654321']]);

    expect($settings->getPropertyIdForSite('shop'))->toBe('properties/987654321')
        ->and($settings->getPropertyIdForSite('default'))->toBe('properties/123456789');
});

it('falls back to the default property when the override is blank', function() {
    $settings = configuredSettings(['sitePropertyIds' => ['shop' => '   ']]);

    expect($settings->getPropertyIdForSite('shop'))->toBe('properties/123456789');
});

it('reads credentials and property IDs from environment variables', function() {
    putenv('TELESCOPE_TEST_PROPERTY=555000111');
    putenv('TELESCOPE_TEST_CREDENTIALS=/etc/telescope/key.json');

    $settings = settings([
        'propertyId' => '$TELESCOPE_TEST_PROPERTY',
        'credentials' => '$TELESCOPE_TEST_CREDENTIALS',
    ]);

    expect($settings->getPropertyIdForSite())->toBe('properties/555000111')
        ->and($settings->getCredentials())->toBe('/etc/telescope/key.json');

    putenv('TELESCOPE_TEST_PROPERTY');
    putenv('TELESCOPE_TEST_CREDENTIALS');
});

it('validates a property ID held in an environment variable', function() {
    putenv('TELESCOPE_TEST_PROPERTY=G-NOPE');

    $settings = configuredSettings(['propertyId' => '$TELESCOPE_TEST_PROPERTY']);
    $valid = $settings->validate();

    putenv('TELESCOPE_TEST_PROPERTY');

    expect($valid)->toBeFalse()
        ->and($settings->getErrors('propertyId')[0])->toContain('measurement ID');
});

it('is not configured until both credentials and a property exist', function() {
    expect(settings()->isConfigured())->toBeFalse()
        ->and(settings(['propertyId' => '123456789'])->isConfigured())->toBeFalse()
        ->and(settings(['credentials' => '{}'])->isConfigured())->toBeFalse()
        ->and(configuredSettings()->isConfigured())->toBeTrue();
});

it('is not configured in OAuth mode until all three OAuth values exist', function() {
    $partial = settings([
        'authMode' => Settings::AUTH_OAUTH,
        'propertyId' => '123456789',
        'clientId' => 'id',
        'clientSecret' => 'secret',
    ]);

    expect($partial->isConfigured())->toBeFalse();

    $partial->refreshToken = 'refresh';

    expect($partial->isConfigured())->toBeTrue();
});

it('reports configuration per site', function() {
    $settings = settings([
        'credentials' => '{}',
        'propertyId' => '',
        'sitePropertyIds' => ['shop' => '987654321'],
    ]);

    expect($settings->isConfigured('shop'))->toBeTrue()
        ->and($settings->isConfigured('default'))->toBeFalse();
});

it('clamps limits and durations to usable values', function() {
    $settings = settings(['rowLimit' => 5000, 'widgetLimit' => 0, 'cacheDuration' => -30]);

    expect($settings->getRowLimit())->toBe(100)
        ->and($settings->getWidgetLimit())->toBe(1)
        ->and($settings->getCacheDuration())->toBe(0);
});

it('normalises the stored section list', function() {
    $settings = settings(['sections' => ['sources', 'nonsense']]);

    expect($settings->getSections())->toBe([ReportSection::OVERVIEW, ReportSection::SOURCES]);
});

it('resolves the default period into a usable period', function() {
    expect(settings(['defaultPeriod' => 'last90days'])->getDefaultPeriod()->startDate)->toBe('90daysAgo')
        ->and(settings(['defaultPeriod' => 'rubbish'])->getDefaultPeriod()->handle)->toBe('last28days');
});

it('does not auto-attach to entries unless switched on', function() {
    expect(settings()->shouldAutoAttach('news'))->toBeFalse();
});

it('auto-attaches to every section when no sections are chosen', function() {
    $settings = settings(['autoAttachToEntries' => true]);

    expect($settings->shouldAutoAttach('news'))->toBeTrue()
        ->and($settings->shouldAutoAttach(null))->toBeTrue();
});

it('auto-attaches only to the chosen sections', function() {
    $settings = settings([
        'autoAttachToEntries' => true,
        'autoAttachSections' => ['properties'],
    ]);

    expect($settings->shouldAutoAttach('properties'))->toBeTrue()
        ->and($settings->shouldAutoAttach('news'))->toBeFalse()
        ->and($settings->shouldAutoAttach(null))->toBeFalse();
});
