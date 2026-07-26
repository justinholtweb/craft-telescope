<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\Period;

it('accepts the three date formats GA4 understands', function() {
    expect(Period::isValidDate('today'))->toBeTrue()
        ->and(Period::isValidDate('yesterday'))->toBeTrue()
        ->and(Period::isValidDate('7daysAgo'))->toBeTrue()
        ->and(Period::isValidDate('365daysAgo'))->toBeTrue()
        ->and(Period::isValidDate('2026-01-31'))->toBeTrue();
});

it('rejects dates GA4 would reject', function(string $date) {
    expect(Period::isValidDate($date))->toBeFalse();
})->with([
    'last week',
    'daysAgo',
    '-7daysAgo',
    '2026-13-01',
    '2026-02-30',
    '26-01-01',
    '',
]);

it('builds a period from two dates', function() {
    $period = Period::create('30daysAgo', 'yesterday');

    expect($period->startDate)->toBe('30daysAgo')
        ->and($period->endDate)->toBe('yesterday')
        ->and($period->toArray())->toBe(['startDate' => '30daysAgo', 'endDate' => 'yesterday']);
});

it('defaults the end date to today', function() {
    expect(Period::create('7daysAgo')->endDate)->toBe('today');
});

it('refuses to build a period from an invalid date', function() {
    Period::create('sometime last year');
})->throws(InvalidArgumentException::class, 'Invalid GA4 start date');

it('exposes presets with labels', function() {
    $options = Period::presetOptions();

    expect($options)->toHaveKey('last28days')
        ->and($options['last28days'])->toBe('Last 28 days')
        ->and($options['allTime'])->toBe('All time');
});

it('builds a preset period', function() {
    $period = Period::preset('last90days');

    expect($period->startDate)->toBe('90daysAgo')
        ->and($period->label)->toBe('Last 90 days')
        ->and($period->handle)->toBe('last90days');
});

it('starts the all-time period at the earliest date the API accepts', function() {
    expect(Period::preset('allTime')->startDate)->toBe(Period::EARLIEST_DATE)
        ->and(Period::EARLIEST_DATE)->toBe('2015-08-14');
});

it('rejects an unknown preset', function() {
    Period::preset('lastFortnight');
})->throws(InvalidArgumentException::class, 'Unknown period preset');

it('resolves a preset handle', function() {
    expect(Period::resolve('last7days')->handle)->toBe('last7days');
});

it('resolves a raw GA4 date', function() {
    $period = Period::resolve('45daysAgo');

    expect($period->startDate)->toBe('45daysAgo')
        ->and($period->handle)->toBeNull();
});

it('falls back when the value is empty or nonsense', function(?string $value) {
    expect(Period::resolve($value)->handle)->toBe('last28days');
})->with([null, '', '   ', 'not a period']);

it('uses the supplied fallback before the built-in default', function() {
    expect(Period::resolve('nonsense', 'last7days')->handle)->toBe('last7days');
});

it('falls through a nonsense fallback to the default rather than throwing', function() {
    // A site owner can type anything into the default-period setting, and that
    // value is used as the fallback everywhere.
    expect(Period::resolve(null, 'also nonsense')->handle)->toBe('last28days');
});

it('accepts a raw date as the fallback', function() {
    expect(Period::resolve('', '90daysAgo')->startDate)->toBe('90daysAgo');
});

it('uses the preset handle as its cache key', function() {
    expect(Period::preset('last30days')->cacheKey())->toBe('last30days');
});

it('builds a cache key from the dates when there is no preset', function() {
    expect(Period::create('2026-01-01', '2026-02-01')->cacheKey())->toBe('2026-01-01_2026-02-01');
});

it('gives different periods different cache keys', function() {
    expect(Period::preset('last7days')->cacheKey())
        ->not->toBe(Period::preset('last30days')->cacheKey());
});
