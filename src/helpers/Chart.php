<?php

declare(strict_types=1);

namespace justinholtweb\telescope\helpers;

use InvalidArgumentException;

/**
 * Renders the timeline chart as inline SVG.
 *
 * Drawing the chart server-side keeps the plugin free of a JavaScript charting
 * library — nothing is fetched from a CDN, nothing breaks under a strict CSP,
 * and the print/PDF view gets the identical chart the editor sees on screen.
 * The geometry is plain arithmetic, so it is unit-tested directly.
 */
final class Chart
{
    private const PADDING_LEFT = 44;
    private const PADDING_RIGHT = 12;
    private const PADDING_TOP = 12;
    private const PADDING_BOTTOM = 26;

    /**
     * Fallbacks used as presentation attributes, for when the SVG is rendered
     * without the plugin's stylesheet — as a standalone image in the PDF. CSS
     * still wins wherever the stylesheet does reach it.
     */
    private const GRID_COLOR = '#e5e7eb';
    private const AXIS_COLOR = '#6b7280';

    /**
     * Round a maximum value up to a "nice" axis bound (1, 2 or 5 × a power of ten).
     */
    public static function niceMax(float $max): float
    {
        if (!is_finite($max) || $max <= 0.0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($max));
        $normalised = $max / $magnitude;

        $step = match (true) {
            $normalised <= 1.0 => 1.0,
            $normalised <= 2.0 => 2.0,
            $normalised <= 5.0 => 5.0,
            default => 10.0,
        };

        return $step * $magnitude;
    }

    /**
     * Plot values into SVG coordinates inside the chart's inner drawing area.
     *
     * A single data point is centred horizontally — a line chart with one point
     * would otherwise collapse onto the left edge.
     *
     * @param list<float|int> $values
     * @return list<array{x: float, y: float}>
     */
    public static function points(array $values, float $width, float $height, float $max): array
    {
        if ($values === []) {
            return [];
        }

        if ($max <= 0.0) {
            $max = 1.0;
        }

        $count = count($values);
        $innerWidth = $width - self::PADDING_LEFT - self::PADDING_RIGHT;
        $innerHeight = $height - self::PADDING_TOP - self::PADDING_BOTTOM;

        $points = [];

        foreach (array_values($values) as $index => $value) {
            $ratio = $count === 1 ? 0.5 : $index / ($count - 1);
            $x = self::PADDING_LEFT + ($ratio * $innerWidth);
            $y = self::PADDING_TOP + $innerHeight - (min((float)$value, $max) / $max * $innerHeight);

            $points[] = [
                'x' => round($x, 2),
                'y' => round($y, 2),
            ];
        }

        return $points;
    }

    /**
     * An SVG `d` attribute connecting the given points with straight segments.
     *
     * @param list<array{x: float, y: float}> $points
     */
    public static function path(array $points): string
    {
        if ($points === []) {
            return '';
        }

        $commands = [];

        foreach ($points as $index => $point) {
            $commands[] = sprintf('%s%s %s', $index === 0 ? 'M' : 'L', $point['x'], $point['y']);
        }

        return implode(' ', $commands);
    }

    /**
     * The same path closed back down to the baseline, for the fill under a line.
     *
     * @param list<array{x: float, y: float}> $points
     */
    public static function areaPath(array $points, float $height): string
    {
        if ($points === []) {
            return '';
        }

        $baseline = round($height - self::PADDING_BOTTOM, 2);
        $first = $points[0];
        $last = $points[count($points) - 1];

        return sprintf(
            '%s L%s %s L%s %s Z',
            self::path($points),
            $last['x'],
            $baseline,
            $first['x'],
            $baseline,
        );
    }

    /**
     * Render a multi-series line chart.
     *
     * @param list<array{label: string, color: string, values: list<float|int>}> $series
     * @param list<string> $labels one x-axis label per data point
     */
    public static function line(array $series, array $labels, int $width = 960, int $height = 240): string
    {
        if ($series === []) {
            throw new InvalidArgumentException('A chart needs at least one series.');
        }

        $max = 0.0;
        foreach ($series as $set) {
            foreach ($set['values'] as $value) {
                $max = max($max, (float)$value);
            }
        }

        $axisMax = self::niceMax($max);
        $innerHeight = $height - self::PADDING_TOP - self::PADDING_BOTTOM;

        // Explicit width and height as well as a viewBox: CSS still makes it
        // fluid on screen, but an SVG with no intrinsic size rasterises to
        // nothing when it is used as an image — which is how the PDF gets it.
        // No preserveAspectRatio override, or the axis labels stretch along
        // with the plot area.
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="telescope-chart" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s">',
            $width,
            $height,
            $width,
            $height,
            htmlspecialchars(self::describe($series), ENT_QUOTES),
        );

        // Horizontal grid lines and their y-axis labels.
        for ($i = 0; $i <= 4; $i++) {
            $y = round(self::PADDING_TOP + ($innerHeight * $i / 4), 2);
            $value = $axisMax * (1 - $i / 4);

            // Presentation attributes as well as classes: the same markup is
            // rasterised as a standalone `data:image/svg+xml` for the PDF,
            // where no stylesheet reaches it.
            $svg .= sprintf(
                '<line class="telescope-chart-grid" x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1" />',
                self::PADDING_LEFT,
                $y,
                $width - self::PADDING_RIGHT,
                $y,
                self::GRID_COLOR,
            );

            $svg .= sprintf(
                '<text class="telescope-chart-axis" x="%s" y="%s" text-anchor="end" fill="%s" font-size="10" font-family="system-ui, sans-serif">%s</text>',
                self::PADDING_LEFT - 6,
                $y + 3,
                self::AXIS_COLOR,
                htmlspecialchars(Format::compact($value), ENT_QUOTES),
            );
        }

        foreach ($series as $index => $set) {
            $points = self::points($set['values'], (float)$width, (float)$height, $axisMax);

            if ($points === []) {
                continue;
            }

            $svg .= sprintf(
                '<path class="telescope-chart-area" d="%s" fill="%s" fill-opacity="0.10" stroke="none" />',
                self::areaPath($points, (float)$height),
                htmlspecialchars($set['color'], ENT_QUOTES),
            );

            $svg .= sprintf(
                '<path class="telescope-chart-line" d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" data-series="%d" />',
                self::path($points),
                htmlspecialchars($set['color'], ENT_QUOTES),
                $index,
            );
        }

        // One transparent hover target per x position, carrying the values for
        // every series so the tooltip can be built without a second data source.
        $pointCount = count($series[0]['values']);

        if ($pointCount > 0) {
            $firstPoints = self::points($series[0]['values'], (float)$width, (float)$height, $axisMax);

            foreach ($firstPoints as $index => $point) {
                $tooltip = [];

                foreach ($series as $set) {
                    $tooltip[] = $set['label'] . ': ' . Format::number((float)($set['values'][$index] ?? 0));
                }

                // fill="none" so the hover targets stay invisible when the SVG
                // is rendered as a standalone image, where the stylesheet that
                // would otherwise make them transparent does not reach. CSS
                // still wins on screen, where they need to be hoverable.
                $svg .= sprintf(
                    '<rect class="telescope-chart-hit" fill="none" pointer-events="all" x="%s" y="%s" width="%s" height="%s" data-label="%s" data-values="%s" />',
                    round($point['x'] - self::hitWidth($width, $pointCount) / 2, 2),
                    self::PADDING_TOP,
                    round(self::hitWidth($width, $pointCount), 2),
                    $innerHeight,
                    htmlspecialchars($labels[$index] ?? '', ENT_QUOTES),
                    htmlspecialchars(implode(' · ', $tooltip), ENT_QUOTES),
                );
            }
        }

        // Sparse x-axis labels — at most six, so they never overlap.
        $labelCount = count($labels);

        if ($labelCount > 0) {
            $step = max(1, (int)ceil($labelCount / 6));
            $firstPoints = self::points($series[0]['values'], (float)$width, (float)$height, $axisMax);

            for ($i = 0; $i < $labelCount; $i += $step) {
                if (!isset($firstPoints[$i])) {
                    continue;
                }

                $svg .= sprintf(
                    '<text class="telescope-chart-axis" x="%s" y="%s" text-anchor="middle" fill="%s" font-size="10" font-family="system-ui, sans-serif">%s</text>',
                    $firstPoints[$i]['x'],
                    $height - 8,
                    self::AXIS_COLOR,
                    htmlspecialchars($labels[$i], ENT_QUOTES),
                );
            }
        }

        return $svg . '</svg>';
    }

    private static function hitWidth(int $width, int $pointCount): float
    {
        $innerWidth = $width - self::PADDING_LEFT - self::PADDING_RIGHT;

        return $pointCount > 0 ? $innerWidth / $pointCount : $innerWidth;
    }

    /**
     * @param list<array{label: string, color: string, values: list<float|int>}> $series
     */
    private static function describe(array $series): string
    {
        return 'Chart of ' . implode(' and ', array_column($series, 'label')) . ' over time';
    }
}
