<?php

use App\Models\User;
use App\Models\WasteCategory;

beforeEach(fn () => $this->seed());

it('covers the student submission and staff verification flow', function () {
    $student = User::where('email', 'student1@greenpoints.test')->firstOrFail();
    $otherStudent = User::where('email', 'student2@greenpoints.test')->firstOrFail();
    $staff = User::where('email', 'staff@greenpoints.test')->firstOrFail();
    $categories = apiRequest($this, 'GET', '/waste-categories', $student)
        ->assertOk()
        ->json('data');
    expect($categories)->toHaveCount(5);
    $plastic = WasteCategory::where('name', 'Plastic')->firstOrFail();
    $paper = WasteCategory::where('name', 'Paper')->firstOrFail();

    apiRequest($this, 'POST', '/waste-submissions', $student, [
        'items' => [['category_id' => $plastic->id, 'weight_kg' => 0]],
    ])->assertUnprocessable();
    apiRequest($this, 'POST', '/waste-submissions', $student, [
        'items' => [['category_id' => 99999, 'weight_kg' => 1]],
    ])->assertUnprocessable();

    $before = apiRequest($this, 'GET', '/me', $student)->assertOk()->json('data.points_balance');
    $created = apiRequest($this, 'POST', '/waste-submissions', $student, [
        'notes' => 'Pest test',
        'items' => [
            ['category_id' => $plastic->id, 'weight_kg' => 1.2, 'waste_condition' => 'clean'],
            ['category_id' => $paper->id, 'weight_kg' => 0.5, 'waste_condition' => 'contaminated'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.points_awarded', null);

    expect($created->json('data.items'))->toHaveCount(2);
    $submissionId = $created->json('data.id');
    apiRequest($this, 'GET', '/waste-submissions?status=pending', $student)
        ->assertOk()
        ->assertJsonFragment(['id' => $submissionId]);
    apiRequest($this, 'GET', "/waste-submissions/{$submissionId}", $student)
        ->assertOk()
        ->assertJsonPath('data.id', $submissionId);
    apiRequest($this, 'GET', "/waste-submissions/{$submissionId}", $otherStudent)->assertNotFound();
    apiRequest($this, 'GET', '/staff/submissions', $student)->assertForbidden();
    apiRequest($this, 'POST', "/staff/submissions/{$submissionId}/verify", $student)->assertForbidden();

    $queue = apiRequest($this, 'GET', '/staff/submissions?status=pending', $staff)->assertOk();
    expect(collect($queue->json('data'))->firstWhere('id', $submissionId)['user']['name'])->not->toBeEmpty();

    $verified = apiRequest($this, 'POST', "/staff/submissions/{$submissionId}/verify", $staff)
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');
    $points = $verified->json('data.points_awarded');
    expect($points)->toBeInt()->toBeGreaterThan(0);
    apiRequest($this, 'POST', "/staff/submissions/{$submissionId}/verify", $staff)->assertStatus(409);
    expect(apiRequest($this, 'GET', '/me', $student)->assertOk()->json('data.points_balance') - $before)
        ->toBe($points);
    apiRequest($this, 'GET', '/waste-submissions/summary', $student)
        ->assertOk()
        ->assertJsonPath('data.total_points', $points);

    $second = apiRequest($this, 'POST', '/waste-submissions', $student, [
        'items' => [['category_id' => $paper->id, 'weight_kg' => 0.3]],
    ])->assertCreated();
    $secondId = $second->json('data.id');
    apiRequest($this, 'POST', "/staff/submissions/{$secondId}/reject", $staff, [
        'reason' => 'Not sorted properly',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(apiRequest($this, 'POST', "/staff/submissions/{$secondId}/reject", $staff)->status())
        ->toBeIn([409, 422]);
    expect(apiRequest($this, 'GET', '/me', $student)->assertOk()->json('data.points_balance'))
        ->toBe($before + $points);

    $staffSubmission = apiRequest($this, 'POST', '/waste-submissions', $staff, [
        'items' => [['category_id' => $plastic->id, 'weight_kg' => 2]],
    ])->assertCreated();
    apiRequest($this, 'POST', "/staff/submissions/{$staffSubmission->json('data.id')}/verify", $staff)
        ->assertForbidden();
});
