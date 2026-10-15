<?php

use App\Models\Reward;
use App\Models\User;
use App\Models\WasteCategory;

beforeEach(fn () => $this->seed());

it('covers notification creation, ownership, reading, and bulk actions', function () {
    $staff = User::where('email', 'staff@greenpoints.test')->firstOrFail();
    $admin = User::where('email', 'admin@greenpoints.test')->firstOrFail();
    $student = userWithRole(User::ROLE_STUDENT);
    $other = userWithRole(User::ROLE_STUDENT);
    $plastic = WasteCategory::where('name', 'Plastic')->firstOrFail();
    $paper = WasteCategory::where('name', 'Paper')->firstOrFail();
    $list = fn ($user, string $query = '') => apiRequest($this, 'GET', '/notifications?per_page=50'.$query, $user)
        ->assertOk()
        ->json();
    $submit = fn ($user, array $items) => apiRequest($this, 'POST', '/waste-submissions', $user, ['items' => $items])
        ->assertCreated();
    $earn = function (int $weight) use ($student, $staff, $plastic) {
        $items = [];
        while ($weight > 0) {
            $chunk = min($weight, 100);
            $items[] = [
                'category_id' => $plastic->id,
                'weight_kg' => $chunk,
                'waste_condition' => 'clean',
            ];
            $weight -= $chunk;
        }
        $submission = apiRequest($this, 'POST', '/waste-submissions', $student, [
            'items' => $items,
        ])->assertCreated();

        return apiRequest($this, 'POST', "/staff/submissions/{$submission->json('data.id')}/verify", $staff)
            ->assertOk();
    };

    apiRequest($this, 'GET', '/notifications')->assertUnauthorized();
    expect($list($student)['total'])->toBe(0);
    apiRequest($this, 'GET', '/notifications/unread-count', $student)->assertJsonPath('data.unread', 0);
    apiRequest($this, 'GET', '/dashboard', $student)->assertJsonPath('data.unread_notifications', 0);

    $created = $submit($student, [
        ['category_id' => $plastic->id, 'weight_kg' => 2, 'waste_condition' => 'clean'],
    ]);
    $submissionId = $created->json('data.id');
    expect($list($student)['total'])->toBe(0);
    $verified = apiRequest($this, 'POST', "/staff/submissions/{$submissionId}/verify", $staff)->assertOk();
    $points = $verified->json('data.points_awarded');
    $notifications = $list($student)['data'];
    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['style'])->toBe('goal')
        ->and($notifications[0]['title'])->toContain("{$points} points")
        ->and($notifications[0]['body'])->toContain('2 kg')
        ->and($notifications[0]['link'])->toMatchArray(['type' => 'submission', 'id' => $submissionId])
        ->and($notifications[0]['read'])->toBeFalse()
        ->and($notifications[0]['read_at'])->toBeNull();
    apiRequest($this, 'GET', '/notifications/unread-count', $student)->assertJsonPath('data.unread', 1);
    apiRequest($this, 'GET', '/dashboard', $student)->assertJsonPath('data.unread_notifications', 1);
    apiRequest($this, 'POST', "/staff/submissions/{$submissionId}/verify", $staff)->assertStatus(409);
    expect($list($student)['total'])->toBe(1);

    $notificationId = $notifications[0]['id'];
    apiRequest($this, 'POST', "/notifications/{$notificationId}/read", $student)
        ->assertOk()
        ->assertJsonPath('data.read', true);
    apiRequest($this, 'POST', "/notifications/{$notificationId}/read", $student)->assertOk();
    apiRequest($this, 'GET', '/notifications/unread-count', $student)->assertJsonPath('data.unread', 0);
    apiRequest($this, 'POST', "/notifications/{$notificationId}/read", $other)->assertNotFound();
    apiRequest($this, 'POST', '/notifications/00000000-0000-4000-8000-000000000000/read', $student)
        ->assertNotFound();

    $rejected = $submit($student, [['category_id' => $paper->id, 'weight_kg' => 0.3]]);
    $rejectedId = $rejected->json('data.id');
    apiRequest($this, 'POST', "/staff/submissions/{$rejectedId}/reject", $staff, [
        'reason' => 'Not sorted properly',
    ])->assertOk();
    $notifications = $list($student)['data'];
    expect($notifications[0]['style'])->toBe('alert')
        ->and($notifications[0]['title'])->toBe('Drop-off rejected')
        ->and($notifications[0]['body'])->toContain('Not sorted properly')
        ->and($notifications[0]['link']['id'])->toBe($rejectedId);

    $reward = Reward::where('is_active', true)->where('stock_qty', '>', 0)->orderBy('points_cost')->firstOrFail();
    $earn((int) ceil($reward->points_cost * 2 / $plastic->points_per_kg));
    expect($list($student)['total'])->toBe(3);
    $first = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student)->assertCreated();
    expect($list($student)['total'])->toBe(3);
    $firstId = $first->json('data.id');
    apiRequest($this, 'POST', "/staff/redemptions/{$firstId}/ready", $staff)->assertOk();
    $notifications = $list($student)['data'];
    expect($notifications[0]['style'])->toBe('info')
        ->and($notifications[0]['title'])->toBe('Your reward is ready for pickup')
        ->and($notifications[0]['body'])->toContain($first->json('data.code'))
        ->and($notifications[0]['body'])->toContain($reward->name)
        ->and($notifications[0]['link'])->toMatchArray(['type' => 'redemption', 'id' => $firstId]);
    apiRequest($this, 'POST', "/staff/redemptions/{$firstId}/claim", $staff)->assertOk();
    expect($list($student)['data'][0]['title'])->toBe('Reward collected');

    $staffCancelled = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student)->assertCreated();
    $staffCancelledId = $staffCancelled->json('data.id');
    apiRequest($this, 'POST', "/redemptions/{$staffCancelledId}/cancel", $staff)->assertOk();
    expect($list($student)['data'][0]['title'])->toBe('Redemption cancelled')
        ->and($list($student)['data'][0]['body'])->toContain('refunded');

    $selfCancelled = apiRequest($this, 'POST', "/rewards/{$reward->id}/redeem", $student)->assertCreated();
    apiRequest($this, 'POST', "/redemptions/{$selfCancelled->json('data.id')}/cancel", $student)->assertOk();
    expect($list($student)['total'])->toBe(6);

    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => 50, 'note' => 'Event bonus.',
    ])->assertCreated();
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => -10, 'note' => 'Correction.',
    ])->assertCreated();
    $notifications = $list($student)['data'];
    expect($notifications)->toHaveCount(8)
        ->and($notifications[0]['title'])->toBe('Points adjusted: -10')
        ->and($notifications[0]['style'])->toBe('alert')
        ->and($notifications[1]['title'])->toBe('Points adjusted: +50')
        ->and($notifications[1]['style'])->toBe('goal')
        ->and($notifications[1]['body'])->toContain('Event bonus.')
        ->and($notifications[1]['body'])->toContain('balance is now')
        ->and($notifications[1]['link']['type'])->toBe('wallet');

    $unread = $list($student, '&status=unread');
    expect($unread['total'])->toBe(7)
        ->and(collect($unread['data'])->every(fn ($notification) => $notification['read'] === false))->toBeTrue();
    $page = apiRequest($this, 'GET', '/notifications?per_page=3', $student)->assertOk();
    expect($page->json('data'))->toHaveCount(3)
        ->and($page->json('last_page'))->toBe(3)
        ->and($page->json('total'))->toBe(8);
    $ordered = $list($student)['data'];
    $timestamps = array_column($ordered, 'created_at');
    for ($index = 1; $index < count($timestamps); $index++) {
        expect($timestamps[$index - 1] >= $timestamps[$index])->toBeTrue();
    }
    apiRequest($this, 'POST', '/notifications/read-all', $student)
        ->assertOk()
        ->assertJsonPath('data.marked', 7);
    apiRequest($this, 'GET', '/notifications/unread-count', $student)->assertJsonPath('data.unread', 0);
    apiRequest($this, 'GET', '/dashboard', $student)->assertJsonPath('data.unread_notifications', 0);
    expect($list($student, '&status=unread')['total'])->toBe(0);
    apiRequest($this, 'POST', '/notifications/read-all', $student)->assertJsonPath('data.marked', 0);

    $victim = $ordered[0]['id'];
    apiRequest($this, 'DELETE', "/notifications/{$victim}", $other)->assertNotFound();
    apiRequest($this, 'DELETE', "/notifications/{$victim}", $student)->assertNoContent();
    expect($list($student)['total'])->toBe(7)
        ->and($list($other)['total'])->toBe(0);
});
