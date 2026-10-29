<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email_verified_at === null) {
            return response()->json(['error' => [
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Please verify your email address first.',
            ]], 403);
        }

        return $next($request);
    }
}
