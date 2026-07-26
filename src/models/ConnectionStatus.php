<?php

declare(strict_types=1);

namespace justinholtweb\telescope\models;

/**
 * The result of a connection check, shared by the settings screen and the
 * `telescope/check` console command.
 */
final class ConnectionStatus
{
    /**
     * @param list<string> $checks human-readable steps that passed
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $checks = [],
        public readonly ?string $propertyId = null,
    ) {
    }

    /**
     * @param list<string> $checks
     */
    public static function success(string $message, array $checks = [], ?string $propertyId = null): self
    {
        return new self(true, $message, $checks, $propertyId);
    }

    /**
     * @param list<string> $checks
     */
    public static function failure(string $message, array $checks = [], ?string $propertyId = null): self
    {
        return new self(false, $message, $checks, $propertyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'checks' => $this->checks,
            'propertyId' => $this->propertyId,
        ];
    }
}
