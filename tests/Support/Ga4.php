<?php

declare(strict_types=1);

namespace justinholtweb\telescope\tests\Support;

/**
 * Builders for realistic GA4 Data API payloads.
 *
 * The shape here mirrors a real `runReport` response — headers first, then rows
 * of parallel dimension/metric values — so parsing tests fail for the same
 * reasons production would.
 */
final class Ga4
{
    /**
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @param list<array{0: list<string>, 1: list<string|int|float>}> $rows dimension values, then metric values
     * @return array<string, mixed>
     */
    public static function response(array $dimensions, array $metrics, array $rows): array
    {
        return [
            'dimensionHeaders' => array_map(static fn(string $name): array => ['name' => $name], $dimensions),
            'metricHeaders' => array_map(static fn(string $name): array => [
                'name' => $name,
                'type' => 'TYPE_INTEGER',
            ], $metrics),
            'rows' => array_map(static fn(array $row): array => [
                'dimensionValues' => array_map(static fn(string $value): array => ['value' => $value], $row[0]),
                'metricValues' => array_map(static fn($value): array => ['value' => (string)$value], $row[1]),
            ], $rows),
            'rowCount' => count($rows),
        ];
    }

    /**
     * A totals-only overview response.
     *
     * @return array<string, mixed>
     */
    public static function overview(
        int $views = 1200,
        int $users = 800,
        int $newUsers = 500,
        int $sessions = 950,
        float $duration = 134.5,
        float $engagement = 0.62,
        float $bounce = 0.38,
    ): array {
        return self::response([], [
            'screenPageViews',
            'activeUsers',
            'newUsers',
            'sessions',
            'averageSessionDuration',
            'engagementRate',
            'bounceRate',
        ], [
            [[], [$views, $users, $newUsers, $sessions, $duration, $engagement, $bounce]],
        ]);
    }

    /**
     * A daily timeline response.
     *
     * @param list<array{0: string, 1: int, 2: int}> $days date (YYYYMMDD), views, users
     * @return array<string, mixed>
     */
    public static function timeline(array $days): array
    {
        return self::response(['date'], ['screenPageViews', 'activeUsers'], array_map(
            static fn(array $day): array => [[$day[0]], [$day[1], $day[2]]],
            $days,
        ));
    }

    /**
     * An empty (no matching rows) response — what GA4 returns for a path that
     * was never visited.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['rowCount' => 0];
    }

    /**
     * An error body in the Data API's shape.
     *
     * @return array<string, mixed>
     */
    public static function error(string $message, string $status = 'PERMISSION_DENIED'): array
    {
        return [
            'error' => [
                'code' => 403,
                'message' => $message,
                'status' => $status,
            ],
        ];
    }

    /**
     * A service account token endpoint response.
     *
     * @return array<string, mixed>
     */
    public static function token(string $accessToken = 'ya29.test-token', int $expiresIn = 3600): array
    {
        return [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ];
    }
}
