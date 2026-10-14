<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\WasteCategory;

class PointsCalculator
{
    /** Multiplier for a waste condition ('clean', 'contaminated'); unknown values count as 1.0. */
    public function factorFor(string $condition): float
    {
        return (float) config("greenpoints.condition_multipliers.{$condition}", 1.0);
    }

    /**
     * Points for one waste item. Never negative. Honors the optional daily cap.
     * This is the ONLY place the scoring formula lives, so it is easy to change.
     */
    public function points(WasteCategory $category, float $weightKg, float $factor, int $userId): int
    {
        $points = (int) round($weightKg * (float) $category->points_per_kg * $factor);

        $cap = config('greenpoints.daily_points_cap');
        if ($cap !== null) {
            $earnedToday = (int) PointTransaction::where('user_id', $userId)
                ->where('type', PointTransaction::TYPE_EARN)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('points');

            $points = min($points, max(0, $cap - $earnedToday));
        }

        return max(0, $points);
    }
}
