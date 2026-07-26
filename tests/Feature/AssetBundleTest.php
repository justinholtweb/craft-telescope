<?php

declare(strict_types=1);

use justinholtweb\telescope\web\assets\cp\TelescopeAsset;
use justinholtweb\telescope\web\assets\cp\TelescopeReportAsset;

it('ships the plugin\'s own stylesheet and script', function() {
    expect(TelescopeAsset::CSS)->toBe(['css/telescope.css'])
        ->and(TelescopeAsset::JS)->toBe(['js/telescope.js']);
});

it('publishes files that actually exist', function(string $file) {
    expect(TelescopeAsset::SOURCE_PATH . '/' . $file)->toBeReadableFile();
})->with([...TelescopeAsset::CSS, ...TelescopeAsset::JS]);

it('loads the chart and PDF libraries only in the report bundle', function() {
    // Half a megabyte of JavaScript has no business on every entry that shows
    // the compact sidebar panel.
    expect(TelescopeAsset::JS)->toHaveCount(1)
        ->and(TelescopeReportAsset::JS)->toHaveCount(5);
});

it('registers the zoom plugin after both Chart.js and Hammer', function() {
    // chartjs-plugin-zoom registers itself against Chart and needs Hammer for
    // pan and pinch, so load order is not cosmetic.
    $js = TelescopeReportAsset::JS;
    $zoom = array_search(TelescopeReportAsset::CHART_ZOOM_JS, $js, true);

    expect($zoom)->toBeGreaterThan(array_search(TelescopeReportAsset::CHART_JS, $js, true))
        ->and($zoom)->toBeGreaterThan(array_search(TelescopeReportAsset::HAMMER_JS, $js, true));
});

it('pins every third-party library to an exact version, over https', function(string $url) {
    // An unpinned CDN URL is a silent breakage waiting to happen on somebody
    // else's site.
    expect($url)->toStartWith('https://cdn.jsdelivr.net/npm/')
        ->and($url)->toMatch('/@\d+\.\d+\.\d+\//');
})->with(TelescopeReportAsset::JS);

it('loads jsPDF as a UMD build, which is what exposes window.jspdf', function() {
    expect(TelescopeReportAsset::JSPDF_JS)->toContain('jspdf.umd.min.js');
});
