<?php

namespace App\Services;

use App\Exceptions\VerificationCooldown;
use App\Exceptions\VerificationMailFailed;
use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 6-digit email verification codes, one pending code per user (table email_verifications).
 *
 * - The resend cooldown is claimed with one conditional UPDATE, so parallel requests can't
 *   send more than one email per cooldown window.
 * - Wrong guesses are counted the same way; after max_attempts the code is burned and the
 *   user must request a new one (which is again limited by the cooldown).
 * - Only a hash of the code is stored.
 */
class EmailVerificationService
{
    /** Create and email a new code. Throws VerificationCooldown or VerificationMailFailed. */
    public function send(User $user): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }

        $now = now();
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::table('email_verifications')->insertOrIgnore([
            'user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $claimed = DB::table('email_verifications')
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere('sent_at', '<=', $now->copy()->subSeconds($this->cooldown())))
            ->update([
                'code_hash' => Hash::make($code),
                'expires_at' => $now->copy()->addMinutes($this->ttl()),
                'sent_at' => $now,
                'attempts' => 0,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            throw new VerificationCooldown(max(1, $this->resendAvailableIn($user)));
        }

        try {
            Mail::to($user->email)->send(new EmailVerificationCode($user->name, $code, $this->ttl()));
        } catch (Throwable $e) {
            // Nothing was delivered: drop the code and the cooldown so an immediate retry is allowed.
            DB::table('email_verifications')->where('user_id', $user->id)
                ->update(['code_hash' => null, 'expires_at' => null, 'sent_at' => null]);

            throw new VerificationMailFailed($e);
        }
    }

    /** Check a code and mark the email as verified. Throws a 422 on `code` when it is wrong or unusable. */
    public function verify(User $user, string $code): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }

        // Count the attempt before checking it, so parallel guesses can't exceed max_attempts.
        $counted = DB::table('email_verifications')
            ->where('user_id', $user->id)
            ->whereNotNull('code_hash')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', $this->maxAttempts())
            ->increment('attempts');

        $row = DB::table('email_verifications')->where('user_id', $user->id)->first();

        if ($counted === 0 || ! $row) {
            throw ValidationException::withMessages([
                'code' => ['This code has expired or was entered wrong too many times. Please request a new code.'],
            ]);
        }

        if (! Hash::check($code, $row->code_hash)) {
            $left = $this->maxAttempts() - $row->attempts;
            if ($left <= 0) {
                DB::table('email_verifications')->where('user_id', $user->id)->update(['code_hash' => null]);
            }

            throw ValidationException::withMessages([
                'code' => [$left > 0
                    ? "That code is incorrect. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                    : 'That code is incorrect. Please request a new code.'],
            ]);
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('email_verifications')->where('user_id', $user->id)->delete();
        });
    }

    /** What the verification screen needs to show. */
    public function status(User $user): array
    {
        $row = DB::table('email_verifications')->where('user_id', $user->id)->first();

        return [
            'email' => $user->email,
            'verified' => $user->email_verified_at !== null,
            'resend_available_in' => $user->email_verified_at === null ? $this->resendAvailableIn($user) : 0,
            'code_expires_at' => $row?->code_hash && $row->expires_at
                ? Carbon::parse($row->expires_at)->toIso8601String()
                : null,
        ];
    }

    /** Seconds until a new code may be requested (0 = now). */
    public function resendAvailableIn(User $user): int
    {
        $sentAt = DB::table('email_verifications')->where('user_id', $user->id)->value('sent_at');
        if (! $sentAt) {
            return 0;
        }

        $availableAt = Carbon::parse($sentAt)->addSeconds($this->cooldown());

        return max(0, (int) ceil(now()->diffInSeconds($availableAt, false)));
    }

    private function cooldown(): int
    {
        return config('greenpoints.email_verification.resend_cooldown_seconds');
    }

    private function ttl(): int
    {
        return config('greenpoints.email_verification.code_ttl_minutes');
    }

    private function maxAttempts(): int
    {
        return config('greenpoints.email_verification.max_attempts');
    }
}
