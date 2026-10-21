<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WasteLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'submission_id',
        'user_id',
        'category_id',
        'weight_kg',
        'waste_condition',
        'contamination_factor',
        'notes',
        'dropped_at',
    ];
    // status, points_awarded, verified_by, verified_at, reject_reason are server-controlled:
    // they are never mass-assigned from request data.

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'category_id' => 'integer',
            'verified_by' => 'integer',
            'points_awarded' => 'integer',
            'weight_kg' => 'decimal:3',
            'contamination_factor' => 'decimal:2',
            'dropped_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WasteCategory::class, 'category_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function pointTransaction(): HasOne
    {
        return $this->hasOne(PointTransaction::class);
    }
}
