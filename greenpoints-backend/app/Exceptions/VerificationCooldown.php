<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A new verification code was asked for before the resend cooldown ended. */
class VerificationCooldown extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Please wait before requesting a new code.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'RESEND_COOLDOWN',
            'message' => "Please wait {$this->retryAfter} seconds before requesting a new code.",
            'details' => ['retry_after' => $this->retryAfter],
        ]], 429, ['Retry-After' => $this->retryAfter]);
    }
}
