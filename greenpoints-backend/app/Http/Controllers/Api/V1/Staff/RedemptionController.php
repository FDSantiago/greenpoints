<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\RedemptionResource;
use App\Models\Redemption;
use App\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedemptionController extends Controller
{
    public function __construct(private RedemptionService $service)
    {
    }

    /**
     * GET /staff/redemptions?status=pending&code=ABCD2345
     * Default view is the pending queue. Searching by code looks across all statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'ready', 'claimed', 'cancelled'])],
            'code' => ['nullable', 'string', 'max:16'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Redemption::with(['reward', 'user.group'])
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id');

        if (! empty($filters['code'])) {
            $query->where('redemption_code', strtoupper($filters['code']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif (empty($filters['code'])) {
            $query->where('status', Redemption::STATUS_PENDING);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 15));
        $page->setCollection($page->getCollection()->map(fn ($r) => (new RedemptionResource($r))->resolve()));

        return response()->json($page);
    }

    /** POST /staff/redemptions/{id}/ready */
    public function ready(Request $request, int $redemptionId): JsonResponse
    {
        $redemption = $this->service->markReady($redemptionId, $request->user());

        return response()->json(['data' => (new RedemptionResource($redemption))->resolve()]);
    }

    /** POST /staff/redemptions/{id}/claim : handed over at the desk. */
    public function claim(Request $request, int $redemptionId): JsonResponse
    {
        $redemption = $this->service->claim($redemptionId, $request->user());

        return response()->json(['data' => (new RedemptionResource($redemption))->resolve()]);
    }
}
