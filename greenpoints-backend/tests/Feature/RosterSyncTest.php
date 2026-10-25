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

    $this->admin = userWithRole(User::ROLE_ADMIN);
    $this->it3a = ClassGroup::create(['name' => 'IT3A', 'type' => 'class']);
});

function studentInGroup(ClassGroup $group, ?string $studentId, string $name = 'Old Name'): User
{
    $user = User::factory()->create(['name' => $name, 'group_id' => $group->id]);
    $user->forceFill(['role' => User::ROLE_STUDENT, 'student_id' => $studentId])->save();

    return $user;
}

function fakeIt3aRoster(): void
{
    Http::fake([
        FAKE_REGISTRY.'/api/v1/sections/IT3A/students' => Http::response([
            'section' => 'IT3A',
            'count' => 2,
            'students' => [registryStudent(), registryStudent(['student_id' => '2024-00310', 'first_name' => 'No', 'last_name' => 'Account'])],
        ]),
        FAKE_REGISTRY.'/api/v1/sections/*' => Http::response(['error' => ['code' => 'SECTION_NOT_FOUND', 'message' => 'x']], 404),
    ]);
}

it('reports matched, updated and missing students, and is idempotent', function () {
    fakeIt3aRoster();
    $stale = studentInGroup($this->it3a, '2024-00309');      // in the registry, stale name/course
    $gone = studentInGroup($this->it3a, '2024-00399', 'Gone'); // not in the registry roster
    studentInGroup($this->it3a, null, 'Legacy');               // created before the integration

    $first = apiRequest($this, 'POST', '/admin/registry/sync', $this->admin)->assertOk();

    expect($first->json('data.totals'))->toBe([
        'registry_students' => 2,
        'matched' => 1,
        'updated' => 1,
        'missing_in_registry' => 1,
        'without_student_id' => 1,
    ])->and($first->json('data.sections.0.missing_student_ids'))->toBe(['2024-00399']);

    expect($stale->fresh()->name)->toBe("Ana Maria O'Reilly")
        ->and($stale->fresh()->course)->toBe('BSIT')
        ->and($gone->fresh())->not->toBeNull(); // never deleted

    $second = apiRequest($this, 'POST', '/admin/registry/sync', $this->admin)->assertOk();

    expect($second->json('data.totals'))->toBe([
        'registry_students' => 2,
        'matched' => 1,
        'updated' => 0,
        'missing_in_registry' => 1,
        'without_student_id' => 1,
    ])->and(User::count())->toBe(4);
});

it('syncs only class groups, and one section when asked', function () {
    fakeIt3aRoster();
    ClassGroup::create(['name' => 'IT1A', 'type' => 'class']);
    ClassGroup::create(['name' => 'Eco Zone', 'type' => 'zone']);

    $all = apiRequest($this, 'POST', '/admin/registry/sync', $this->admin)->assertOk();
    expect(collect($all->json('data.sections'))->pluck('section')->all())->toBe(['IT1A', 'IT3A']);

    $one = apiRequest($this, 'POST', '/admin/registry/sync', $this->admin, ['section' => 'it3a'])->assertOk();
    expect(collect($one->json('data.sections'))->pluck('section')->all())->toBe(['IT3A']);

    Http::assertSentCount(3);
});

it('rejects a section that is not a class group', function () {
    Http::fake();

    apiRequest($this, 'POST', '/admin/registry/sync', $this->admin, ['section' => 'ZZ9Z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['section']);

    Http::assertNothingSent();
});

it('is admin only', function (string $role) {
    Http::fake();

    apiRequest($this, 'POST', '/admin/registry/sync', userWithRole($role))->assertForbidden();
    apiRequest($this, 'POST', '/admin/registry/sync')->assertUnauthorized();

    Http::assertNothingSent();
})->with([User::ROLE_STUDENT, User::ROLE_STAFF]);

it('returns 503 when the registry is down', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    apiRequest($this, 'POST', '/admin/registry/sync', $this->admin)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE');
});

it('runs as an artisan command', function () {
    fakeIt3aRoster();
    studentInGroup($this->it3a, '2024-00309');

    $this->artisan('greenpoints:sync-roster', ['section' => 'it3a'])
        ->expectsTable(
            ['section', 'registry_students', 'matched', 'updated', 'missing_in_registry', 'without_student_id'],
            [['IT3A', 2, 1, 1, 0, 0], ['TOTAL', 2, 1, 1, 0, 0]],
        )
        ->assertSuccessful();

    $this->artisan('greenpoints:sync-roster', ['section' => 'NOPE'])->assertFailed();
});

it('fails the command when the registry is down', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('greenpoints:sync-roster')
        ->expectsOutputToContain('School Registry unavailable')
        ->assertFailed();
});
