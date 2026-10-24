<?php

/*
 * End-to-end: talks to the REAL School Registry at SCHOOL_REGISTRY_URL with SCHOOL_REGISTRY_KEY (from .env).
 * Skipped unless SCHOOL_REGISTRY_E2E is set. Expects the registry's fictional seed data (`bun run seed`).
 *
 *   SCHOOL_REGISTRY_E2E=1 php artisan test --group=e2e
 */

use App\Integrations\SchoolRegistry\Exceptions\RegistryAuthFailed;
use App\Integrations\SchoolRegistry\Exceptions\StudentNotFound;
use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use Tests\TestCase;

uses(TestCase::class)->group('e2e');

beforeEach(function () {
    if (! env('SCHOOL_REGISTRY_E2E')) {
        $this->markTestSkipped('Set SCHOOL_REGISTRY_E2E=1 to run against the real School Registry.');
    }
});

it('E2E-01 reaches the registry health check', function () {
    $health = app(SchoolRegistryClient::class)->health();

    expect($health->status)->toBe('ok');
});

it('E2E-02 finds an Active student', function () {
    $student = app(SchoolRegistryClient::class)->findStudent('2024-00309');

    expect($student->status)->toBe('Active')->and($student->section)->toBe('IT3A');
});

it('E2E-03 finds an Inactive student', function () {
    expect(app(SchoolRegistryClient::class)->findStudent('2026-00109')->status)->toBe('Inactive');
});

it('E2E-04 reports an unknown student id', function () {
    app(SchoolRegistryClient::class)->findStudent('2026-99999');
})->throws(StudentNotFound::class);

it('E2E-05 rejects a wrong API key', function () {
    config(['services.school_registry.read_key' => 'wrong-key-for-e2e']);

    app(SchoolRegistryClient::class)->findStudent('2024-00309');
})->throws(RegistryAuthFailed::class);

it('E2E-06 pulls a section roster as JSON', function () {
    $roster = app(SchoolRegistryClient::class)->sectionRoster('it1a');

    expect($roster->section)->toBe('IT1A')
        ->and($roster->count())->toBeGreaterThan(0)
        ->and(collect($roster->students)->pluck('section')->unique()->all())->toBe(['IT1A']);
});

it('E2E-07 returns an empty roster for an unknown section', function () {
    expect(app(SchoolRegistryClient::class)->sectionRoster('ZZ9Z')->count())->toBe(0);
});
