<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/** The SMTP server refused or could not be reached. The cooldown is not started, so the user can retry. */
class VerificationMailFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Could not send the verification email: '.$previous->getMessage(), 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'MAIL_UNAVAILABLE',
            'message' => 'We could not send the verification email right now. Please try again shortly.',
        ]], 503);
    }
}
