<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'student_id' => $this->student_id,
            'course' => $this->course,
            'email_verified' => $this->email_verified_at !== null,
            'group' => $this->whenLoaded('group', fn () => $this->group ? [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'type' => $this->group->type,
            ] : null),
            // Present only when loaded with loadSum('pointTransactions as points_balance', 'points')
            'points_balance' => $this->when(
                array_key_exists('points_balance', $this->resource->getAttributes()),
                fn () => (int) $this->points_balance
            ),
            'created_at' => $this->created_at,
        ];
    }
}
