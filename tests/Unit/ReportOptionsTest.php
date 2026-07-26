<?php

declare(strict_types=1);

use justinholtweb\telescope\ga4\ReportRequest;
use justinholtweb\telescope\reports\ReportOptions;
use justinholtweb\telescope\reports\ReportSection;

it('defaults to exact matching of every section', function() {
    $options = new ReportOptions();

    expect($options->matchType)->toBe(ReportRequest::MATCH_EXACT)
        ->and($options->sections)->toBe(ReportSection::ALL)
        ->and($options->hostname)->toBeNull()
        ->and($options->rowLimit)->toBe(10);
});

it('knows which sections it wants', function() {
    $options = new ReportOptions(sections: [ReportSection::OVERVIEW, ReportSection::TIMELINE]);

    expect($options->wants(ReportSection::TIMELINE))->toBeTrue()
        ->and($options->wants(ReportSection::GEOGRAPHY))->toBeFalse();
});

it('copies itself with new sections or a new hostname', function() {
    $options = new ReportOptions(rowLimit: 25);

    expect($options->withSections([ReportSection::OVERVIEW])->sections)->toBe([ReportSection::OVERVIEW])
        ->and($options->withSections([ReportSection::OVERVIEW])->rowLimit)->toBe(25)
        ->and($options->withHostname('example.com')->hostname)->toBe('example.com')
        ->and($options->hostname)->toBeNull();
});

it('fingerprints identical options identically', function() {
    expect((new ReportOptions())->fingerprint())->toBe((new ReportOptions())->fingerprint());
});

it('changes its fingerprint when anything that shapes a report changes', function() {
    $base = new ReportOptions();

    expect($base->fingerprint())->not->toBe((new ReportOptions(matchType: ReportRequest::MATCH_BEGINS_WITH))->fingerprint())
        ->and($base->fingerprint())->not->toBe((new ReportOptions(hostname: 'example.com'))->fingerprint())
        ->and($base->fingerprint())->not->toBe((new ReportOptions(rowLimit: 25))->fingerprint())
        ->and($base->fingerprint())->not->toBe($base->withSections([ReportSection::OVERVIEW])->fingerprint());
});

it('ignores known hosts in the fingerprint, since they do not change the query', function() {
    expect((new ReportOptions(knownHosts: ['a.com']))->fingerprint())
        ->toBe((new ReportOptions(knownHosts: ['b.com']))->fingerprint());
});

it('lists every section with a label', function() {
    $options = ReportSection::options();

    expect(array_keys($options))->toBe(ReportSection::ALL)
        ->and($options[ReportSection::LANDING_PAGES])->toBe('Landing pages');
});

it('normalises a section list into canonical order', function() {
    $sections = ReportSection::normalize([ReportSection::SOURCES, ReportSection::OVERVIEW, ReportSection::TIMELINE]);

    expect($sections)->toBe([ReportSection::OVERVIEW, ReportSection::TIMELINE, ReportSection::SOURCES]);
});

it('discards section handles it does not recognise', function() {
    expect(ReportSection::normalize(['overview', 'conversions', 'revenue']))->toBe([ReportSection::OVERVIEW]);
});

it('always keeps the overview, which everything else is measured against', function() {
    expect(ReportSection::normalize([ReportSection::TIMELINE]))
        ->toBe([ReportSection::OVERVIEW, ReportSection::TIMELINE]);
});

it('falls back to every section when the setting is not a list', function(mixed $value) {
    expect(ReportSection::normalize($value))->toBe(ReportSection::ALL);
})->with([null, 'overview', 42]);

it('returns just the overview for an empty list', function() {
    expect(ReportSection::normalize([]))->toBe([ReportSection::OVERVIEW]);
});
