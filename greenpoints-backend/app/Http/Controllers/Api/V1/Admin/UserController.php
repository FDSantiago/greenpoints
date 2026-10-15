<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PointTransactionResource;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const ROLES = [User::ROLE_STUDENT, User::ROLE_STAFF, User::ROLE_ADMIN];

    /** GET /admin/users?role=&group_id=&is_active=&q=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(self::ROLES)],
            'group_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->with('group')
            ->withSum('pointTransactions as points_balance', 'points')
            ->orderBy('name')
            ->orderBy('id');

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }
        if (! empty($filters['group_id'])) {
            $query->where('group_id', $filters['group_id']);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if (! empty($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $page->setCollection($page->getCollection()->map(fn ($u) => (new AdminUserResource($u))->resolve()));

        return response()->json($page);
    }

    /** POST /admin/users */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in(self::ROLES)],
            'group_id' => ['nullable', 'integer', 'exists:class_groups,id'],
        ]);

        $user = new User(Arr::only($data, ['name', 'email', 'password', 'group_id']));
        $user->forceFill(['role' => $data['role'], 'is_active' => true, 'email_verified_at' => now()])->save();

        return response()->json(['data' => (new AdminUserResource($user->load('group')))->resolve()], 201);
    }

    /** GET /admin/users/{id} */
    public function show(int $userId): JsonResponse
    {
        $user = User::with('group')->withSum('pointTransactions as points_balance', 'points')->findOrFail($userId);

        return response()->json(['data' => (new AdminUserResource($user))->resolve()]);
    }

    /**
     * PATCH /admin/users/{id}  (send only the fields to change)
     * Admins can't change their own role or deactivate themselves, so the system can't be left
     * without an admin. Deactivating a user (or changing their password) revokes their API tokens.
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', Password::min(8)],
            'role' => ['sometimes', 'required', Rule::in(self::ROLES)],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:class_groups,id'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($user->id === $request->user()->id) {
            if (isset($data['role']) && $data['role'] !== $user->role) {
                throw ValidationException::withMessages(['role' => ["You can't change your own role."]]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => ["You can't deactivate your own account."]]);
            }
        }

        $user->fill(Arr::only($data, ['name', 'email', 'password', 'group_id']));
        if (isset($data['role'])) {
            $user->forceFill(['role' => $data['role']]);
        }
        if (array_key_exists('is_active', $data)) {
            $user->forceFill(['is_active' => (bool) $data['is_active']]);
        }
        $user->save();

        if (array_key_exists('is_active', $data) && ! $data['is_active'] || isset($data['password'])) {
            $user->tokens()->delete();
        }

        $user = User::with('group')->withSum('pointTransactions as points_balance', 'points')->find($user->id);

        return response()->json(['data' => (new AdminUserResource($user))->resolve()]);
    }

    /** DELETE /admin/users/{id} : only for users with no activity; otherwise deactivate. */
    public function destroy(Request $request, int $userId): Response|JsonResponse
    {
        $user = User::findOrFail($userId);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => ["You can't delete your own account."]]);
        }

        $hasActivity = $user->wasteLogs()->exists()
            || $user->redemptions()->exists()
            || $user->pointTransactions()->exists();

        abort_if(
            $hasActivity,
            409,
            'This user has activity (waste logs, points or redemptions) and cannot be deleted. Deactivate the account instead (PATCH is_active=false).'
        );

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    /** GET /admin/users/{id}/transactions : a user's full points ledger. */
    public function transactions(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $page = PointTransaction::with(['wasteLog.category', 'redemption.reward'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($t) => (new PointTransactionResource($t))->resolve()));

        return response()->json($page);
    }

    /**
     * POST /admin/users/{id}/points-adjustments  { "points": 50, "note": "Event bonus" }
     * Adds an 'adjustment' ledger row (history is never edited). Negative adjustments can't take the
     * balance below zero. The admin's name is recorded in the note.
     */
    public function adjustPoints(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['required', 'string', 'max:150'],
        ]);
        $admin = $request->user();

        [$transaction, $balance] = DB::transaction(function () use ($userId, $data, $admin) {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            abort_if(! $user, 404);

            $balance = (int) PointTransaction::where('user_id', $user->id)->sum('points');
            if ($balance + $data['points'] < 0) {
                throw ValidationException::withMessages([
                    'points' => ["This would make the balance negative (current balance: {$balance})."],
                ]);
            }

            $transaction = PointTransaction::create([
                'user_id' => $user->id,
                'type' => PointTransaction::TYPE_ADJUSTMENT,
                'points' => $data['points'],
                'note' => Str::limit("Admin adjustment by {$admin->name} (#{$admin->id}): {$data['note']}", 255, ''),
            ]);

            return [$transaction, $balance + $data['points']];
        });

        return response()->json([
            'data' => (new PointTransactionResource($transaction))->resolve(),
            'balance' => $balance,
        ], 201);
    }
}
