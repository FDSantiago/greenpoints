<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Models\Reward;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /** GET /rewards?affordable=true : the catalog, cheapest first, flagged with can_afford. */
    public function index(Request $request, WalletService $wallet): JsonResponse
    {
        $balance = $wallet->balance($request->user());

        $query = Reward::where('is_active', true)->orderBy('points_cost')->orderBy('name');

        if ($request->boolean('affordable')) {
            $query->where('points_cost', '<=', $balance)->where('stock_qty', '>', 0);
        }

        $data = $query->get()->map(
            fn (Reward $reward) => (new RewardResource($reward))->resolve()
                + ['can_afford' => $balance >= $reward->points_cost && $reward->stock_qty > 0]
        );

        return response()->json(['balance' => $balance, 'data' => $data->values()]);
    }

    /** GET /rewards/{id} : detail + the "Your points" preview from the redeem screen. */
    public function show(Request $request, WalletService $wallet, int $rewardId): JsonResponse
    {
        $reward = Reward::where('is_active', true)->find($rewardId);
        abort_if(! $reward, 404, 'Reward not found.');

        $balance = $wallet->balance($request->user());

        return response()->json(['data' => (new RewardResource($reward))->resolve() + [
            'preview' => [
                'balance' => $balance,
                'cost' => $reward->points_cost,
                'balance_after' => $balance - $reward->points_cost,
                'can_afford' => $balance >= $reward->points_cost && $reward->stock_qty > 0,
                'pickup' => [
                    'location' => config('greenpoints.pickup.location'),
                    'note' => config('greenpoints.pickup.note'),
                ],
            ],
        ]]);
    }
}
