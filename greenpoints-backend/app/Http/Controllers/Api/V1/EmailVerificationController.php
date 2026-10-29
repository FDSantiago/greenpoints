<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->verification->status($request->user())]);
    }

    public function resend(Request $request): JsonResponse
    {
        $this->verification->send($request->user());

        return response()->json([
            'message' => 'A new code has been sent.',
            'data' => $this->verification->status($request->user()),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.regex' => 'The code is the 6 digits from the email.',
        ]);

        $this->verification->verify($request->user(), $data['code']);

        return response()->json([
            'message' => 'Email verified.',
            'user' => new UserResource($request->user()->load('group')),
        ]);
    }
}
