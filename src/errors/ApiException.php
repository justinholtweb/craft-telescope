<?php

declare(strict_types=1);

namespace justinholtweb\telescope\errors;

use Throwable;

/**
 * Raised when the GA4 Data API returns a non-2xx response.
 */
class ApiException extends TelescopeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Build an exception from a Data API error body.
     *
     * Google nests the useful part under `error`, and the top-level HTTP status
     * alone rarely says what went wrong (403 covers both "API not enabled" and
     * "service account has no access to this property").
     *
     * @param array<string, mixed> $body the JSON-decoded error response
     */
    public static function fromResponse(int $statusCode, array $body): self
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = (string)($error['message'] ?? 'The Google Analytics Data API returned an error.');
        $reason = isset($error['status']) ? (string)$error['status'] : null;

        return new self($message, $statusCode, $reason);
    }

    /**
     * Whether retrying the same request could plausibly succeed.
     */
    public function isRetryable(): bool
    {
        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
