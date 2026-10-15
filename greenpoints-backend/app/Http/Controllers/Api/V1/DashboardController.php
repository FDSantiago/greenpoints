<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RedemptionResource;
use App\Models\Redemption;
use App\Models\WasteLog;
use App\Services\LeaderboardService;
use App\Services\WalletService;
use App\Services\WasteSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /dashboard : everything the home screen needs in one round trip. */
    public function show(
        Request $request,
        WalletService $wallet,
        LeaderboardService $leaderboard,
        WasteSubmissionService $submissions,
    ): JsonResponse {
        $user = $request->user()->load('group');

        $myRank = $leaderboard->ranking('individual', 'weekly')->firstWhere('id', $user->id);
        $groupRank = $user->group_id
            ? $leaderboard->ranking('group', 'weekly')->firstWhere('id', $user->group_id)
            : null;

        $recent = $submissions->paginate(
            WasteLog::query()->where('user_id', $user->id),
            3
        )->items();

        $pendingCount = WasteLog::where('user_id', $user->id)
            ->where('status', WasteLog::STATUS_PENDING)
            ->distinct()
            ->count('submission_id');

        $activeRedemptions = Redemption::with('reward')
            ->where('user_id', $user->id)
            ->whereIn('status', [Redemption::STATUS_PENDING, Redemption::STATUS_READY])
            ->orderByDesc('redeemed_at')
            ->get()
            ->map(fn ($r) => (new RedemptionResource($r))->resolve());

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'group' => $user->group?->name,
            ],
            'points' => $wallet->summary($user),
            'rank' => [
                'individual' => $myRank ? ['rank' => $myRank['rank'], 'points' => $myRank['points']] : null,
                'group' => $groupRank ? [
                    'name' => $groupRank['name'],
                    'rank' => $groupRank['rank'],
                    'points' => $groupRank['points'],
                ] : null,
                'period' => 'weekly',
            ],
            'pending_submissions' => $pendingCount,
            'unread_notifications' => $user->unreadNotifications()->count(),
            'recent_submissions' => $recent,
            'active_redemptions' => $activeRedemptions->values(),
        ]]);
    }
}
