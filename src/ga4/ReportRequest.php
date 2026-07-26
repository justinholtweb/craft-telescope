<?php

declare(strict_types=1);

namespace justinholtweb\telescope\ga4;

use InvalidArgumentException;

/**
 * Builds `runReport` request bodies for the GA4 Data API.
 *
 * Kept free of Craft and HTTP concerns so the exact JSON we send to Google can
 * be asserted in isolation — the request body is the part most likely to drift
 * and the hardest to debug once it is inside a Guzzle call.
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport
 */
final class ReportRequest
{
    public const MATCH_EXACT = 'EXACT';
    public const MATCH_BEGINS_WITH = 'BEGINS_WITH';
    public const MATCH_CONTAINS = 'CONTAINS';

    private const MATCH_TYPES = [self::MATCH_EXACT, self::MATCH_BEGINS_WITH, self::MATCH_CONTAINS];

    /** @var list<string> */
    private array $dimensions = [];

    /** @var list<string> */
    private array $metrics = [];

    /** @var list<array{fieldName: string, matchType: string, value: string}> */
    private array $stringFilters = [];

    /** @var array{field: string, type: 'dimension'|'metric', desc: bool}|null */
    private ?array $orderBy = null;

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $keepEmptyRows = false;

    public function __construct(private readonly Period $period)
    {
    }

    public static function for(Period $period): self
    {
        return new self($period);
    }

    /**
     * @param string ...$dimensions GA4 dimension API names, e.g. `date`, `city`
     */
    public function dimensions(string ...$dimensions): self
    {
        foreach ($dimensions as $dimension) {
            if (!in_array($dimension, $this->dimensions, true)) {
                $this->dimensions[] = $dimension;
            }
        }

        return $this;
    }

    /**
     * @param string ...$metrics GA4 metric API names, e.g. `screenPageViews`
     */
    public function metrics(string ...$metrics): self
    {
        foreach ($metrics as $metric) {
            if (!in_array($metric, $this->metrics, true)) {
                $this->metrics[] = $metric;
            }
        }

        return $this;
    }

    /**
     * Restrict the report to a single page path.
     */
    public function pagePath(string $path, string $matchType = self::MATCH_EXACT): self
    {
        return $this->whereDimension('pagePath', $path, $matchType);
    }

    /**
     * Restrict the report to one hostname.
     *
     * Needed when several Craft sites report into a single GA4 property: page
     * paths collide across sites, hostnames do not.
     */
    public function hostname(string $hostname): self
    {
        return $this->whereDimension('hostName', $hostname, self::MATCH_EXACT);
    }

    /**
     * @throws InvalidArgumentException if the match type is not one GA4 supports here
     */
    public function whereDimension(string $fieldName, string $value, string $matchType = self::MATCH_EXACT): self
    {
        if (!in_array($matchType, self::MATCH_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported string match type: {$matchType}");
        }

        $this->stringFilters[] = [
            'fieldName' => $fieldName,
            'matchType' => $matchType,
            'value' => $value,
        ];

        return $this;
    }

    public function orderByMetric(string $metric, bool $descending = true): self
    {
        $this->orderBy = ['field' => $metric, 'type' => 'metric', 'desc' => $descending];

        return $this;
    }

    public function orderByDimension(string $dimension, bool $descending = false): self
    {
        $this->orderBy = ['field' => $dimension, 'type' => 'dimension', 'desc' => $descending];

        return $this;
    }

    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Report limit must be at least 1.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(?int $offset): self
    {
        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException('Report offset cannot be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    public function keepEmptyRows(bool $keep = true): self
    {
        $this->keepEmptyRows = $keep;

        return $this;
    }

    /**
     * The request body, ready to be JSON-encoded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->metrics === []) {
            throw new InvalidArgumentException('A GA4 report needs at least one metric.');
        }

        $payload = [
            'dateRanges' => [$this->period->toArray()],
            'dimensions' => array_map(static fn(string $name): array => ['name' => $name], $this->dimensions),
            'metrics' => array_map(static fn(string $name): array => ['name' => $name], $this->metrics),
        ];

        $filter = $this->buildDimensionFilter();
        if ($filter !== null) {
            $payload['dimensionFilter'] = $filter;
        }

        if ($this->orderBy !== null) {
            $payload['orderBys'] = [$this->buildOrderBy()];
        }

        if ($this->limit !== null) {
            $payload['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $payload['offset'] = $this->offset;
        }

        if ($this->keepEmptyRows) {
            $payload['keepEmptyRows'] = true;
        }

        return $payload;
    }

    /**
     * A hash of the request, used as the cache key for its response.
     */
    public function fingerprint(): string
    {
        return substr(sha1((string)json_encode($this->toArray())), 0, 20);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDimensionFilter(): ?array
    {
        if ($this->stringFilters === []) {
            return null;
        }

        $expressions = array_map(static fn(array $filter): array => [
            'filter' => [
                'fieldName' => $filter['fieldName'],
                'stringFilter' => [
                    'matchType' => $filter['matchType'],
                    'value' => $filter['value'],
                ],
            ],
        ], $this->stringFilters);

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return ['andGroup' => ['expressions' => $expressions]];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderBy(): array
    {
        $orderBy = $this->orderBy;
        assert($orderBy !== null);

        $key = $orderBy['type'] === 'metric' ? 'metric' : 'dimension';
        $nameKey = $orderBy['type'] === 'metric' ? 'metricName' : 'dimensionName';

        $result = [$key => [$nameKey => $orderBy['field']]];

        if ($orderBy['desc']) {
            $result['desc'] = true;
        }

        return $result;
    }
}
