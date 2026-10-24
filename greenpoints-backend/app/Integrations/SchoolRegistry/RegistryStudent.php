<?php

namespace App\Integrations\SchoolRegistry;

use App\Integrations\SchoolRegistry\Exceptions\RegistryBadResponse;

/**
 * One student as the School Registry returns it (JSON, snake_case), deserialized into PHP.
 */
final readonly class RegistryStudent
{
    public function __construct(
        public string $studentId,
        public string $firstName,
        public string $lastName,
        public string $course,
        public int $yearLevel,
        public string $section,
        public string $status, // Active | Inactive | Graduated
    ) {}

    public static function fromArray(mixed $data, ?string $requestId = null): self
    {
        $strings = ['student_id', 'first_name', 'last_name', 'course', 'section', 'status'];

        if (! is_array($data)
            || ! is_int($data['year_level'] ?? null)
            || collect($strings)->contains(fn ($key) => ! is_string($data[$key] ?? null))) {
            throw new RegistryBadResponse('The registry returned a student without the expected fields.', $requestId);
        }

        return new self(
            studentId: $data['student_id'],
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            course: $data['course'],
            yearLevel: $data['year_level'],
            section: $data['section'],
            status: $data['status'],
        );
    }

    public function fullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }
}
