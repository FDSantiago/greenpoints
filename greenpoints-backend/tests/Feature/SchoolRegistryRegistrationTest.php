<?php

use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    useFakeRegistry();
    Sleep::fake();
    Http::preventStrayRequests();
});

function registration(array $overrides = []): array
{
    return $overrides + [
        'student_id' => '2024-00309',
        'name' => 'Someone Else',
        'email' => 'ana@student.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ];
}

function fakeStudent(array $body, int $status = 200): void
{
    Http::fake([FAKE_REGISTRY.'/api/v1/students/*' => Http::response($body, $status)]);
}

function expectRegistryUnavailable(object $test): void
{
    apiRequest($test, 'POST', '/auth/register', null, registration())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['request_id']]]);

    expect(User::count())->toBe(0);
}

it('TC-01 registers an Active student using the registry as the source of truth', function () {
    fakeStudent(registryStudent());

    $response = apiRequest($this, 'POST', '/auth/register', null, registration())
        ->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'group', 'created_at']])
        ->assertJsonPath('user.name', "Ana Maria O'Reilly") // not "Someone Else"
        ->assertJsonPath('user.role', 'student')
        ->assertJsonPath('user.student_id', '2024-00309')
        ->assertJsonPath('user.course', 'BSIT')
        ->assertJsonPath('user.group.name', 'IT3A')
        ->assertJsonPath('user.group.type', 'class');

    $user = User::findOrFail($response->json('user.id'));
    expect($user->student_id)->toBe('2024-00309')
        ->and($user->course)->toBe('BSIT')
        ->and($user->group->name)->toBe('IT3A');

    Http::assertSent(fn ($request) => $request->url() === FAKE_REGISTRY.'/api/v1/students/2024-00309');
});

it('joins the existing class group for the registry section', function () {
    $group = ClassGroup::create(['name' => 'IT3A', 'type' => 'class']);
    fakeStudent(registryStudent());

    apiRequest($this, 'POST', '/auth/register', null, registration())
        ->assertCreated()
        ->assertJsonPath('user.group.id', $group->id);

    expect(ClassGroup::where('name', 'IT3A')->count())->toBe(1);
});

it('TC-02 rejects a student id the registry does not know', function () {
    fakeStudent(['error' => ['code' => 'STUDENT_NOT_FOUND', 'message' => 'No student with id 2026-99999']], 404);

    apiRequest($this, 'POST', '/auth/register', null, registration(['student_id' => '2026-99999']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'not found in the School Registry']);

    expect(User::count())->toBe(0);
});

it('TC-03 requires student_id and does not call the registry without it', function () {
    Http::fake();

    apiRequest($this, 'POST', '/auth/register', null, registration(['student_id' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'required']);

    Http::assertNothingSent();
});

it('rejects a malformed student_id without calling the registry', function () {
    Http::fake();

    apiRequest($this, 'POST', '/auth/register', null, registration(['student_id' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'must look like 2026-00123']);

    Http::assertNothingSent();
});

it('rejects a student who is not Active', function (string $status) {
    fakeStudent(registryStudent(['student_id' => '2026-00109', 'status' => $status]));

    apiRequest($this, 'POST', '/auth/register', null, registration(['student_id' => '2026-00109']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => "not active in the School Registry (status: {$status})"]);

    expect(User::count())->toBe(0);
})->with(['Inactive', 'Graduated']);

it('rejects a student id that already has an account, before calling the registry', function () {
    User::factory()->create()->forceFill(['student_id' => '2024-00309'])->save();
    Http::fake();

    apiRequest($this, 'POST', '/auth/register', null, registration())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id' => 'already exists']);

    Http::assertNothingSent();
});

it('returns 503 when the registry rejects our API key', function (int $status) {
    fakeStudent(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']], $status);

    expectRegistryUnavailable($this);
})->with([401, 403]);

it('returns 503 when the API key is missing from our config', function () {
    config(['services.school_registry.read_key' => null]);
    Http::fake();

    expectRegistryUnavailable($this);
    Http::assertNothingSent();
});

it('returns 503 when the registry times out', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expectRegistryUnavailable($this);
    Sleep::assertSleptTimes(2);
});

it('returns 503 when the registry keeps failing with 5xx', function () {
    fakeStudent(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']], 500);

    expectRegistryUnavailable($this);
    Http::assertSentCount(3);
});

it('retries a 5xx and registers when the registry recovers', function () {
    Http::fake([FAKE_REGISTRY.'/*' => Http::sequence()
        ->push(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']], 500)
        ->push(registryStudent())]);

    apiRequest($this, 'POST', '/auth/register', null, registration())
        ->assertCreated()
        ->assertJsonPath('user.student_id', '2024-00309');

    Http::assertSentCount(2);
});

it('keeps the old behaviour when the registry is disabled', function () {
    useFakeRegistry(enabled: false);
    $group = ClassGroup::create(['name' => 'Eco Club', 'type' => 'zone']);
    Http::fake();

    apiRequest($this, 'POST', '/auth/register', null, [
        'name' => 'Plain Student',
        'email' => 'plain@student.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
        'group_id' => $group->id,
        'student_id' => '2024-00309', // ignored
    ])->assertCreated()
        ->assertJsonPath('user.name', 'Plain Student')
        ->assertJsonPath('user.group.id', $group->id)
        ->assertJsonPath('user.student_id', null);

    apiRequest($this, 'POST', '/auth/register', null, ['email' => 'x@student.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'password'])
        ->assertJsonMissingValidationErrors(['student_id']);

    Http::assertNothingSent();
});
