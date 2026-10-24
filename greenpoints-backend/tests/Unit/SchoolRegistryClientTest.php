<?php

use App\Integrations\SchoolRegistry\Exceptions\RegistryAuthFailed;
use App\Integrations\SchoolRegistry\Exceptions\RegistryBadResponse;
use App\Integrations\SchoolRegistry\Exceptions\RegistryUnavailable;
use App\Integrations\SchoolRegistry\Exceptions\StudentNotFound;
use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

const FAKE_REGISTRY = 'http://registry.test';
const FAKE_KEY = 'test-read-key';

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

beforeEach(function () {
    config(['services.school_registry' => [
        'enabled' => true,
        'base_url' => FAKE_REGISTRY,
        'read_key' => FAKE_KEY,
        'timeout' => 3,
        'retries' => 2,
    ]]);
    Sleep::fake();
    Http::preventStrayRequests();
});

it('maps a student and sends the API key and a request id', function () {
    Http::fake([FAKE_REGISTRY.'/api/v1/students/2024-00309' => Http::response(registryStudent())]);

    $student = app(SchoolRegistryClient::class)->findStudent('2024-00309');

    expect($student->studentId)->toBe('2024-00309')
        ->and($student->fullName())->toBe("Ana Maria O'Reilly")
        ->and($student->course)->toBe('BSIT')
        ->and($student->yearLevel)->toBe(3)
        ->and($student->section)->toBe('IT3A')
        ->and($student->isActive())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->header('X-API-Key') === [FAKE_KEY]
        && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $request->header('X-Request-Id')[0]) === 1);
});

it('reuses a valid incoming X-Request-Id', function () {
    $this->app->instance('request', Illuminate\Http\Request::create('/', 'GET', server: ['HTTP_X_REQUEST_ID' => 'demo-123']));
    Http::fake(['*' => Http::response(registryStudent())]);

    app(SchoolRegistryClient::class)->findStudent('2024-00309');

    Http::assertSent(fn (Request $request) => $request->header('X-Request-Id') === ['demo-123']);
});

it('throws StudentNotFound on 404', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'STUDENT_NOT_FOUND', 'message' => 'No student with id 2026-99999']], 404)]);

    app(SchoolRegistryClient::class)->findStudent('2026-99999');
})->throws(StudentNotFound::class);

it('throws RegistryAuthFailed on 401 and 403 without retrying', function (int $status) {
    Http::fake(['*' => Http::response(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']], $status)]);

    expect(fn () => app(SchoolRegistryClient::class)->findStudent('2024-00309'))->toThrow(RegistryAuthFailed::class);
    Http::assertSentCount(1);
})->with([401, 403]);

it('retries connection errors and then throws RegistryUnavailable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => app(SchoolRegistryClient::class)->findStudent('2024-00309'))->toThrow(RegistryUnavailable::class);
    Sleep::assertSleptTimes(2); // 1 attempt + 2 retries
});

it('retries a 5xx and succeeds when the registry recovers', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']], 500)
        ->push(registryStudent())]);

    expect(app(SchoolRegistryClient::class)->findStudent('2024-00309')->studentId)->toBe('2024-00309');
    Http::assertSentCount(2);
});

it('throws RegistryUnavailable when every attempt is a 5xx', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']], 503)]);

    expect(fn () => app(SchoolRegistryClient::class)->findStudent('2024-00309'))->toThrow(RegistryUnavailable::class);
    Http::assertSentCount(3);
});

it('treats 429 as unavailable without retrying', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'RATE_LIMITED', 'message' => 'x']], 429, ['Retry-After' => '60'])]);

    expect(fn () => app(SchoolRegistryClient::class)->findStudent('2024-00309'))->toThrow(RegistryUnavailable::class);
    Http::assertSentCount(1);
});

it('throws RegistryBadResponse for a body that breaks the contract', function () {
    Http::fake(['*' => Http::response(['student_id' => '2024-00309'])]);

    app(SchoolRegistryClient::class)->findStudent('2024-00309');
})->throws(RegistryBadResponse::class);

it('throws RegistryUnavailable when not configured, without calling out', function () {
    config(['services.school_registry.read_key' => '']);
    Http::fake();

    expect(fn () => app(SchoolRegistryClient::class)->health())->toThrow(RegistryUnavailable::class);
    Http::assertNothingSent();
});

it('maps a section roster, and a 404 to an empty roster', function () {
    Http::fake([
        FAKE_REGISTRY.'/api/v1/sections/IT3A/students' => Http::response([
            'section' => 'IT3A', 'count' => 2,
            'students' => [registryStudent(), registryStudent(['student_id' => '2024-00310', 'status' => 'Inactive'])],
        ]),
        FAKE_REGISTRY.'/api/v1/sections/zz9z/students' => Http::response(['error' => ['code' => 'SECTION_NOT_FOUND', 'message' => 'x']], 404),
    ]);
    $client = app(SchoolRegistryClient::class);

    $roster = $client->sectionRoster('IT3A');
    expect($roster->section)->toBe('IT3A')
        ->and($roster->count())->toBe(2)
        ->and($roster->students[1]->status)->toBe('Inactive');

    $empty = $client->sectionRoster('zz9z');
    expect($empty->section)->toBe('ZZ9Z')->and($empty->count())->toBe(0);
});

it('maps the health check', function () {
    Http::fake([FAKE_REGISTRY.'/health' => Http::response(['status' => 'ok', 'version' => '1.0.0', 'time' => '2026-10-24T23:46:50.000Z'])]);

    $health = app(SchoolRegistryClient::class)->health();

    expect($health->status)->toBe('ok')->and($health->version)->toBe('1.0.0')->and($health->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('logs one line per call without the API key', function () {
    Log::spy();
    Http::fake(['*' => Http::response(registryStudent())]);

    app(SchoolRegistryClient::class)->findStudent('2024-00309');

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'school_registry.call'
        && $context['method'] === 'GET'
        && $context['path'] === '/api/v1/students/2024-00309'
        && $context['status'] === 200
        && is_int($context['ms'])
        && is_string($context['request_id'])
        && ! str_contains(json_encode($context), FAKE_KEY));
});
