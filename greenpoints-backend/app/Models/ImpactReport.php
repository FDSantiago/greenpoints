<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpactReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'report_month',
        'total_kg_diverted',
        'total_points',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'report_month' => 'date',
            'total_kg_diverted' => 'decimal:3',
            'total_points' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'group_id');
    }
}
