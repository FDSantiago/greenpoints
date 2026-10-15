<?php

use App\Models\Reward;
use App\Models\User;
use App\Models\WasteCategory;

beforeEach(fn () => $this->seed());

it('covers wallet, redemption, fulfillment, leaderboard, and dashboard behavior', function () {
    $staff = User::where('email', 'staff@greenpoints.test')->firstOrFail();
    $student = userWithRole(User::ROLE_STUDENT);
    $other = userWithRole(User::ROLE_STUDENT);
    $plastic = WasteCategory::where('name', 'Plastic')->firstOrFail();
    $reward = Reward::where('is_active', true)->where('stock_qty', '>', 0)->orderBy('points_cost')->firstOrFail();
    $cost = $reward->points_cost;
    $earn = function (int $points) use ($student, $staff, $plastic) {
        $weight = (int) ceil($points / ($plastic->points_per_kg * config('greenpoints.condition_multipliers.clean')));
        $submission = apiRequest($this, 'POST', '/waste-submissions', $student, [
            'items' => [['category_id' => $plastic->id, 'weight_kg' => $weight, 'waste_condition' => 'clean']],
        ])->assertCreated();

        return apiRequest($this, 'POST', "/staff/submissions/{$submission->json('data.id')}/verify", $staff)
            ->assertOk()
            ->json('data.points_awarded');
    };

    $initial = apiRequest($this, 'GET', '/wallet', $student)->assertOk();
    $initial->assertJsonPath('data.balance', 0);
    expect($initial->json('data.next_reward'))->not->toBeNull();
    $rewards = apiRequest($this, 'GET', '/rewards', $student)->assertOk();
    expect($rewards->json('data'))->not->toBeEmpty();
    expect(collect($rewards->json('data'))->every(fn ($item) => $item['can_afford'] === false))->toBeTrue();
    apiRequest($this, 'GET', '/rewards?affordable=true', $student)->assertOk()->assertJsonPath('data', []);
    apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student)->assertUnprocessable();
    apiRequest($this, 'POST', '/rewards/999999/redeem', $student)->assertNotFound();

    $earnedPoints = $earn($cost);
    expect($earnedPoints)->toBeGreaterThanOrEqual($cost)->toBeLessThan($cost * 2);
    $wallet = apiRequest($this, 'GET', '/wallet', $student)->assertOk();
    $wallet->assertJsonPath('data.balance', $earnedPoints);
    $wallet->assertJsonPath('data.earned_this_month', $earnedPoints);
    $earnedTransactions = apiRequest($this, 'GET', '/wallet/transactions?type=earned', $student)
        ->assertOk()
        ->json('data');
    expect($earnedTransactions)->not->toBeEmpty();
    expect(collect($earnedTransactions)->every(fn ($transaction) => $transaction['points'] > 0))->toBeTrue();
    expect($earnedTransactions[0]['waste']['category'])->toBe('Plastic');
    apiRequest($this, 'GET', '/wallet/transactions?type=redeemed', $student)
        ->assertOk()
        ->assertJsonPath('data', []);
    apiRequest($this, 'GET', '/rewards?affordable=true', $student)
        ->assertOk()
        ->assertJsonFragment(['id' => $reward->id]);

    $detail = apiRequest($this, 'GET', "/rewards/{$reward->id}", $student)->assertOk();
    $detail->assertJsonPath('data.preview.balance_after', $earnedPoints - $cost);
    $stockBefore = $detail->json('data.stock_qty');
    $key = 'pest-redemption-key-0001';
    $redeemed = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student, [], [
        'Idempotency-Key' => $key,
    ])->assertCreated();
    $redemptionId = $redeemed->json('data.id');
    $redeemed->assertJsonPath('data.status', 'pending');
    $redeemed->assertJsonPath('data.points_spent', $cost);
    $redeemed->assertJsonPath('balance_after', $earnedPoints - $cost);
    expect($redeemed->json('data.code'))->toMatch('/^[A-Z0-9]{8}$/');
    $replay = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student, [], [
        'Idempotency-Key' => $key,
    ])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $replay->assertJsonPath('data.id', $redemptionId);
    apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student, [], [
        'Idempotency-Key' => 'pest-redemption-key-0003',
    ])->assertUnprocessable();
    apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student, [], [
        'Idempotency-Key' => 'bad key!',
    ])->assertUnprocessable();

    apiRequest($this, 'GET', '/wallet', $student)->assertJsonPath('data.balance', $earnedPoints - $cost);
    apiRequest($this, 'GET', "/rewards/{$reward->id}", $student)
        ->assertJsonPath('data.stock_qty', $stockBefore - 1);
    apiRequest($this, 'GET', '/wallet/transactions?type=redeemed', $student)
        ->assertJsonPath('data.0.points', -$cost)
        ->assertJsonPath('data.0.redemption.reward', $reward->name);
    apiRequest($this, 'GET', '/redemptions', $student)->assertJsonFragment(['id' => $redemptionId]);
    apiRequest($this, 'GET', "/redemptions/{$redemptionId}", $student)->assertOk();
    apiRequest($this, 'GET', "/redemptions/{$redemptionId}", $other)->assertNotFound();
    apiRequest($this, 'GET', '/staff/redemptions', $other)->assertForbidden();
    apiRequest($this, 'GET', '/staff/redemptions?code='.strtolower($redeemed->json('data.code')), $staff)
        ->assertOk()
        ->assertJsonFragment(['id' => $redemptionId]);

    apiRequest($this, 'POST', "/staff/redemptions/{$redemptionId}/ready", $staff)
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');
    apiRequest($this, 'POST', "/staff/redemptions/{$redemptionId}/ready", $staff)->assertStatus(409);
    apiRequest($this, 'POST', "/staff/redemptions/{$redemptionId}/claim", $staff)
        ->assertOk()
        ->assertJsonPath('data.status', 'claimed');
    apiRequest($this, 'POST', "/staff/redemptions/{$redemptionId}/claim", $staff)->assertStatus(409);
    apiRequest($this, 'POST', "/redemptions/{$redemptionId}/cancel", $student)->assertStatus(409);

    $earn($cost);
    $balanceBeforeCancel = apiRequest($this, 'GET', '/wallet', $student)->json('data.balance');
    $stockBeforeCancel = apiRequest($this, 'GET', "/rewards/{$reward->id}", $student)->json('data.stock_qty');
    $pending = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student, [], [
        'Idempotency-Key' => 'pest-redemption-key-0002',
    ])->assertCreated();
    $pendingId = $pending->json('data.id');
    apiRequest($this, 'POST', "/redemptions/{$pendingId}/cancel", $student)
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
    apiRequest($this, 'GET', '/wallet', $student)->assertJsonPath('data.balance', $balanceBeforeCancel);
    apiRequest($this, 'GET', "/rewards/{$reward->id}", $student)
        ->assertJsonPath('data.stock_qty', $stockBeforeCancel);
    apiRequest($this, 'POST', "/redemptions/{$pendingId}/cancel", $student)->assertStatus(409);
    apiRequest($this, 'POST', "/redemptions/{$pendingId}/cancel", $other)->assertNotFound();

    $leaderboard = apiRequest($this, 'GET', '/leaderboard?scope=individual&period=weekly&limit=50', $student)
        ->assertOk();
    expect($leaderboard->json('data.entries'))->toBeArray();
    expect($leaderboard->json('data.me.points'))->toBe($earnedPoints * 2);
    expect($leaderboard->json('data.me.rank'))->toBeGreaterThanOrEqual(1);
    apiRequest($this, 'GET', '/leaderboard?scope=group&period=monthly&metric=per_member', $student)->assertOk();
    apiRequest($this, 'GET', '/leaderboard?scope=nope', $student)->assertUnprocessable();

    $dashboard = apiRequest($this, 'GET', '/dashboard', $student)->assertOk();
    expect($dashboard->json('data.points.balance'))->toBe($balanceBeforeCancel);
    expect($dashboard->json('data.recent_submissions'))->not->toBeEmpty();
    expect($dashboard->json('data.rank.individual.rank'))->toBeGreaterThanOrEqual(1);
});
