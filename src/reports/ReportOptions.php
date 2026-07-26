<?php

declare(strict_types=1);

namespace justinholtweb\telescope\reports;

use justinholtweb\telescope\ga4\ReportRequest;

/**
 * How a report should be scoped and shaped.
 *
 * Bundled into one object because these five values travel together through
 * the service, the builder and the console commands.
 */
final class ReportOptions
{
    /**
     * @param string $matchType how `pagePath` is matched — see {@see ReportRequest}
     * @param string|null $hostname restrict to one hostname, for GA4 properties
     *                              shared by several Craft sites
     * @param int $rowLimit rows to fetch for the breakdown tables
     * @param list<string> $knownHosts hostnames treated as "internal" referrers
     * @param list<string> $sections which report sections to fetch
     */
    public function __construct(
        public readonly string $matchType = ReportRequest::MATCH_EXACT,
        public readonly ?string $hostname = null,
        public readonly int $rowLimit = 10,
        public readonly array $knownHosts = [],
        public readonly array $sections = ReportSection::ALL,
    ) {
    }

    public function wants(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }

    /**
     * @param list<string> $sections
     */
    public function withSections(array $sections): self
    {
        return new self($this->matchType, $this->hostname, $this->rowLimit, $this->knownHosts, $sections);
    }

    public function withHostname(?string $hostname): self
    {
        return new self($this->matchType, $hostname, $this->rowLimit, $this->knownHosts, $this->sections);
    }

    /**
     * A stable identifier for these options, mixed into cache keys so a
     * settings change cannot serve a stale report shaped the old way.
     */
    public function fingerprint(): string
    {
        return substr(sha1((string)json_encode([
            $this->matchType,
            $this->hostname,
            $this->rowLimit,
            $this->sections,
        ])), 0, 10);
    }
}
