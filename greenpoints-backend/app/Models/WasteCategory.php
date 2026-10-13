<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteCategory extends Model
{
    protected $fillable = ['name', 'is_recyclable', 'points_per_kg', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_recyclable' => 'boolean',
            'is_active' => 'boolean',
            'points_per_kg' => 'decimal:2',
        ];
    }

    public function wasteLogs(): HasMany
    {
        return $this->hasMany(WasteLog::class, 'category_id');
    }
}
