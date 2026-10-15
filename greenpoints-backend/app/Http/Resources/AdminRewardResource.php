<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Reward */
class AdminRewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'points_cost' => (int) $this->points_cost,
            'stock_qty' => (int) $this->stock_qty,
            'is_active' => (bool) $this->is_active,
            'redemptions_count' => $this->when(
                array_key_exists('redemptions_count', $this->resource->getAttributes()),
                fn () => (int) $this->redemptions_count
            ),
        ];
    }
}
