<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassGroup extends Model
{
    protected $table = 'class_groups';

    protected $fillable = ['name', 'type'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'group_id');
    }

    public function impactReports(): HasMany
    {
        return $this->hasMany(ImpactReport::class, 'group_id');
    }
}
