<?php

use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    useFakeRegistry();
    Sleep::fake();
    Http::preventStrayRequests();

    $this->admin = userWithRole(User::ROLE_ADMIN);
});

it('reports registry health, client configuration and the previous call, never the key', function () {
    Http::fake([
        FAKE_REGISTRY.'/api/v1/students/*' => Http::response(registryStudent()),
        FAKE_REGISTRY.'/health' => Http::response(['status' => 'ok', 'version' => '1.0.0', 'time' => '2026-10-24T23:46:50.000Z']),
    ]);
    app(SchoolRegistryClient::class)->findStudent('2024-00309');

    $response = apiRequest($this, 'GET', '/admin/registry/health', $this->admin)
        ->assertOk()
        ->assertJsonPath('data.registry.reachable', true)
        ->assertJsonPath('data.registry.status', 'ok')
        ->assertJsonPath('data.registry.version', '1.0.0')
        ->assertJsonPath('data.client', [
            'enabled' => true,
            'base_url' => FAKE_REGISTRY,
            'key_configured' => true,
            'timeout_seconds' => 3,
            'retries' => 2,
        ])
        ->assertJsonPath('data.last_call.path', '/api/v1/students/2024-00309')
        ->assertJsonPath('data.last_call.status', 200)
        ->assertJsonStructure(['data' => [
            'registry' => ['latency_ms'],
            'last_call' => ['method', 'path', 'status', 'ms', 'request_id', 'attempt', 'at'],
        ]]);

    expect($response->getContent())->not->toContain(FAKE_KEY);
});

it('reports an unreachable registry with 200 instead of failing', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    apiRequest($this, 'GET', '/admin/registry/health', $this->admin)
        ->assertOk()
        ->assertJsonPath('data.registry.reachable', false)
        ->assertJsonPath('data.registry.error', 'RegistryUnavailable')
        ->assertJsonPath('data.last_call', null);

    // The failed probe is now the last call.
    apiRequest($this, 'GET', '/admin/registry/health', $this->admin)
        ->assertJsonPath('data.last_call.status', 'connection_error');
});

it('reports a missing key as not configured', function () {
    config(['services.school_registry.read_key' => '']);
    Http::fake();

    apiRequest($this, 'GET', '/admin/registry/health', $this->admin)
        ->assertOk()
        ->assertJsonPath('data.registry.reachable', false)
        ->assertJsonPath('data.client.key_configured', false);

    Http::assertNothingSent();
});

it('is admin only', function (string $role) {
    Http::fake();

    apiRequest($this, 'GET', '/admin/registry/health', userWithRole($role))->assertForbidden();
    apiRequest($this, 'GET', '/admin/registry/health')->assertUnauthorized();

    Http::assertNothingSent();
})->with([User::ROLE_STUDENT, User::ROLE_STAFF]);
