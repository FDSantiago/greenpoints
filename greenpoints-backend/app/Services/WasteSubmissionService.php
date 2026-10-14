<?php

namespace App\Services;

use App\Http\Resources\WasteSubmissionResource;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WasteLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A "submission" is one drop-off = several waste_logs rows sharing a submission_id.
 * Submissions are verified or rejected as a whole.
 */
class WasteSubmissionService
{
    public function __construct(private PointsCalculator $calculator)
    {
    }

    /** All rows of one submission, with relations needed by the resource. */
    public function logsFor(string $submissionId): Collection
    {
        return WasteLog::with(['category', 'user.group'])
            ->where('submission_id', $submissionId)
            ->orderBy('id')
            ->get();
    }

    public function find(string $submissionId, User $viewer): Collection
    {
        $logs = $this->logsFor($submissionId);

        // 404 (not 403) for other people's submissions so ids can't be probed.
        if ($logs->isEmpty() || (! $viewer->isStaff() && $logs->first()->user_id !== $viewer->id)) {
            abort(404);
        }

        return $logs;
    }

    public function create(User $user, array $items, ?string $notes): Collection
    {
        $submissionId = (string) Str::uuid();

        DB::transaction(function () use ($user, $items, $notes, $submissionId) {
            foreach ($items as $item) {
                $condition = $item['waste_condition'] ?? 'clean';

                WasteLog::create([
                    'submission_id' => $submissionId,
                    'user_id' => $user->id,
                    'category_id' => $item['category_id'],
                    'weight_kg' => $item['weight_kg'],
                    'waste_condition' => $condition,
                    'contamination_factor' => $this->calculator->factorFor($condition),
                    'notes' => $notes,
                    'dropped_at' => now(),
                ]);
            }
        });

        return $this->logsFor($submissionId);
    }

    /**
     * Paginate submissions (not rows). $query is a filtered WasteLog query.
     * Returns a paginator whose items are already-resolved arrays.
     */
    public function paginate(Builder $query, int $perPage): LengthAwarePaginator
    {
        $page = $query
            ->select(['submission_id', 'user_id', DB::raw('MIN(dropped_at) as submitted_at')])
            ->groupBy('submission_id', 'user_id')
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        $ids = $page->getCollection()->pluck('submission_id')->all();

        $logs = WasteLog::with(['category', 'user.group'])
            ->whereIn('submission_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('submission_id');

        $page->setCollection(
            $page->getCollection()->map(
                fn ($row) => (new WasteSubmissionResource($logs[$row->submission_id]))->resolve()
            )
        );

        return $page;
    }

    /**
     * Staff verifies a whole submission. Optionally overrides weight/condition per item
     * (staff weigh the waste, so their numbers win). Awards points via the ledger.
     *
     * @param  array<int, array{id:int, weight_kg?:numeric, waste_condition?:string}>  $overrides
     */
    public function verify(string $submissionId, User $staff, array $overrides = []): Collection
    {
        return DB::transaction(function () use ($submissionId, $staff, $overrides) {
            $logs = $this->lockPending($submissionId, $staff);

            $byId = collect($overrides)->keyBy('id');
            $unknown = $byId->keys()->diff($logs->pluck('id'));
            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Item ids not part of this submission: '.$unknown->implode(', ')],
                ]);
            }

            foreach ($logs as $log) {
                $override = $byId->get($log->id, []);
                $weight = (float) ($override['weight_kg'] ?? $log->weight_kg);
                $condition = $override['waste_condition'] ?? $log->waste_condition;
                $factor = $this->calculator->factorFor($condition);
                $points = $this->calculator->points($log->category, $weight, $factor, $log->user_id);

                $log->forceFill([
                    'weight_kg' => $weight,
                    'waste_condition' => $condition,
                    'contamination_factor' => $factor,
                    'points_awarded' => $points,
                    'status' => WasteLog::STATUS_VERIFIED,
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ])->save();

                // The ledger forbids zero-point rows (CHECK points <> 0), so skip them.
                if ($points > 0) {
                    PointTransaction::create([
                        'user_id' => $log->user_id,
                        'waste_log_id' => $log->id,
                        'type' => PointTransaction::TYPE_EARN,
                        'points' => $points,
                        'note' => 'Waste drop-off verified',
                    ]);
                }
            }

            return $logs->load('user.group');
        });
    }

    public function reject(string $submissionId, User $staff, string $reason): Collection
    {
        return DB::transaction(function () use ($submissionId, $staff, $reason) {
            $logs = $this->lockPending($submissionId, $staff);

            foreach ($logs as $log) {
                $log->forceFill([
                    'status' => WasteLog::STATUS_REJECTED,
                    'reject_reason' => $reason,
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ])->save();
            }

            return $logs->load('user.group');
        });
    }

    /** Locks the submission's rows and checks it can still be processed by this staff member. */
    private function lockPending(string $submissionId, User $staff): Collection
    {
        $logs = WasteLog::with('category')
            ->where('submission_id', $submissionId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        abort_if($logs->isEmpty(), 404);
        abort_if(
            $logs->contains(fn (WasteLog $l) => $l->status !== WasteLog::STATUS_PENDING),
            409,
            'This submission was already processed.'
        );
        abort_if($logs->first()->user_id === $staff->id, 403, 'You cannot verify or reject your own submission.');

        return $logs;
    }
}
