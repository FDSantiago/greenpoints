<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardService
{
    /** @return array{0: ?Carbon, 1: Carbon} [from, to] */
    public function bounds(string $period): array
    {
        return match ($period) {
            'weekly' => [now()->startOfWeek(), now()],
            'monthly' => [now()->startOfMonth(), now()],
            default => [null, now()], // 'all'
        };
    }

    /**
     * Full ranking (everyone, best first). Ranking counts EARNED points only (type 'earn'),
     * so spending points on rewards never lowers anyone's rank.
     *
     * scope  : individual | group
     * metric : total | per_member (group scope only: points / number of students in the group)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ranking(string $scope, string $period, string $metric = 'total'): Collection
    {
        [$from] = $this->bounds($period);

        $earnJoin = function ($join, string $userColumn) use ($from) {
            $join->on('pt.user_id', '=', $userColumn)->where('pt.type', '=', 'earn');
            if ($from) {
                $join->where('pt.created_at', '>=', $from);
            }
        };

        if ($scope === 'individual') {
            $entries = DB::table('users')
                ->leftJoin('point_transactions as pt', fn ($j) => $earnJoin($j, 'users.id'))
                ->where('users.role', 'student')
                ->groupBy('users.id', 'users.name', 'users.group_id')
                ->get([
                    'users.id',
                    'users.name',
                    DB::raw('COALESCE(SUM(pt.points), 0) as points'),
                ])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'points' => (int) $row->points,
                    'score' => (int) $row->points,
                ]);
        } else {
            $entries = DB::table('class_groups')
                ->leftJoin('users as u', function ($j) {
                    $j->on('u.group_id', '=', 'class_groups.id')->where('u.role', '=', 'student');
                })
                ->leftJoin('point_transactions as pt', fn ($j) => $earnJoin($j, 'u.id'))
                ->groupBy('class_groups.id', 'class_groups.name')
                ->get([
                    'class_groups.id',
                    'class_groups.name',
                    DB::raw('COUNT(DISTINCT u.id) as members'),
                    DB::raw('COALESCE(SUM(pt.points), 0) as points'),
                ])
                ->map(function ($row) use ($metric) {
                    $points = (int) $row->points;
                    $members = (int) $row->members;
                    $perMember = $members > 0 ? round($points / $members, 2) : 0.0;

                    return [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'points' => $points,
                        'members' => $members,
                        'per_member' => $perMember,
                        'score' => $metric === 'per_member' ? $perMember : $points,
                    ];
                });
        }

        $sorted = $entries
            ->sort(fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']))
            ->values();

        // Competition ranking: ties share a rank (1, 2, 2, 4).
        $rank = 0;
        $previous = null;

        return $sorted->map(function ($entry, $index) use (&$rank, &$previous) {
            if ($previous === null || $entry['score'] !== $previous) {
                $rank = $index + 1;
                $previous = $entry['score'];
            }

            return ['rank' => $rank] + $entry;
        });
    }
}
