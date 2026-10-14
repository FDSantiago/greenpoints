<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RedemptionResource;
use App\Models\Redemption;
use App\Services\RedemptionService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(private RedemptionService $service, private WalletService $wallet)
    {
    }

    /**
     * POST /rewards/{rewardId}/redeem
     * Optional header "Idempotency-Key" (<= 64 chars: letters, digits, _ - : .). Generate one UUID
     * per button press on the client and reuse it on retries: a retry returns the same redemption
     * (HTTP 200) instead of charging again.
     */
    public function store(Request $request, int $rewardId): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key !== null && ! preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key must be 1-64 characters: letters, digits, _ - : .'],
            ]);
        }

        [$redemption, $replayed] = $this->service->redeem($request->user(), $rewardId, $key);

        return response()->json([
            'data' => (new RedemptionResource($redemption))->resolve(),
            'balance_after' => $this->wallet->balance($request->user()),
        ], $replayed ? 200 : 201, $replayed ? ['Idempotent-Replayed' => 'true'] : []);
    }

    /** GET /redemptions?status= : my redemptions, newest first. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'ready', 'claimed', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Redemption::with('reward')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 15));
        $page->setCollection($page->getCollection()->map(fn ($r) => (new RedemptionResource($r))->resolve()));

        return response()->json($page);
    }

    /** GET /redemptions/{id} : owner or staff. */
    public function show(Request $request, int $redemptionId): JsonResponse
    {
        $redemption = Redemption::with(['reward', 'user.group'])->find($redemptionId);

        abort_if(
            ! $redemption || (! $request->user()->isStaff() && $redemption->user_id !== $request->user()->id),
            404
        );

        return response()->json(['data' => (new RedemptionResource($redemption))->resolve()]);
    }

    /** POST /redemptions/{id}/cancel : refunds the points and returns the stock. */
    public function cancel(Request $request, int $redemptionId): JsonResponse
    {
        $redemption = $this->service->cancel($redemptionId, $request->user());

        return response()->json([
            'data' => (new RedemptionResource($redemption))->resolve(),
            'balance' => $this->wallet->balance($request->user()),
        ]);
    }
}
