<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WasteCategory */
class WasteCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_recyclable' => $this->is_recyclable,
            'points_per_kg' => (float) $this->points_per_kg,
        ];
    }
}
