<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\SchoolRegistry\Exceptions\RegistryException;
use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use App\Services\RosterSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin view of the School Registry (System 2) integration. */
class RegistryController extends Controller
{
    /**
     * GET /admin/registry/health: is System 2 reachable, how is our client configured, and how did
     * the last outbound call go. Always 200: an unreachable registry is reported, not raised.
     */
    public function health(SchoolRegistryClient $client): JsonResponse
    {
        $config = config('services.school_registry');
        $lastCall = SchoolRegistryClient::lastCall(); // read before probing, so it shows real traffic

        try {
            $health = $client->health();
            $registry = [
                'reachable' => true,
                'status' => $health->status,
                'version' => $health->version,
                'time' => $health->time,
                'latency_ms' => $health->latencyMs,
            ];
        } catch (RegistryException $e) {
            $registry = [
                'reachable' => false,
                'error' => class_basename($e),
                'message' => $e->getMessage(),
                'request_id' => $e->requestId,
            ];
        }

        return response()->json(['data' => [
            'registry' => $registry,
            'client' => [
                'enabled' => (bool) $config['enabled'],
                'base_url' => $config['base_url'],
                'key_configured' => filled($config['read_key']), // never the key itself
                'timeout_seconds' => (int) $config['timeout'],
                'retries' => (int) $config['retries'],
            ],
            'last_call' => $lastCall,
        ]]);
    }

    /** POST /admin/registry/sync {section?}: pull rosters and report matched / updated / missing. */
    public function sync(Request $request, RosterSyncService $service): JsonResponse
    {
        $request->merge(['section' => $request->filled('section') ? Str::upper((string) $request->input('section')) : null]);
        $data = $request->validate([
            'section' => ['nullable', 'string', 'regex:/^[A-Z0-9-]{1,20}$/',
                Rule::exists('class_groups', 'name')->where('type', 'class')],
        ]);

        // Registry failures become 503 REGISTRY_UNAVAILABLE (bootstrap/app.php).
        return response()->json(['data' => $service->sync($data['section'] ?? null)]);
    }
}
