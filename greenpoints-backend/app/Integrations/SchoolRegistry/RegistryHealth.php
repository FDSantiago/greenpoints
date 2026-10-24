<?php

namespace App\Integrations\SchoolRegistry;

use App\Integrations\SchoolRegistry\Exceptions\RegistryBadResponse;

/**
 * The registry's GET /health answer, plus how long the call took.
 */
final readonly class RegistryHealth
{
    public function __construct(
        public string $status,
        public string $version,
        public string $time,
        public int $latencyMs,
    ) {}

    public static function fromArray(mixed $data, int $latencyMs, ?string $requestId = null): self
    {
        if (! is_array($data) || collect(['status', 'version', 'time'])->contains(fn ($key) => ! is_string($data[$key] ?? null))) {
            throw new RegistryBadResponse('The registry returned a health check without the expected fields.', $requestId);
        }

        return new self($data['status'], $data['version'], $data['time'], $latencyMs);
    }
}
