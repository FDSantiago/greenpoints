<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PointTransaction */
class PointTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,           // earn | redeem | adjustment | refund
            'points' => $this->points,       // signed: + earned/refunded, - spent
            'note' => $this->note,
            'created_at' => $this->created_at,
            'waste' => $this->whenLoaded('wasteLog', fn () => $this->wasteLog ? [
                'submission_id' => $this->wasteLog->submission_id,
                'category' => $this->wasteLog->category?->name,
                'weight_kg' => (float) $this->wasteLog->weight_kg,
            ] : null),
            'redemption' => $this->whenLoaded('redemption', fn () => $this->redemption ? [
                'id' => $this->redemption->id,
                'reward' => $this->redemption->reward?->name,
            ] : null),
        ];
    }
}
