<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StudentRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, StudentRegistrationService $registration): JsonResponse
    {
        // Self-registration always creates a student: `role` is not mass-assignable.
        // With the School Registry enabled, the student is verified there first (System 2).
        // refresh() loads the DB defaults (role = student, is_active = true) into the model.
        $user = config('services.school_registry.enabled')
            ? $registration->register($request->validated())->refresh()
            : User::create($request->validated())->refresh();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => new UserResource($user->load('group')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Checked after the password so disabled accounts can't be discovered without it.
        abort_if($user->is_active !== null && ! $user->is_active, 403, 'This account has been deactivated. Please contact an administrator.');

        $deviceName = $request->validated('device_name') ?? 'api';

        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user->load('group')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user()
            ->load('group')
            ->loadSum('pointTransactions as points_balance', 'points');

        return new UserResource($user);
    }
}
