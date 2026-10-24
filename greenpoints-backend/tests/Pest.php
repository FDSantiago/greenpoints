<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

function apiRequest(
    object $test,
    string $method,
    string $path,
    User|string|null $user = null,
    array $data = [],
    array $headers = [],
): TestResponse {
    if (! $test instanceof TestCase) {
        throw new LogicException('API requests require a Laravel test case.');
    }

    if ($user instanceof User) {
        $test->actingAs($user, 'sanctum');
    } else {
        Auth::forgetGuards();
        if (is_string($user)) {
            $headers['Authorization'] = 'Bearer '.$user;
        }
    }

    return $test->json(
        $method,
        '/api/v1'.$path,
        $data,
        ['Accept' => 'application/json'] + $headers,
    );
}

// --- School Registry (System 2) fakes -------------------------------------------------

const FAKE_REGISTRY = 'http://registry.test';
const FAKE_KEY = 'test-read-key';

/** Point the School Registry client at a fake host (use with Http::fake()). */
function useFakeRegistry(bool $enabled = true): void
{
    config(['services.school_registry' => [
        'enabled' => $enabled,
        'base_url' => FAKE_REGISTRY,
        'read_key' => FAKE_KEY,
        'timeout' => 3,
        'retries' => 2,
    ]]);
}

/** A student exactly as the registry's GET /api/v1/students/{id} returns it. */
function registryStudent(array $overrides = []): array
{
    return $overrides + [
        'student_id' => '2024-00309',
        'first_name' => 'Ana Maria',
        'last_name' => "O'Reilly",
        'course' => 'BSIT',
        'year_level' => 3,
        'section' => 'IT3A',
        'status' => 'Active',
        'created_at' => '2026-10-24T23:46:31.758Z',
        'updated_at' => '2026-10-24T23:46:31.758Z',
    ];
}
