<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImpactReport;
use App\Services\ImpactReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImpactReportController extends Controller
{
    public function __construct(private ImpactReportService $service)
    {
    }

    /** GET /admin/reports/impact?month=2026-10 : live numbers (month defaults to the current one). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->live($this->month($request))]);
    }

    /** POST /admin/reports/impact/generate { "month": "2026-10" } : store/refresh the monthly snapshot rows. */
    public function generate(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $rows = $this->service->generate($month)->each(fn (ImpactReport $r) => $r->load('group'));

        return response()->json(['data' => [
            'month' => $month->format('Y-m'),
            'reports' => $rows->map(fn (ImpactReport $r) => $this->present($r))->values(),
        ]]);
    }

    /** GET /admin/reports/impact/history?group_id=&per_page= : stored snapshots, newest month first. */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ImpactReport::with('group')->orderByDesc('report_month')->orderBy('group_id');
        if (! empty($filters['group_id'])) {
            $query->where('group_id', $filters['group_id']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 24));
        $page->setCollection($page->getCollection()->map(fn (ImpactReport $r) => $this->present($r)));

        return response()->json($page);
    }

    /** GET /admin/reports/impact/export?month=2026-10&type=groups|categories : CSV download. */
    public function export(Request $request): StreamedResponse
    {
        $type = $request->validate(['type' => ['nullable', Rule::in(['groups', 'categories'])]])['type'] ?? 'groups';
        $month = $this->month($request);
        $live = $this->service->live($month);
        $label = $month->format('Y-m');

        if ($type === 'groups') {
            $header = ['month', 'group', 'students', 'kg_diverted', 'points_awarded', 'submissions', 'kg_per_student'];
            $rows = collect($live['groups'])->map(fn ($g) => [
                $label, $g['name'], $g['students'], $g['total_kg'], $g['total_points'], $g['submissions'], $g['kg_per_student'],
            ]);
        } else {
            $header = ['month', 'category', 'recyclable', 'kg_diverted', 'points_awarded'];
            $rows = collect($live['by_category'])->map(fn ($c) => [
                $label, $c['category'], $c['is_recyclable'] ? 'yes' : 'no', $c['total_kg'], $c['total_points'],
            ]);
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            fputcsv($out, $header, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'csvSafe'], $row), ',', '"', '\\');
            }
            fclose($out);
        }, "impact-{$type}-{$label}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function month(Request $request): Carbon
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $this->service->parseMonth($data['month'] ?? null);
    }

    private function present(ImpactReport $r): array
    {
        return [
            'id' => $r->id,
            'month' => $r->report_month->format('Y-m'),
            'group' => $r->group ? ['id' => $r->group->id, 'name' => $r->group->name] : null,
            'total_kg_diverted' => (float) $r->total_kg_diverted,
            'total_points' => (int) $r->total_points,
            'generated_at' => $r->generated_at,
        ];
    }

    /** Stops spreadsheet formula injection (cells starting with = + - @). */
    private function csvSafe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
