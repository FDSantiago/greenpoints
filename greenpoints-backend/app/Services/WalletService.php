<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\User;

class WalletService
{
    /** Balance is always derived from the ledger, never stored. */
    public function balance(User $user): int
    {
        return (int) PointTransaction::where('user_id', $user->id)->sum('points');
    }

    public function earnedThisMonth(User $user): int
    {
        return (int) PointTransaction::where('user_id', $user->id)
            ->where('type', PointTransaction::TYPE_EARN)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('points');
    }

    /** Cheapest in-stock reward the user cannot afford yet (drives the progress bar). */
    public function nextReward(int $balance): ?Reward
    {
        return Reward::where('is_active', true)
            ->where('stock_qty', '>', 0)
            ->where('points_cost', '>', $balance)
            ->orderBy('points_cost')
            ->first();
    }

    public function summary(User $user): array
    {
        $balance = $this->balance($user);
        $next = $this->nextReward($balance);

        return [
            'balance' => $balance,
            'earned_this_month' => $this->earnedThisMonth($user),
            'next_reward' => $next ? [
                'id' => $next->id,
                'name' => $next->name,
                'points_cost' => $next->points_cost,
                'points_needed' => $next->points_cost - $balance,
                'progress_percent' => (int) min(100, floor($balance / max(1, $next->points_cost) * 100)),
            ] : null,
        ];
    }
}
