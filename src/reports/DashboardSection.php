<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

/**
 * The panels the site-wide dashboard is made of.
 *
 * Deliberately separate from {@see ReportSection}: that one describes a single
 * page's report, this one a whole property. They overlap in name only — the
 * dashboard's "sources" panel is site-wide, the page report's is filtered to
 * one path — and they are toggled independently because they are paid for on
 * different screens.
 *
 * Each panel is one GA4 API call.
 */
final class DashboardSection
{
    public const TIMELINE = 'timeline';
    public const SOURCES = 'sources';
    public const GEOGRAPHY = 'geography';
    public const DEVICES = 'devices';
    public const BROWSERS = 'browsers';

    /**
     * The totals and the top-pages table are the dashboard — without them the
     * screen has nothing on it — so neither is optional and neither appears
     * here.
     *
     * @var list<string>
     */
    public const ALL = [
        self::TIMELINE,
        self::SOURCES,
        self::GEOGRAPHY,
        self::DEVICES,
        self::BROWSERS,
    ];

    /**
     * Labels for the settings screen.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::TIMELINE => 'Traffic over time',
            self::SOURCES => 'Traffic sources',
            self::GEOGRAPHY => 'Top countries',
            self::DEVICES => 'Devices',
            self::BROWSERS => 'Browsers',
        ];
    }

    /**
     * Keep only recognised panel handles, in canonical order, so the dashboard
     * always lays out the same way.
     *
     * @return list<string>
     */
    public static function normalize(mixed $sections): array
    {
        if (!is_array($sections)) {
            return self::ALL;
        }

        return array_values(array_filter(
            self::ALL,
            static fn(string $section): bool => in_array($section, $sections, true),
        ));
    }
}
