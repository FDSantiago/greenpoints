<?php

use App\Http\Controllers\Api\V1\Admin\GroupController as AdminGroupController;
use App\Http\Controllers\Api\V1\Admin\ImpactReportController as AdminImpactReportController;
use App\Http\Controllers\Api\V1\Admin\RegistryController as AdminRegistryController;
use App\Http\Controllers\Api\V1\Admin\RewardController as AdminRewardController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Admin\WasteCategoryController as AdminWasteCategoryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RedemptionController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\Staff\RedemptionController as StaffRedemptionController;
use App\Http\Controllers\Api\V1\Staff\SubmissionController as StaffSubmissionController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WasteCategoryController;
use App\Http\Controllers\Api\V1\WasteSubmissionController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('dashboard', [DashboardController::class, 'show']);

        // Waste categories + the student's own submissions
        Route::get('waste-categories', [WasteCategoryController::class, 'index']);
        Route::get('waste-submissions/summary', [WasteSubmissionController::class, 'summary']);
        Route::get('waste-submissions', [WasteSubmissionController::class, 'index']);
        Route::post('waste-submissions', [WasteSubmissionController::class, 'store'])->middleware('throttle:30,1');
        Route::get('waste-submissions/{submissionId}', [WasteSubmissionController::class, 'show'])->whereUuid('submissionId');

        // Wallet
        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);

        // Rewards + redemptions
        Route::get('rewards', [RewardController::class, 'index']);
        Route::get('rewards/{rewardId}', [RewardController::class, 'show'])->whereNumber('rewardId');
        Route::post('rewards/{rewardId}/redeem', [RedemptionController::class, 'store'])
            ->whereNumber('rewardId')->middleware('throttle:10,1');
        Route::get('redemptions', [RedemptionController::class, 'index']);
        Route::get('redemptions/{redemptionId}', [RedemptionController::class, 'show'])->whereNumber('redemptionId');
        Route::post('redemptions/{redemptionId}/cancel', [RedemptionController::class, 'cancel'])->whereNumber('redemptionId');

        // Leaderboard
        Route::get('leaderboard', [LeaderboardController::class, 'index']);

        // Notifications (static paths first)
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereUuid('notificationId');
        Route::delete('notifications/{notificationId}', [NotificationController::class, 'destroy'])->whereUuid('notificationId');

        // Staff / admin only
        Route::middleware(EnsureStaff::class)->prefix('staff')->group(function () {
            Route::get('submissions', [StaffSubmissionController::class, 'index']);
            Route::post('submissions/{submissionId}/verify', [StaffSubmissionController::class, 'verify'])->whereUuid('submissionId');
            Route::post('submissions/{submissionId}/reject', [StaffSubmissionController::class, 'reject'])->whereUuid('submissionId');

            Route::get('redemptions', [StaffRedemptionController::class, 'index']);
            Route::post('redemptions/{redemptionId}/ready', [StaffRedemptionController::class, 'ready'])->whereNumber('redemptionId');
            Route::post('redemptions/{redemptionId}/claim', [StaffRedemptionController::class, 'claim'])->whereNumber('redemptionId');
        });

        // Admin only
        Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::post('users', [AdminUserController::class, 'store']);
            Route::get('users/{userId}', [AdminUserController::class, 'show'])->whereNumber('userId');
            Route::patch('users/{userId}', [AdminUserController::class, 'update'])->whereNumber('userId');
            Route::delete('users/{userId}', [AdminUserController::class, 'destroy'])->whereNumber('userId');
            Route::get('users/{userId}/transactions', [AdminUserController::class, 'transactions'])->whereNumber('userId');
            Route::post('users/{userId}/points-adjustments', [AdminUserController::class, 'adjustPoints'])->whereNumber('userId');

            Route::get('groups', [AdminGroupController::class, 'index']);
            Route::post('groups', [AdminGroupController::class, 'store']);
            Route::get('groups/{groupId}', [AdminGroupController::class, 'show'])->whereNumber('groupId');
            Route::patch('groups/{groupId}', [AdminGroupController::class, 'update'])->whereNumber('groupId');
            Route::delete('groups/{groupId}', [AdminGroupController::class, 'destroy'])->whereNumber('groupId');

            Route::get('waste-categories', [AdminWasteCategoryController::class, 'index']);
            Route::post('waste-categories', [AdminWasteCategoryController::class, 'store']);
            Route::get('waste-categories/{categoryId}', [AdminWasteCategoryController::class, 'show'])->whereNumber('categoryId');
            Route::patch('waste-categories/{categoryId}', [AdminWasteCategoryController::class, 'update'])->whereNumber('categoryId');
            Route::delete('waste-categories/{categoryId}', [AdminWasteCategoryController::class, 'destroy'])->whereNumber('categoryId');

            Route::get('rewards', [AdminRewardController::class, 'index']);
            Route::post('rewards', [AdminRewardController::class, 'store']);
            Route::get('rewards/{rewardId}', [AdminRewardController::class, 'show'])->whereNumber('rewardId');
            Route::patch('rewards/{rewardId}', [AdminRewardController::class, 'update'])->whereNumber('rewardId');
            Route::delete('rewards/{rewardId}', [AdminRewardController::class, 'destroy'])->whereNumber('rewardId');

            Route::get('reports/impact', [AdminImpactReportController::class, 'show']);
            Route::post('reports/impact/generate', [AdminImpactReportController::class, 'generate']);
            Route::get('reports/impact/history', [AdminImpactReportController::class, 'history']);
            Route::get('reports/impact/export', [AdminImpactReportController::class, 'export']);

            // School Registry (System 2) integration
            Route::post('registry/sync', [AdminRegistryController::class, 'sync']);
        });
    });
});
