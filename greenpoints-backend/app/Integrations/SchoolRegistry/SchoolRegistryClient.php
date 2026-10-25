<?php

namespace App\Integrations\SchoolRegistry;

use App\Integrations\SchoolRegistry\Exceptions\RegistryAuthFailed;
use App\Integrations\SchoolRegistry\Exceptions\RegistryBadResponse;
use App\Integrations\SchoolRegistry\Exceptions\RegistryUnavailable;
use App\Integrations\SchoolRegistry\Exceptions\StudentNotFound;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * HTTP client for the School Registry (System 2). Contract: sia_school-registry/docs/openapi.yaml.
 *
 * Every call is a GET with the read-only key in X-API-Key and a request id in X-Request-Id.
 * Connection errors (including timeouts) and 5xx answers are retried a few times with a short
 * backoff; everything else is answered immediately. Failures become typed exceptions.
 */
class SchoolRegistryClient
{
    // The registry only echoes request ids in this format; anything else it replaces.
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    private const LAST_CALL_CACHE_KEY = 'school_registry.last_call';

    private string $baseUrl;

    private string $key;

    private int $timeout;

    private int $retries;

    public function __construct()
    {
        $config = config('services.school_registry');

        $this->baseUrl = rtrim((string) $config['base_url'], '/');
        $this->key = (string) $config['read_key'];
        $this->timeout = max(1, (int) $config['timeout']);
        $this->retries = max(0, (int) $config['retries']);
    }

    /** GET /api/v1/students/{student_id}: the student, whatever their status. */
    public function findStudent(string $studentId): RegistryStudent
    {
        [$response, $requestId] = $this->get('/api/v1/students/'.rawurlencode($studentId));

        if ($response->status() === 404) {
            throw new StudentNotFound("The School Registry has no student with id {$studentId}.", $requestId);
        }

        return RegistryStudent::fromArray($this->successJson($response, $requestId), $requestId);
    }

    /** GET /api/v1/sections/{section}/students as JSON. A 404 (no students) is an empty roster. */
    public function sectionRoster(string $section): RegistryRoster
    {
        [$response, $requestId] = $this->get('/api/v1/sections/'.rawurlencode($section).'/students');

        if ($response->status() === 404) {
            return new RegistryRoster(Str::upper($section), []);
        }

        return RegistryRoster::fromArray($this->successJson($response, $requestId), $requestId);
    }

    /** GET /health */
    public function health(): RegistryHealth
    {
        $started = hrtime(true);
        [$response, $requestId] = $this->get('/health');
        $latencyMs = (int) round((hrtime(true) - $started) / 1e6);

        return RegistryHealth::fromArray($this->successJson($response, $requestId), $latencyMs, $requestId);
    }

    /**
     * Send one GET (plus retries) and return the final response with the request id used.
     *
     * @return array{0: Response, 1: string}
     */
    private function get(string $path): array
    {
        $requestId = $this->requestId();

        if ($this->baseUrl === '' || $this->key === '') {
            throw new RegistryUnavailable('The School Registry is not configured (SCHOOL_REGISTRY_URL / SCHOOL_REGISTRY_KEY).', $requestId);
        }

        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);

            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->acceptJson()
                    ->timeout($this->timeout)
                    ->connectTimeout($this->timeout)
                    ->withHeaders(['X-API-Key' => $this->key, 'X-Request-Id' => $requestId])
                    ->get($path);
            } catch (ConnectionException) {
                $this->log($path, 'connection_error', $started, $requestId, $attempt);

                if ($attempt > $this->retries) {
                    throw new RegistryUnavailable('The School Registry could not be reached.', $requestId);
                }
                $this->backoff($attempt);

                continue;
            }

            $this->log($path, $response->status(), $started, $requestId, $attempt);

            if ($response->serverError() && $attempt <= $this->retries) {
                $this->backoff($attempt);

                continue;
            }

            return [$response, $requestId];
        }
    }

    /** Map every non-2xx answer the caller did not handle to a typed exception; return the JSON body. */
    private function successJson(Response $response, string $requestId): mixed
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new RegistryAuthFailed("The School Registry rejected our API key (HTTP {$status}).", $requestId);
        }
        if ($status === 429 || $response->serverError()) {
            throw new RegistryUnavailable("The School Registry is unavailable (HTTP {$status}).", $requestId);
        }
        if (! $response->successful()) {
            throw new RegistryBadResponse("Unexpected answer from the School Registry (HTTP {$status}).", $requestId);
        }

        return $response->json();
    }

    /** Reuse the incoming X-Request-Id when the registry would accept it, so one id spans both systems. */
    private function requestId(): string
    {
        $incoming = request()->header('X-Request-Id'); // null in artisan commands

        return is_string($incoming) && preg_match(self::REQUEST_ID_PATTERN, $incoming)
            ? $incoming
            : (string) Str::uuid();
    }

    private function backoff(int $attempt): void
    {
        Sleep::for(200 * $attempt)->milliseconds();
    }

    /** The most recent outbound attempt (for the admin health endpoint), or null if none yet. */
    public static function lastCall(): ?array
    {
        return Cache::get(self::LAST_CALL_CACHE_KEY);
    }

    // One line per outbound attempt, also remembered as the "last call". The API key is never logged.
    private function log(string $path, int|string $status, int $started, string $requestId, int $attempt): void
    {
        $call = [
            'method' => 'GET',
            'path' => $path,
            'status' => $status,
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
            'request_id' => $requestId,
            'attempt' => $attempt,
        ];

        Log::info('school_registry.call', $call);
        Cache::forever(self::LAST_CALL_CACHE_KEY, $call + ['at' => now()->toIso8601String()]);
    }
}
