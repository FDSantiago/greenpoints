<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Redemption */
class RedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->redemption_code,
            'status' => $this->status,
            'points_spent' => $this->points_spent,
            'reward' => $this->whenLoaded('reward', fn () => [
                'id' => $this->reward->id,
                'name' => $this->reward->name,
                'image_url' => $this->reward->image_url,
            ]),
            'pickup' => [
                'location' => config('greenpoints.pickup.location'),
                'note' => config('greenpoints.pickup.note'),
            ],
            'redeemed_at' => $this->redeemed_at,
            'fulfilled_at' => $this->fulfilled_at,
        ];

        // Staff also see who the redemption belongs to.
        if ($request->user()?->isStaff() && $this->relationLoaded('user')) {
            $data['user'] = [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'group' => $this->user->group?->name,
            ];
        }

        return $data;
    }
}
