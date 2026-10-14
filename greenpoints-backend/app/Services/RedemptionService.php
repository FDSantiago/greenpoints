<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedemptionService
{
    /**
     * Redeem a reward atomically.
     *
     * Concurrency: the user's row is locked first, so two simultaneous redemptions by the same
     * user run one after the other and the second sees the already-reduced balance. The reward
     * row is locked too, so stock can't go negative.
     *
     * Idempotency: with a key, a retry returns the original redemption instead of charging twice.
     *
     * @return array{0: Redemption, 1: bool}  [redemption, replayed]
     */
    public function redeem(User $user, int $rewardId, ?string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $rewardId, $idempotencyKey) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null) {
                $existing = Redemption::with('reward')
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    if ((int) $existing->reward_id !== $rewardId) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This Idempotency-Key was already used for a different reward.'],
                        ]);
                    }

                    return [$existing, true];
                }
            }

            $reward = Reward::whereKey($rewardId)->lockForUpdate()->first();
            abort_if(! $reward || ! $reward->is_active, 404, 'Reward not found.');
            abort_if($reward->stock_qty < 1, 409, 'This reward is out of stock.');

            $cost = (int) $reward->points_cost;
            $balance = (int) PointTransaction::where('user_id', $user->id)->sum('points');

            if ($balance < $cost) {
                throw ValidationException::withMessages([
                    'points' => ["Not enough points. You have {$balance} and this reward costs {$cost}."],
                ]);
            }

            $redemption = Redemption::create([
                'user_id' => $user->id,
                'reward_id' => $reward->id,
                'points_spent' => $cost,
                'status' => Redemption::STATUS_PENDING,
                'redemption_code' => $this->newCode(),
                'idempotency_key' => $idempotencyKey,
                'redeemed_at' => now(),
            ]);

            // The ledger forbids zero-point rows (CHECK points <> 0).
            if ($cost > 0) {
                PointTransaction::create([
                    'user_id' => $user->id,
                    'redemption_id' => $redemption->id,
                    'type' => PointTransaction::TYPE_REDEEM,
                    'points' => -$cost,
                    'note' => 'Redeemed: '.$reward->name,
                ]);
            }

            $reward->decrement('stock_qty');

            return [$redemption->load('reward'), false];
        });
    }

    /** Owner may cancel while pending; staff may cancel pending or ready. Refunds the points. */
    public function cancel(int $redemptionId, User $actor): Redemption
    {
        return DB::transaction(function () use ($redemptionId, $actor) {
            $redemption = Redemption::with(['reward', 'user.group'])->lockForUpdate()->find($redemptionId);

            // 404 for strangers so ids can't be probed.
            abort_if(! $redemption || (! $actor->isStaff() && $redemption->user_id !== $actor->id), 404);

            $cancellable = $actor->isStaff()
                ? [Redemption::STATUS_PENDING, Redemption::STATUS_READY]
                : [Redemption::STATUS_PENDING];

            abort_unless(
                in_array($redemption->status, $cancellable, true),
                409,
                'This redemption can no longer be cancelled.'
            );

            $redemption->forceFill(['status' => Redemption::STATUS_CANCELLED])->save();

            if ($redemption->points_spent > 0) {
                PointTransaction::create([
                    'user_id' => $redemption->user_id,
                    'redemption_id' => $redemption->id,
                    'type' => PointTransaction::TYPE_REFUND,
                    'points' => $redemption->points_spent,
                    'note' => 'Refund: redemption cancelled',
                ]);
            }

            Reward::whereKey($redemption->reward_id)->increment('stock_qty');

            return $redemption;
        });
    }

    /** Staff: pending -> ready (student is told to come and collect). */
    public function markReady(int $redemptionId, User $staff): Redemption
    {
        return $this->transition($redemptionId, $staff, [Redemption::STATUS_PENDING], Redemption::STATUS_READY);
    }

    /** Staff: pending|ready -> claimed (handed over at the desk). */
    public function claim(int $redemptionId, User $staff): Redemption
    {
        return $this->transition(
            $redemptionId,
            $staff,
            [Redemption::STATUS_PENDING, Redemption::STATUS_READY],
            Redemption::STATUS_CLAIMED
        );
    }

    private function transition(int $redemptionId, User $staff, array $from, string $to): Redemption
    {
        return DB::transaction(function () use ($redemptionId, $staff, $from, $to) {
            $redemption = Redemption::with(['reward', 'user.group'])->lockForUpdate()->find($redemptionId);

            abort_if(! $redemption, 404);
            abort_if($redemption->user_id === $staff->id, 403, 'You cannot process your own redemption.');
            abort_unless(
                in_array($redemption->status, $from, true),
                409,
                "This redemption is {$redemption->status}; it must be ".implode(' or ', $from).'.'
            );

            $attributes = ['status' => $to];
            if ($to === Redemption::STATUS_CLAIMED) {
                $attributes['fulfilled_by'] = $staff->id;
                $attributes['fulfilled_at'] = now();
            }

            $redemption->forceFill($attributes)->save();

            return $redemption;
        });
    }

    /** Human-friendly code without look-alike characters (no 0/O/1/I). */
    private function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = (int) config('greenpoints.redemption_code_length', 8);

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Redemption::where('redemption_code', $code)->exists());

        return $code;
    }
}
