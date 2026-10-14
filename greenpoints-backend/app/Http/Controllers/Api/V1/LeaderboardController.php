<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaderboardController extends Controller
{
    /**
     * GET /leaderboard?scope=group|individual&period=weekly|monthly|all&metric=total|per_member&limit=20
     * metric=per_member (group scope) divides by the number of students, so a bigger class
     * doesn't win just by headcount.
     */
    public function index(Request $request, LeaderboardService $leaderboard): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['group', 'individual'])],
            'period' => ['nullable', Rule::in(['weekly', 'monthly', 'all'])],
            'metric' => ['nullable', Rule::in(['total', 'per_member'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $scope = $filters['scope'] ?? 'group';
        $period = $filters['period'] ?? 'weekly';
        $metric = $scope === 'group' ? ($filters['metric'] ?? 'total') : 'total';
        $limit = (int) ($filters['limit'] ?? 20);

        $ranking = $leaderboard->ranking($scope, $period, $metric);
        $user = $request->user();
        $myId = $scope === 'individual' ? $user->id : $user->group_id;
        [$from, $to] = $leaderboard->bounds($period);

        return response()->json(['data' => [
            'scope' => $scope,
            'period' => $period,
            'metric' => $metric,
            'from' => $from,
            'to' => $to,
            'entries' => $ranking->take($limit)->values(),
            // The caller's own row (their group's row in group scope), even if outside the top N.
            'me' => $myId ? $ranking->firstWhere('id', $myId) : null,
        ]]);
    }
}
