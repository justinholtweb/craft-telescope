<?php

declare(strict_types=1);

namespace justinholtweb\telescope\ga4;

use justinholtweb\telescope\auth\TokenProviderInterface;
use justinholtweb\telescope\errors\ApiException;
use justinholtweb\telescope\http\HttpClientInterface;

/**
 * A thin client for the GA4 Data API's `runReport` endpoint.
 *
 * Deliberately small: build a request, send it with a bearer token, hand back a
 * parsed response. Caching, report shaping and Craft integration all live
 * further up, which keeps this class testable against a fake transport.
 */
final class Client
{
    public const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param int $maxAttempts total attempts, including the first one
     * @param callable(int): void|null $sleeper overridable so retry tests do not actually sleep
     */
    public function __construct(
        private readonly string $propertyId,
        private readonly TokenProviderInterface $tokens,
        private readonly HttpClientInterface $http,
        private readonly int $maxAttempts = 3,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function(int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Normalise a property ID to the `properties/123456789` form the API wants.
     *
     * People paste all three of `123456789`, `properties/123456789` and
     * `G-XXXXXXX` (which is a measurement ID, not a property ID) into settings.
     */
    public static function normalizePropertyId(string $propertyId): string
    {
        $propertyId = trim($propertyId);

        if ($propertyId === '') {
            return '';
        }

        if (str_starts_with($propertyId, 'properties/')) {
            $propertyId = substr($propertyId, strlen('properties/'));
        }

        $propertyId = trim($propertyId, '/');

        return $propertyId === '' ? '' : 'properties/' . $propertyId;
    }

    /**
     * Whether a property ID looks like a numeric GA4 property (and not, say, a
     * `G-` measurement ID or a UA property).
     */
    public static function isValidPropertyId(string $propertyId): bool
    {
        $normalized = self::normalizePropertyId($propertyId);

        return $normalized !== '' && preg_match('/^properties\/\d+$/', $normalized) === 1;
    }

    public function propertyId(): string
    {
        return self::normalizePropertyId($this->propertyId);
    }

    /**
     * @throws ApiException on a non-2xx response that retries could not fix
     * @throws \justinholtweb\telescope\errors\AuthException when no token is available
     */
    public function runReport(ReportRequest $request): ReportResponse
    {
        $propertyId = $this->propertyId();

        if ($propertyId === '') {
            throw new ApiException('No GA4 property ID is configured.', 0, 'NO_PROPERTY');
        }

        $url = self::BASE_URL . '/' . $propertyId . ':runReport';
        $body = (string)json_encode($request->toArray());

        $attempt = 0;

        while (true) {
            $attempt++;

            $response = $this->http->request('POST', $url, [
                'Authorization' => 'Bearer ' . $this->tokens->getToken()->value,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $body);

            if ($response->isSuccessful()) {
                return ReportResponse::fromArray($response->json());
            }

            $exception = ApiException::fromResponse($response->statusCode, $response->json());

            if (!$exception->isRetryable() || $attempt >= $this->maxAttempts) {
                throw $exception;
            }

            // Exponential backoff: 250ms, 500ms, 1s …
            ($this->sleeper)(250000 * (2 ** ($attempt - 1)));
        }
    }
}
