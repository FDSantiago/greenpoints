<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ClassGroup */
class ClassGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'members_count' => $this->when(
                array_key_exists('users_count', $this->resource->getAttributes()),
                fn () => (int) $this->users_count
            ),
        ];
    }
}
