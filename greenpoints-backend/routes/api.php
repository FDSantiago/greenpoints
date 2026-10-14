<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\RedemptionController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\Staff\RedemptionController as StaffRedemptionController;
use App\Http\Controllers\Api\V1\Staff\SubmissionController as StaffSubmissionController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WasteCategoryController;
use App\Http\Controllers\Api\V1\WasteSubmissionController;
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

        // Staff / admin only
        Route::middleware(EnsureStaff::class)->prefix('staff')->group(function () {
            Route::get('submissions', [StaffSubmissionController::class, 'index']);
            Route::post('submissions/{submissionId}/verify', [StaffSubmissionController::class, 'verify'])->whereUuid('submissionId');
            Route::post('submissions/{submissionId}/reject', [StaffSubmissionController::class, 'reject'])->whereUuid('submissionId');

            Route::get('redemptions', [StaffRedemptionController::class, 'index']);
            Route::post('redemptions/{redemptionId}/ready', [StaffRedemptionController::class, 'ready'])->whereNumber('redemptionId');
            Route::post('redemptions/{redemptionId}/claim', [StaffRedemptionController::class, 'claim'])->whereNumber('redemptionId');
        });
    });
});
