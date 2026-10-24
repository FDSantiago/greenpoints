<?php

namespace App\Integrations\SchoolRegistry;

use App\Integrations\SchoolRegistry\Exceptions\RegistryBadResponse;

/**
 * A section roster from the School Registry. An empty roster means the registry has no students in it.
 */
final readonly class RegistryRoster
{
    /** @param list<RegistryStudent> $students */
    public function __construct(
        public string $section,
        public array $students,
    ) {}

    public static function fromArray(mixed $data, ?string $requestId = null): self
    {
        if (! is_array($data) || ! is_string($data['section'] ?? null) || ! is_array($data['students'] ?? null)) {
            throw new RegistryBadResponse('The registry returned a roster without the expected fields.', $requestId);
        }

        return new self(
            section: $data['section'],
            students: array_map(fn ($student) => RegistryStudent::fromArray($student, $requestId), array_values($data['students'])),
        );
    }

    public function count(): int
    {
        return count($this->students);
    }
}
