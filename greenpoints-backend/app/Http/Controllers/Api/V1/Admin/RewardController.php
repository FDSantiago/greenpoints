<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminRewardResource;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RewardController extends Controller
{
    /** All rewards, including inactive and out-of-stock ones. */
    public function index(): JsonResponse
    {
        $rewards = Reward::withCount('redemptions')->orderBy('points_cost')->orderBy('name')->get();

        return response()->json(['data' => AdminRewardResource::collection($rewards)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:rewards,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:255'],
            'points_cost' => ['required', 'integer', 'min:1', 'max:1000000'],
            'stock_qty' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reward = Reward::create($data + ['is_active' => true]);

        return response()->json(['data' => (new AdminRewardResource($reward))->resolve()], 201);
    }

    public function show(int $rewardId): JsonResponse
    {
        $reward = Reward::withCount('redemptions')->findOrFail($rewardId);

        return response()->json(['data' => (new AdminRewardResource($reward))->resolve()]);
    }

    /** Price changes apply to FUTURE redemptions only (each redemption snapshots points_spent). */
    public function update(Request $request, int $rewardId): JsonResponse
    {
        $reward = Reward::findOrFail($rewardId);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('rewards', 'name')->ignore($reward->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'points_cost' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'stock_qty' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $reward->fill($data)->save();

        return response()->json(['data' => (new AdminRewardResource(Reward::withCount('redemptions')->find($reward->id)))->resolve()]);
    }

    public function destroy(int $rewardId): Response
    {
        $reward = Reward::findOrFail($rewardId);

        abort_if(
            $reward->redemptions()->exists(),
            409,
            'This reward has been redeemed before and cannot be deleted. Deactivate it instead (PATCH is_active=false).'
        );

        $reward->delete();

        return response()->noContent();
    }
}
