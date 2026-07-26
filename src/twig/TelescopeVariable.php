<?php

declare(strict_types=1);

namespace justinholtweb\telescope\twig;

use craft\base\ElementInterface;
use justinholtweb\telescope\ga4\Period;
use justinholtweb\telescope\helpers\Chart;
use justinholtweb\telescope\helpers\Format;
use justinholtweb\telescope\Plugin;
use justinholtweb\telescope\reports\PageReport;
use yii\base\Behavior;

/**
 * `craft.telescope.*` — the Twig API, available to control panel templates and
 * to front-end templates that want to surface their own numbers.
 */
class TelescopeVariable extends Behavior
{
    /**
     * The report for an element.
     */
    public function report(ElementInterface $element, ?string $period = null): PageReport
    {
        return Plugin::getInstance()?->getAnalytics()->getReportForElement(
            $element,
            $period !== null ? Period::resolve($period) : null,
        ) ?? PageReport::empty();
    }

    /**
     * The report for an arbitrary URL or path.
     */
    public function reportForUrl(string $url, ?int $siteId = null, ?string $period = null): PageReport
    {
        return Plugin::getInstance()?->getAnalytics()->getReport(
            $url,
            $siteId,
            $period !== null ? Period::resolve($period) : null,
        ) ?? PageReport::empty();
    }

    /**
     * The property's most-viewed pages.
     *
     * @return list<array{path: string, title: string, views: int, users: int}>
     */
    public function topPages(?int $siteId = null, ?string $period = null, ?int $limit = null): array
    {
        return Plugin::getInstance()?->getAnalytics()->getTopPages(
            $siteId,
            $period !== null ? Period::resolve($period) : null,
            $limit,
        ) ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function periods(): array
    {
        return Period::presetOptions();
    }

    public function isConfigured(): bool
    {
        return Plugin::getInstance()?->getSettings()->isConfigured() ?? false;
    }

    /**
     * Render a timeline as inline SVG.
     *
     * @param list<array{date: string, views: int, users: int}> $timeline
     */
    public function chart(array $timeline, int $width = 960, int $height = 240): string
    {
        if ($timeline === []) {
            return '';
        }

        return Chart::line(
            [
                ['label' => 'Page views', 'color' => '#2563eb', 'values' => array_column($timeline, 'views')],
                ['label' => 'Visitors', 'color' => '#0d9488', 'values' => array_column($timeline, 'users')],
            ],
            array_map(
                static fn(string $date): string => date('M j', strtotime($date) ?: time()),
                array_column($timeline, 'date'),
            ),
            $width,
            $height,
        );
    }

    public function duration(float $seconds): string
    {
        return Format::duration($seconds);
    }

    public function percent(float $ratio, int $decimals = 1): string
    {
        return Format::percent($ratio, $decimals);
    }

    public function compact(float|int $value): string
    {
        return Format::compact($value);
    }
}
