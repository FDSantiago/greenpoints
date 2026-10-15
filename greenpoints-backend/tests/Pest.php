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
