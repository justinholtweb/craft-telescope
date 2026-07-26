<?php

declare(strict_types=1);

use justinholtweb\telescope\helpers\Format;

it('formats sub-minute durations in seconds', function() {
    expect(Format::duration(0))->toBe('0s')
        ->and(Format::duration(47))->toBe('47s')
        ->and(Format::duration(59.4))->toBe('59s');
});

it('rolls a rounded-up 59.6 seconds over into a minute', function() {
    expect(Format::duration(59.6))->toBe('1m 0s');
});

it('formats minutes and seconds', function() {
    expect(Format::duration(134))->toBe('2m 14s')
        ->and(Format::duration(60))->toBe('1m 0s');
});

it('formats hours with padded minutes', function() {
    expect(Format::duration(3600))->toBe('1h 00m')
        ->and(Format::duration(3780))->toBe('1h 03m')
        ->and(Format::duration(7500))->toBe('2h 05m');
});

it('treats negative and non-finite durations as zero', function() {
    expect(Format::duration(-10))->toBe('0s')
        ->and(Format::duration(NAN))->toBe('0s')
        ->and(Format::duration(INF))->toBe('0s');
});

it('formats a 0–1 ratio as a percentage', function() {
    expect(Format::percent(0.6234))->toBe('62.3%')
        ->and(Format::percent(0))->toBe('0.0%')
        ->and(Format::percent(1))->toBe('100.0%');
});

it('leaves a value already expressed as a percentage alone', function() {
    // A couple of GA4 rate metrics come back as 0–100 rather than 0–1.
    expect(Format::percent(62.34))->toBe('62.3%');
});

it('honours the requested precision', function() {
    expect(Format::percent(0.6234, 0))->toBe('62%')
        ->and(Format::percent(0.6234, 2))->toBe('62.34%');
});

it('formats numbers with thousands separators', function() {
    expect(Format::number(1234567))->toBe('1,234,567')
        ->and(Format::number(0))->toBe('0');
});

it('leaves numbers under ten thousand uncompacted', function() {
    expect(Format::compact(9999))->toBe('9,999')
        ->and(Format::compact(0))->toBe('0');
});

it('compacts thousands, millions and billions', function() {
    expect(Format::compact(12300))->toBe('12.3K')
        ->and(Format::compact(1400000))->toBe('1.4M')
        ->and(Format::compact(2500000000))->toBe('2.5B');
});

it('drops a trailing zero decimal when compacting', function() {
    expect(Format::compact(20000))->toBe('20K')
        ->and(Format::compact(3000000))->toBe('3M');
});

it('keeps the sign when compacting negatives', function() {
    expect(Format::compact(-12300))->toBe('-12.3K');
});

it('converts GA4 date dimensions to ISO dates', function() {
    expect(Format::date('20260131'))->toBe('2026-01-31');
});

it('passes through a date it does not recognise', function() {
    expect(Format::date('2026-01-31'))->toBe('2026-01-31')
        ->and(Format::date('(other)'))->toBe('(other)');
});

it('calculates percentage change', function() {
    expect(Format::change(150, 100))->toBe(50.0)
        ->and(Format::change(50, 100))->toBe(-50.0)
        ->and(Format::change(100, 100))->toBe(0.0);
});

it('reports no change when there is no baseline to compare against', function() {
    expect(Format::change(100, 0))->toBeNull();
});

it('reports zero change when both sides are zero', function() {
    expect(Format::change(0, 0))->toBe(0.0);
});
