<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row. Balance = SUM(points) per user.
 * Never update or delete rows; correct mistakes with an 'adjustment' or 'refund' row.
 */
class PointTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REFUND = 'refund';

    protected $fillable = [
        'user_id',
        'waste_log_id',
        'redemption_id',
        'type',
        'points',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasteLog(): BelongsTo
    {
        return $this->belongsTo(WasteLog::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }
}
