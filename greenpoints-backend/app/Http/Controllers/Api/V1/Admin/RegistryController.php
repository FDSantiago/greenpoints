<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RosterSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin view of the School Registry (System 2) integration. */
class RegistryController extends Controller
{
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
