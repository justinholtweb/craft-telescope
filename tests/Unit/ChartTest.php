<?php

declare(strict_types=1);

use justinholtweb\telescope\helpers\Chart;

it('rounds axis maxima up to a nice number', function(float $max, float $expected) {
    expect(Chart::niceMax($max))->toBe($expected);
})->with([
    [1.0, 1.0],
    [7.0, 10.0],
    [12.0, 20.0],
    [45.0, 50.0],
    [96.0, 100.0],
    [1234.0, 2000.0],
]);

it('never returns a zero or negative axis maximum', function(float $max) {
    expect(Chart::niceMax($max))->toBe(1.0);
})->with([0.0, -5.0, NAN]);

it('plots the first and last points at the edges of the drawing area', function() {
    $points = Chart::points([0, 10], 720.0, 240.0, 10.0);

    expect($points[0]['x'])->toBe(44.0)
        ->and($points[1]['x'])->toBe(708.0);
});

it('plots a higher value higher up the chart', function() {
    $points = Chart::points([1, 10], 720.0, 240.0, 10.0);

    expect($points[1]['y'])->toBeLessThan($points[0]['y']);
});

it('puts the maximum value at the top of the drawing area', function() {
    $points = Chart::points([10], 720.0, 240.0, 10.0);

    expect($points[0]['y'])->toBe(12.0);
});

it('puts a zero value on the baseline', function() {
    $points = Chart::points([0], 720.0, 240.0, 10.0);

    expect($points[0]['y'])->toBe(214.0);
});

it('centres a single data point instead of pinning it to the left edge', function() {
    $points = Chart::points([5], 720.0, 240.0, 10.0);

    expect($points[0]['x'])->toBe(376.0);
});

it('clamps a value above the axis maximum to the top', function() {
    $points = Chart::points([50], 720.0, 240.0, 10.0);

    expect($points[0]['y'])->toBe(12.0);
});

it('treats a zero axis maximum as one rather than dividing by zero', function() {
    $points = Chart::points([0, 0], 720.0, 240.0, 0.0);

    expect($points)->toHaveCount(2)
        ->and($points[0]['y'])->toBe(214.0);
});

it('plots nothing for no values', function() {
    expect(Chart::points([], 720.0, 240.0, 10.0))->toBe([]);
});

it('builds an SVG path that moves once and then draws lines', function() {
    $path = Chart::path([['x' => 0.0, 'y' => 10.0], ['x' => 5.0, 'y' => 2.0], ['x' => 9.0, 'y' => 6.0]]);

    expect($path)->toBe('M0 10 L5 2 L9 6');
});

it('builds no path for no points', function() {
    expect(Chart::path([]))->toBe('')
        ->and(Chart::areaPath([], 240.0))->toBe('');
});

it('closes the area path down to the baseline', function() {
    $area = Chart::areaPath([['x' => 44.0, 'y' => 20.0], ['x' => 100.0, 'y' => 50.0]], 240.0);

    expect($area)->toBe('M44 20 L100 50 L100 214 L44 214 Z');
});

it('renders a chart with grid lines, axis labels and both series', function() {
    $svg = Chart::line([
        ['label' => 'Page views', 'color' => '#2563eb', 'values' => [5, 10, 3]],
        ['label' => 'Visitors', 'color' => '#0d9488', 'values' => [4, 8, 2]],
    ], ['Jan 1', 'Jan 2', 'Jan 3']);

    expect($svg)->toStartWith('<svg')
        ->and($svg)->toEndWith('</svg>')
        ->and($svg)->toContain('viewBox="0 0 960 240"')
        // Uniform scaling, or the axis labels stretch with the plot area.
        ->and($svg)->not->toContain('preserveAspectRatio')
        ->and(substr_count($svg, 'telescope-chart-line'))->toBe(2)
        ->and(substr_count($svg, 'telescope-chart-grid'))->toBe(5)
        ->and($svg)->toContain('stroke="#2563eb"')
        ->and($svg)->toContain('stroke="#0d9488"');
});

it('adds one hover target per data point carrying both series values', function() {
    $svg = Chart::line([
        ['label' => 'Page views', 'color' => '#2563eb', 'values' => [5, 10]],
        ['label' => 'Visitors', 'color' => '#0d9488', 'values' => [4, 8]],
    ], ['Jan 1', 'Jan 2']);

    expect(substr_count($svg, 'telescope-chart-hit'))->toBe(2)
        ->and($svg)->toContain('data-values="Page views: 5 · Visitors: 4"')
        ->and($svg)->toContain('data-label="Jan 2"')
        // Unfilled, or they render as black bars once the SVG is used as an
        // image and the stylesheet no longer applies.
        ->and($svg)->toContain('class="telescope-chart-hit" fill="none"');
});

it('escapes labels so a page title cannot inject markup', function() {
    $svg = Chart::line(
        [['label' => 'Views', 'color' => '#000', 'values' => [1]]],
        ['<script>alert(1)</script>'],
    );

    expect($svg)->not->toContain('<script>')
        ->and($svg)->toContain('&lt;script&gt;');
});

it('thins x-axis labels down to at most six', function() {
    $values = range(1, 30);
    $labels = array_map(static fn(int $day): string => "Jan {$day}", $values);

    $svg = Chart::line([['label' => 'Views', 'color' => '#000', 'values' => $values]], $labels);

    expect(substr_count($svg, 'text-anchor="middle"'))->toBeLessThanOrEqual(6);
});

it('refuses to render with no series at all', function() {
    Chart::line([], []);
})->throws(InvalidArgumentException::class, 'at least one series');

it('renders when every value is zero', function() {
    $svg = Chart::line([['label' => 'Views', 'color' => '#000', 'values' => [0, 0, 0]]], ['a', 'b', 'c']);

    expect($svg)->toContain('telescope-chart-line');
});

it('carries its colours as presentation attributes, not only CSS classes', function() {
    // The PDF rasterises this SVG as a standalone image, where no stylesheet
    // reaches it — without inline colours the grid and axis labels vanish.
    $svg = Chart::line([['label' => 'Views', 'color' => '#2563eb', 'values' => [1, 2]]], ['Jan 1', 'Jan 2']);

    expect($svg)->toContain('class="telescope-chart-grid"')
        ->and($svg)->toContain('stroke="#e5e7eb"')
        ->and($svg)->toContain('class="telescope-chart-axis"')
        ->and($svg)->toContain('fill="#6b7280"')
        ->and($svg)->toContain('font-family=');
});

it('renders standalone, with no external references', function() {
    $svg = Chart::line([['label' => 'Views', 'color' => '#2563eb', 'values' => [3, 1, 4]]], ['a', 'b', 'c']);

    expect($svg)->not->toContain('url(')
        ->and($svg)->not->toContain('<style');
});

it('declares an intrinsic size and a namespace, so it works as an image', function() {
    // An SVG with only a viewBox rasterises to nothing when used as an <img>
    // src, which is exactly how the PDF export consumes it.
    $svg = Chart::line([['label' => 'Views', 'color' => '#2563eb', 'values' => [1, 2]]], ['a', 'b']);

    expect($svg)->toContain('xmlns="http://www.w3.org/2000/svg"')
        ->and($svg)->toContain('width="960"')
        ->and($svg)->toContain('height="240"')
        ->and($svg)->toContain('viewBox="0 0 960 240"');
});
