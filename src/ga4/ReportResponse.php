<?php

declare(strict_types=1);

namespace justinholtweb\telescope\ga4;

/**
 * A decoded `runReport` response.
 *
 * GA4 returns rows as parallel arrays of values with the column names held in
 * separate header arrays. Working with raw indexes throughout the plugin is how
 * subtle "wrong column" bugs creep in, so everything downstream reads values by
 * dimension/metric name through this class instead.
 */
final class ReportResponse
{
    /**
     * @param list<string> $dimensionHeaders
     * @param list<string> $metricHeaders
     * @param list<array{dimensionValues: list<string>, metricValues: list<string>}> $rows
     */
    private function __construct(
        public readonly array $dimensionHeaders,
        public readonly array $metricHeaders,
        public readonly array $rows,
        public readonly int $rowCount,
    ) {
    }

    /**
     * @param array<string, mixed> $raw the JSON-decoded response body
     */
    public static function fromArray(array $raw): self
    {
        $dimensionHeaders = [];
        foreach ($raw['dimensionHeaders'] ?? [] as $header) {
            $dimensionHeaders[] = (string)($header['name'] ?? '');
        }

        $metricHeaders = [];
        foreach ($raw['metricHeaders'] ?? [] as $header) {
            $metricHeaders[] = (string)($header['name'] ?? '');
        }

        $rows = [];
        foreach ($raw['rows'] ?? [] as $row) {
            $dimensionValues = [];
            foreach ($row['dimensionValues'] ?? [] as $value) {
                $dimensionValues[] = (string)($value['value'] ?? '');
            }

            $metricValues = [];
            foreach ($row['metricValues'] ?? [] as $value) {
                $metricValues[] = (string)($value['value'] ?? '');
            }

            $rows[] = [
                'dimensionValues' => $dimensionValues,
                'metricValues' => $metricValues,
            ];
        }

        return new self(
            $dimensionHeaders,
            $metricHeaders,
            $rows,
            (int)($raw['rowCount'] ?? count($rows)),
        );
    }

    public static function empty(): self
    {
        return new self([], [], [], 0);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Read a dimension value from a row by dimension name.
     */
    public function dimension(int $rowIndex, string $name, string $default = ''): string
    {
        $column = array_search($name, $this->dimensionHeaders, true);

        if ($column === false || !isset($this->rows[$rowIndex]['dimensionValues'][$column])) {
            return $default;
        }

        return $this->rows[$rowIndex]['dimensionValues'][$column];
    }

    /**
     * Read a metric value from a row by metric name, as a float.
     */
    public function metric(int $rowIndex, string $name, float $default = 0.0): float
    {
        $column = array_search($name, $this->metricHeaders, true);

        if ($column === false || !isset($this->rows[$rowIndex]['metricValues'][$column])) {
            return $default;
        }

        $value = $this->rows[$rowIndex]['metricValues'][$column];

        return is_numeric($value) ? (float)$value : $default;
    }

    /**
     * Read a metric value from a row by metric name, as an int.
     */
    public function metricInt(int $rowIndex, string $name, int $default = 0): int
    {
        return (int)round($this->metric($rowIndex, $name, (float)$default));
    }

    /**
     * Map every row through a callback, with this response and the row index
     * passed in so callbacks can use the name-based accessors.
     *
     * @template T
     * @param callable(self, int): T $callback
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        $results = [];

        foreach (array_keys($this->rows) as $index) {
            $results[] = $callback($this, $index);
        }

        return $results;
    }

    /**
     * The sum of a metric across every row.
     */
    public function total(string $metric): float
    {
        $total = 0.0;

        foreach (array_keys($this->rows) as $index) {
            $total += $this->metric($index, $metric);
        }

        return $total;
    }
}
