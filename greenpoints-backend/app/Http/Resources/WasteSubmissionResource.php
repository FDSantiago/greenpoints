<?php

namespace App\Http\Resources;

use App\Models\WasteLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps an Eloquent Collection of WasteLog rows that share one submission_id.
 */
class WasteSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $logs = $this->resource;
        $first = $logs->first();
        $verified = $first->status === WasteLog::STATUS_VERIFIED;

        $data = [
            'id' => $first->submission_id,
            'status' => $first->status,
            'submitted_at' => $first->dropped_at,
            'notes' => $first->notes,
            'total_weight_kg' => round((float) $logs->sum('weight_kg'), 3),
            'points_awarded' => $verified ? (int) $logs->sum('points_awarded') : null,
            'verified_at' => $first->verified_at,
            'reject_reason' => $first->reject_reason,
            'items' => $logs->map(fn (WasteLog $log) => [
                'id' => $log->id,
                'category' => [
                    'id' => $log->category->id,
                    'name' => $log->category->name,
                ],
                'weight_kg' => (float) $log->weight_kg,
                'condition' => $log->waste_condition,
                'contamination_factor' => (float) $log->contamination_factor,
                'points_awarded' => $log->points_awarded,
            ])->values(),
        ];

        // Staff also see who submitted it.
        if ($request->user()?->isStaff() && $first->relationLoaded('user')) {
            $data['user'] = [
                'id' => $first->user->id,
                'name' => $first->user->name,
                'group' => $first->user->group?->name,
            ];
        }

        return $data;
    }
}
