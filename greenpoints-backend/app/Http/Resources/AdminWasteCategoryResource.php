<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WasteCategory */
class AdminWasteCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_recyclable' => (bool) $this->is_recyclable,
            'points_per_kg' => (float) $this->points_per_kg,
            'is_active' => (bool) $this->is_active,
            'logs_count' => $this->when(
                array_key_exists('waste_logs_count', $this->resource->getAttributes()),
                fn () => (int) $this->waste_logs_count
            ),
        ];
    }
}
