<?php

declare(strict_types=1);

namespace justinholtweb\telescope\tests\Support;

use justinholtweb\telescope\http\HttpClientInterface;
use justinholtweb\telescope\http\HttpResponse;
use RuntimeException;

/**
 * A scripted HTTP transport.
 *
 * Responses are queued up front and handed out in order; every request is
 * recorded so tests can assert on the URL, headers and exact body that would
 * have gone to Google.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /**
     * The response returned once the queue runs dry. Left null to make an
     * unexpected extra request a loud failure rather than a silent empty report.
     */
    private ?HttpResponse $fallback = null;

    /**
     * @param array<string, mixed>|list<mixed> $body
     */
    public function queueJson(array $body, int $status = 200): self
    {
        return $this->queue((string)json_encode($body), $status);
    }

    public function queue(string $body, int $status = 200): self
    {
        $this->queue[] = new HttpResponse($status, $body);

        return $this;
    }

    /**
     * Queue the same response a number of times — handy when a report fires
     * one call per section.
     *
     * @param array<string, mixed> $body
     */
    public function queueJsonTimes(int $times, array $body, int $status = 200): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->queueJson($body, $status);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function alwaysJson(array $body, int $status = 200): self
    {
        $this->fallback = new HttpResponse($status, (string)json_encode($body));

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        $response = array_shift($this->queue);

        if ($response !== null) {
            return $response;
        }

        if ($this->fallback !== null) {
            return $this->fallback;
        }

        throw new RuntimeException("FakeHttpClient received an unexpected {$method} request to {$url}.");
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}
     */
    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new RuntimeException('No requests were made.');
        }

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The decoded JSON body of the nth request (0-indexed).
     *
     * @return array<string, mixed>
     */
    public function requestBody(int $index = 0): array
    {
        if (!isset($this->requests[$index])) {
            throw new RuntimeException("No request was made at index {$index}.");
        }

        $decoded = json_decode((string)$this->requests[$index]['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The form-encoded body of the nth request, decoded.
     *
     * @return array<string, string>
     */
    public function requestForm(int $index = 0): array
    {
        parse_str((string)($this->requests[$index]['body'] ?? ''), $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
