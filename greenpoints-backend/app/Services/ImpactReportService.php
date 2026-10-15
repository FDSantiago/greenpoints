<?php

namespace App\Services;

use App\Models\ImpactReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Impact" = verified waste only (pending/rejected never count), attributed to the month the
 * waste was dropped off and to the student's CURRENT group.
 */
class ImpactReportService
{
    public function parseMonth(?string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', ($month ?? now()->format('Y-m')).'-01')->startOfMonth();
    }

    /** Fresh numbers computed straight from waste_logs. */
    public function live(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $groups = DB::table('class_groups as g')
            ->leftJoin('users as u', function ($j) {
                $j->on('u.group_id', '=', 'g.id')->where('u.role', '=', 'student');
            })
            ->leftJoin('waste_logs as w', function ($j) use ($start, $end) {
                $j->on('w.user_id', '=', 'u.id')
                    ->where('w.status', '=', 'verified')
                    ->whereBetween('w.dropped_at', [$start, $end]);
            })
            ->groupBy('g.id', 'g.name')
            ->orderBy('g.name')
            ->get([
                'g.id',
                'g.name',
                DB::raw('COUNT(DISTINCT u.id) as students'),
                DB::raw('COALESCE(SUM(w.weight_kg), 0) as total_kg'),
                DB::raw('COALESCE(SUM(w.points_awarded), 0) as total_points'),
                DB::raw('COUNT(DISTINCT w.submission_id) as submissions'),
            ])
            ->map(function ($row) {
                $students = (int) $row->students;
                $kg = round((float) $row->total_kg, 3);

                return [
                    'group_id' => (int) $row->id,
                    'name' => $row->name,
                    'students' => $students,
                    'total_kg' => $kg,
                    'total_points' => (int) $row->total_points,
                    'submissions' => (int) $row->submissions,
                    'kg_per_student' => $students > 0 ? round($kg / $students, 3) : 0.0,
                ];
            })
            ->values();

        $byCategory = DB::table('waste_logs as w')
            ->join('waste_categories as c', 'c.id', '=', 'w.category_id')
            ->where('w.status', 'verified')
            ->whereBetween('w.dropped_at', [$start, $end])
            ->groupBy('c.id', 'c.name', 'c.is_recyclable')
            ->orderBy('c.name')
            ->get([
                'c.id',
                'c.name',
                'c.is_recyclable',
                DB::raw('SUM(w.weight_kg) as total_kg'),
                DB::raw('SUM(w.points_awarded) as total_points'),
            ])
            ->map(fn ($row) => [
                'category_id' => (int) $row->id,
                'category' => $row->name,
                'is_recyclable' => (bool) $row->is_recyclable,
                'total_kg' => round((float) $row->total_kg, 3),
                'total_points' => (int) $row->total_points,
            ])
            ->values();

        $totals = DB::table('waste_logs')
            ->where('status', 'verified')
            ->whereBetween('dropped_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(weight_kg), 0) as kg, COALESCE(SUM(points_awarded), 0) as points, COUNT(DISTINCT submission_id) as submissions')
            ->first();

        $totalKg = round((float) $totals->kg, 3);

        return [
            'month' => $start->format('Y-m'),
            'totals' => [
                'kg' => $totalKg,
                'points' => (int) $totals->points,
                'submissions' => (int) $totals->submissions,
                // Waste from students who belong to no group isn't in any group row.
                'ungrouped_kg' => round($totalKg - (float) $groups->sum('total_kg'), 3),
            ],
            'groups' => $groups,
            'by_category' => $byCategory,
        ];
    }

    /** Stores (or refreshes) one impact_reports row per group for the month. */
    public function generate(Carbon $month): Collection
    {
        $live = $this->live($month);
        $reportMonth = $month->copy()->startOfMonth()->toDateString();

        return collect($live['groups'])->map(function (array $group) use ($reportMonth) {
            return ImpactReport::updateOrCreate(
                ['group_id' => $group['group_id'], 'report_month' => $reportMonth],
                [
                    'total_kg_diverted' => $group['total_kg'],
                    'total_points' => $group['total_points'],
                    'generated_at' => now(),
                ]
            );
        });
    }
}
