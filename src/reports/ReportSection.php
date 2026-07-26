<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

/**
 * The sections a page report is made of.
 *
 * Each one costs a separate GA4 API call, so they can be switched off in
 * settings on properties where quota matters.
 */
final class ReportSection
{
    public const OVERVIEW = 'overview';
    public const TIMELINE = 'timeline';
    public const GEOGRAPHY = 'geography';
    public const SOURCES = 'sources';
    public const REFERRERS = 'referrers';
    public const LANDING_PAGES = 'landingPages';

    /** @var list<string> */
    public const ALL = [
        self::OVERVIEW,
        self::TIMELINE,
        self::GEOGRAPHY,
        self::SOURCES,
        self::REFERRERS,
        self::LANDING_PAGES,
    ];

    /**
     * Labels for the settings screen.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::OVERVIEW => 'Overview totals',
            self::TIMELINE => 'Views over time',
            self::GEOGRAPHY => 'Top locations',
            self::SOURCES => 'Traffic sources',
            self::REFERRERS => 'Referring pages',
            self::LANDING_PAGES => 'Landing pages',
        ];
    }

    /**
     * Keep only recognised section handles, preserving the canonical order so
     * the report always renders in the same sequence.
     *
     * @param mixed $sections
     * @return list<string>
     */
    public static function normalize(mixed $sections): array
    {
        if (!is_array($sections)) {
            return self::ALL;
        }

        $valid = array_values(array_filter(
            self::ALL,
            static fn(string $section): bool => in_array($section, $sections, true),
        ));

        // The overview is what the cards and the "has data" check are built on,
        // so it is never optional.
        if (!in_array(self::OVERVIEW, $valid, true)) {
            array_unshift($valid, self::OVERVIEW);
        }

        return $valid;
    }
}
