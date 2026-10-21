<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Redemption extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_CANCELLED = 'cancelled';

    // Created only by the redemption service, so nothing here is request-fillable.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reward_id' => 'integer',
            'fulfilled_by' => 'integer',
            'points_spent' => 'integer',
            'redeemed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function pointTransaction(): HasOne
    {
        return $this->hasOne(PointTransaction::class);
    }
}
