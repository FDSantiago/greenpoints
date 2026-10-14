<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointTransactionResource;
use App\Models\PointTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    /** GET /wallet : balance card + progress toward the next reward. */
    public function show(Request $request, WalletService $wallet): JsonResponse
    {
        return response()->json(['data' => $wallet->summary($request->user())]);
    }

    /** GET /wallet/transactions?type=earned|redeemed : the two tabs under the balance card. */
    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['earned', 'redeemed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = PointTransaction::with(['wasteLog.category', 'redemption.reward'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // "earned" = every positive row (earn, refund, positive adjustment); "redeemed" = every negative row.
        if (($filters['type'] ?? null) === 'earned') {
            $query->where('points', '>', 0);
        } elseif (($filters['type'] ?? null) === 'redeemed') {
            $query->where('points', '<', 0);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $page->setCollection(
            $page->getCollection()->map(fn ($t) => (new PointTransactionResource($t))->resolve())
        );

        return response()->json($page);
    }
}
