<?php

/*
 * End-to-end registration: POST /api/v1/auth/register on GreenPoints (System 1), which calls the REAL
 * School Registry (System 2) at SCHOOL_REGISTRY_URL. Skipped unless SCHOOL_REGISTRY_E2E is set.
 * Expects the registry's fictional seed data (`bun run seed`).
 *
 *   SCHOOL_REGISTRY_E2E=1 php artisan test --group=e2e
 */

use App\Models\User;

pest()->group('e2e');

beforeEach(function () {
    if (! env('SCHOOL_REGISTRY_E2E')) {
        $this->markTestSkipped('Set SCHOOL_REGISTRY_E2E=1 to run against the real School Registry.');
    }
    config(['services.school_registry.enabled' => true]);
});

function e2eRegistration(string $studentId): array
{
    return [
        'student_id' => $studentId,
        'name' => 'Name From The Form',
        'email' => "e2e-{$studentId}@student.test",
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ];
}

it('E2E-08 registers an Active student with registry data', function () {
    apiRequest($this, 'POST', '/auth/register', null, e2eRegistration('2024-00309'))
        ->assertCreated()
        ->assertJsonPath('user.name', "Ana Maria O'Reilly")
        ->assertJsonPath('user.student_id', '2024-00309')
        ->assertJsonPath('user.course', 'BSIT')
        ->assertJsonPath('user.group.name', 'IT3A');
});

it('E2E-09 rejects an unknown student id with 422', function () {
    apiRequest($this, 'POST', '/auth/register', null, e2eRegistration('2026-99999'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'not found in the School Registry']);

    expect(User::count())->toBe(0);
});

it('E2E-10 rejects an Inactive student with 422', function () {
    apiRequest($this, 'POST', '/auth/register', null, e2eRegistration('2026-00109'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'not active']);

    expect(User::count())->toBe(0);
});

it('E2E-11 returns 503 when the registry rejects the key', function () {
    config(['services.school_registry.read_key' => 'wrong-key-for-e2e']);

    apiRequest($this, 'POST', '/auth/register', null, e2eRegistration('2024-00309'))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE');

    expect(User::count())->toBe(0);
});
