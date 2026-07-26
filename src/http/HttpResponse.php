<?php

declare(strict_types=1);

namespace justinholtweb\telescope\http;

/**
 * A minimal HTTP response, so the plugin's transport seam does not leak
 * Guzzle's types into the classes under test.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * The body decoded as a JSON object, or an empty array when it is not JSON.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
