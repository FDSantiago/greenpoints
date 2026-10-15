<?php

use App\Models\User;
use App\Models\WasteCategory;

beforeEach(fn () => $this->seed());

it('covers admin user, group, points, category, reward, and impact-report workflows', function () {
    $admin = User::where('email', 'admin@greenpoints.test')->firstOrFail();
    $staff = User::where('email', 'staff@greenpoints.test')->firstOrFail();
    $student = User::where('email', 'student1@greenpoints.test')->firstOrFail();
    $plastic = WasteCategory::where('name', 'Plastic')->firstOrFail();
    $registration = apiRequest($this, 'POST', '/auth/register', null, [
        'name' => 'Pest Student',
        'email' => 'pest-registration@greenpoints.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
    ])->assertCreated();
    $registration->assertJsonPath('user.role', 'student');
    $registeredStudent = User::findOrFail($registration->json('user.id'));

    apiRequest($this, 'GET', '/admin/users')->assertUnauthorized();
    apiRequest($this, 'GET', '/admin/users', $registeredStudent)->assertForbidden();
    apiRequest($this, 'GET', '/admin/users', $staff)->assertForbidden();
    apiRequest($this, 'GET', '/admin/users', $admin)->assertOk();

    $submission = apiRequest($this, 'POST', '/waste-submissions', $student, [
        'items' => [['category_id' => $plastic->id, 'weight_kg' => 2, 'waste_condition' => 'clean']],
    ])->assertCreated();
    $verified = apiRequest(
        $this,
        'POST',
        "/staff/submissions/{$submission->json('data.id')}/verify",
        $staff,
    )->assertOk();
    $points = $verified->json('data.points_awarded');
    expect($points)->toBeGreaterThan(0);
    $month = substr($submission->json('data.submitted_at'), 0, 7);

    $group = apiRequest($this, 'POST', '/admin/groups', $admin, [
        'name' => 'Pest Impact Group',
        'type' => 'zone',
    ])->assertCreated()->assertJsonPath('data.type', 'zone');
    $groupId = $group->json('data.id');
    apiRequest($this, 'POST', '/admin/groups', $admin, ['name' => 'Pest Impact Group'])
        ->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/groups', $admin, ['name' => 'x', 'type' => 'nope'])
        ->assertUnprocessable();
    apiRequest($this, 'PATCH', "/admin/groups/{$groupId}", $admin, [
        'name' => 'Pest Impact Group Updated',
        'type' => 'class',
    ])->assertOk()->assertJsonPath('data.type', 'class');
    apiRequest($this, 'GET', '/admin/groups', $admin)->assertJsonFragment([
        'id' => $groupId,
        'members_count' => 0,
    ]);
    apiRequest($this, 'PATCH', "/admin/users/{$student->id}", $admin, ['group_id' => $groupId])
        ->assertOk()
        ->assertJsonPath('data.group.id', $groupId);
    apiRequest($this, 'GET', "/admin/groups/{$groupId}", $admin)
        ->assertOk()
        ->assertJsonPath('data.members_count', 1);
    apiRequest($this, 'DELETE', "/admin/groups/{$groupId}", $admin)->assertStatus(409);

    $email = 'pest-admin-created@greenpoints.test';
    $createdUser = apiRequest($this, 'POST', '/admin/users', $admin, [
        'name' => 'Pest Staffer',
        'email' => $email,
        'password' => 'password123',
        'role' => 'staff',
    ])->assertCreated()->assertJsonPath('data.role', 'staff')->assertJsonPath('data.is_active', true);
    $userId = $createdUser->json('data.id');
    apiRequest($this, 'POST', '/admin/users', $admin, [
        'name' => 'Duplicate',
        'email' => $email,
        'password' => 'password123',
        'role' => 'student',
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/users', $admin, [
        'name' => 'Weak',
        'email' => 'pest-weak@greenpoints.test',
        'password' => 'short',
        'role' => 'student',
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/users', $admin, [
        'name' => 'Invalid Role',
        'email' => 'pest-invalid-role@greenpoints.test',
        'password' => 'password123',
        'role' => 'owner',
    ])->assertUnprocessable();

    apiRequest($this, 'GET', '/admin/users?q='.urlencode($email), $admin)
        ->assertOk()
        ->assertJsonPath('data.0.id', $userId);
    apiRequest($this, 'GET', "/admin/users?group_id={$groupId}", $admin)
        ->assertJsonFragment(['id' => $student->id]);
    apiRequest($this, 'GET', '/admin/users?role=staff', $admin)->assertJsonFragment(['id' => $userId]);
    apiRequest($this, 'GET', "/admin/users/{$student->id}", $admin)
        ->assertJsonPath('data.points_balance', $points);
    apiRequest($this, 'GET', '/admin/users/999999', $admin)->assertNotFound();
    apiRequest($this, 'PATCH', "/admin/users/{$admin->id}", $admin, ['role' => 'student'])
        ->assertUnprocessable();
    apiRequest($this, 'PATCH', "/admin/users/{$admin->id}", $admin, ['is_active' => false])
        ->assertUnprocessable();
    apiRequest($this, 'DELETE', "/admin/users/{$admin->id}", $admin)->assertUnprocessable();

    apiRequest($this, 'PATCH', "/admin/users/{$userId}", $admin, [
        'name' => 'Pest Renamed',
        'role' => 'student',
    ])->assertOk()->assertJsonPath('data.name', 'Pest Renamed')->assertJsonPath('data.role', 'student');
    $login = apiRequest($this, 'POST', '/auth/login', null, [
        'email' => $email,
        'password' => 'password123',
    ])->assertOk();
    $token = $login->json('token');
    apiRequest($this, 'GET', '/me', $token)->assertOk();
    apiRequest($this, 'PATCH', "/admin/users/{$userId}", $admin, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
    apiRequest($this, 'GET', '/me', $token)->assertUnauthorized();
    apiRequest($this, 'POST', '/auth/login', null, [
        'email' => $email,
        'password' => 'password123',
    ])->assertForbidden();
    apiRequest($this, 'PATCH', "/admin/users/{$userId}", $admin, ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
    apiRequest($this, 'DELETE', "/admin/users/{$userId}", $admin)->assertNoContent();
    apiRequest($this, 'GET', "/admin/users/{$userId}", $admin)->assertNotFound();
    apiRequest($this, 'DELETE', "/admin/users/{$student->id}", $admin)->assertStatus(409);

    $plus = apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => 50,
        'note' => 'Pest bonus',
    ])->assertCreated();
    $plus->assertJsonPath('balance', $points + 50)->assertJsonPath('data.type', 'adjustment');
    expect($plus->json('data.note'))->toContain('Admin adjustment');
    apiRequest($this, 'GET', '/wallet', $student)->assertJsonPath('data.balance', $points + 50);
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => -($points + 51),
        'note' => 'Too much',
    ])->assertUnprocessable();
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => -20,
        'note' => 'Correction',
    ])->assertCreated()->assertJsonPath('balance', $points + 30);
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, [
        'points' => 0,
        'note' => 'Zero',
    ])->assertUnprocessable();
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $admin, ['points' => 5])
        ->assertUnprocessable();
    apiRequest($this, 'POST', "/admin/users/{$student->id}/points-adjustments", $staff, [
        'points' => 5,
        'note' => 'Not allowed',
    ])->assertForbidden();
    $ledger = apiRequest($this, 'GET', "/admin/users/{$student->id}/transactions", $admin)->assertOk();
    expect(collect($ledger->json('data'))->where('type', 'adjustment'))->toHaveCount(2);

    $categoryName = 'Pest Test Category';
    $category = apiRequest($this, 'POST', '/admin/waste-categories', $admin, [
        'name' => $categoryName,
        'points_per_kg' => 3.5,
    ])->assertCreated()->assertJsonPath('data.is_recyclable', true);
    $categoryId = $category->json('data.id');
    $category->assertJsonPath('data.points_per_kg', 3.5);
    apiRequest($this, 'POST', '/admin/waste-categories', $admin, [
        'name' => $categoryName,
        'points_per_kg' => 1,
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/waste-categories', $admin, [
        'name' => 'Pest Invalid Category',
        'points_per_kg' => -1,
    ])->assertUnprocessable();
    apiRequest($this, 'PATCH', "/admin/waste-categories/{$categoryId}", $admin, ['points_per_kg' => 4])
        ->assertOk()
        ->assertJsonPath('data.points_per_kg', 4);
    apiRequest($this, 'GET', '/waste-categories', $student)->assertJsonFragment(['name' => $categoryName]);
    apiRequest($this, 'PATCH', "/admin/waste-categories/{$categoryId}", $admin, ['is_active' => false])
        ->assertOk();
    expect(apiRequest($this, 'GET', '/waste-categories', $student)->json('data'))
        ->not->toContain(['name' => $categoryName]);
    apiRequest($this, 'GET', '/admin/waste-categories', $admin)
        ->assertJsonFragment(['id' => $categoryId, 'is_active' => false]);
    apiRequest($this, 'DELETE', "/admin/waste-categories/{$categoryId}", $admin)->assertNoContent();
    apiRequest($this, 'DELETE', "/admin/waste-categories/{$plastic->id}", $admin)->assertStatus(409);

    $rewardName = 'Pest Test Reward';
    $reward = apiRequest($this, 'POST', '/admin/rewards', $admin, [
        'name' => $rewardName,
        'description' => 'Pest test',
        'points_cost' => 5,
        'stock_qty' => 2,
    ])->assertCreated()->assertJsonPath('data.stock_qty', 2);
    $rewardId = $reward->json('data.id');
    apiRequest($this, 'POST', '/admin/rewards', $admin, [
        'name' => 'Pest Negative Stock',
        'points_cost' => 5,
        'stock_qty' => -1,
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/rewards', $admin, [
        'name' => 'Pest Zero Cost',
        'points_cost' => 0,
        'stock_qty' => 1,
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/admin/rewards', $admin, [
        'name' => 'Pest Invalid Image',
        'points_cost' => 5,
        'stock_qty' => 1,
        'image_url' => 'nope',
    ])->assertUnprocessable();
    apiRequest($this, 'GET', '/rewards', $student)->assertJsonFragment(['name' => $rewardName]);
    apiRequest($this, 'POST', "/rewards/{$rewardId}/redeem", $student)
        ->assertCreated()
        ->assertJsonPath('balance_after', $points + 25);
    apiRequest($this, 'GET', "/admin/rewards/{$rewardId}", $admin)
        ->assertJsonPath('data.redemptions_count', 1)
        ->assertJsonPath('data.stock_qty', 1);
    apiRequest($this, 'DELETE', "/admin/rewards/{$rewardId}", $admin)->assertStatus(409);
    apiRequest($this, 'PATCH', "/admin/rewards/{$rewardId}", $admin, ['is_active' => false])->assertOk();
    apiRequest($this, 'GET', '/rewards', $student)->assertJsonMissing(['name' => $rewardName]);
    apiRequest($this, 'POST', "/rewards/{$rewardId}/redeem", $student)->assertNotFound();
    apiRequest($this, 'PATCH', "/admin/rewards/{$rewardId}", $admin, [
        'stock_qty' => 10,
        'points_cost' => 6,
    ])->assertOk()->assertJsonPath('data.stock_qty', 10)->assertJsonPath('data.points_cost', 6);

    $report = apiRequest($this, 'GET', "/admin/reports/impact?month={$month}", $admin)->assertOk();
    $groupRow = collect($report->json('data.groups'))->firstWhere('group_id', $groupId);
    expect($groupRow)->not->toBeNull()
        ->and($groupRow['students'])->toBe(1)
        ->and($groupRow['total_kg'])->toBe(2)
        ->and($groupRow['total_points'])->toBe($points)
        ->and($groupRow['submissions'])->toBe(1)
        ->and($groupRow['kg_per_student'])->toBe(2);
    expect(collect($report->json('data.by_category'))->contains(
        fn ($item) => $item['category'] === 'Plastic' && $item['total_kg'] >= 2,
    ))->toBeTrue();
    expect($report->json('data.totals.kg'))->toBeGreaterThanOrEqual(2);
    expect($report->json('data.totals.submissions'))->toBeGreaterThanOrEqual(1);
    apiRequest($this, 'GET', '/admin/reports/impact?month=nope', $admin)->assertUnprocessable();
    apiRequest($this, 'GET', '/admin/reports/impact', $staff)->assertForbidden();
    apiRequest($this, 'POST', '/admin/reports/impact/generate', $admin, ['month' => $month])
        ->assertOk()
        ->assertJsonFragment(['total_kg_diverted' => 2]);
    apiRequest($this, 'POST', '/admin/reports/impact/generate', $admin, ['month' => $month])->assertOk();
    $history = apiRequest($this, 'GET', "/admin/reports/impact/history?group_id={$groupId}", $admin)
        ->assertOk();
    $history->assertJsonPath('total', 1);
    $history->assertJsonPath('data.0.month', $month);
    $history->assertJsonPath('data.0.total_points', $points);

    $csvGroups = $this->get("/api/v1/admin/reports/impact/export?month={$month}&type=groups", [
        'Accept' => 'text/csv',
        'Authorization' => 'Bearer '.$admin->createToken('csv-test')->plainTextToken,
    ])->assertOk();
    expect($csvGroups->headers->get('content-type'))->toContain('text/csv');
    expect(str_contains($csvGroups->streamedContent(), 'kg_diverted'))->toBeTrue();
    expect(str_contains($csvGroups->streamedContent(), 'Pest Impact Group Updated'))->toBeTrue();
    $csvCategories = $this->get("/api/v1/admin/reports/impact/export?month={$month}&type=categories", [
        'Accept' => 'text/csv',
        'Authorization' => 'Bearer '.$admin->createToken('csv-test')->plainTextToken,
    ])->assertOk();
    expect(str_contains($csvCategories->streamedContent(), 'Plastic'))->toBeTrue();

    apiRequest($this, 'PATCH', "/admin/users/{$student->id}", $admin, ['group_id' => null])->assertOk();
    apiRequest($this, 'DELETE', "/admin/groups/{$groupId}", $admin)->assertNoContent();
    apiRequest($this, 'GET', "/admin/reports/impact/history?group_id={$groupId}", $admin)
        ->assertJsonPath('total', 0);
});
