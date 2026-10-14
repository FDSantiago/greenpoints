<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waste\StoreWasteSubmissionRequest;
use App\Http\Resources\WasteSubmissionResource;
use App\Models\WasteLog;
use App\Services\WasteSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WasteSubmissionController extends Controller
{
    public function __construct(private WasteSubmissionService $service)
    {
    }

    /** GET /waste-submissions : the current user's drop-off history (one entry per submission). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
            'category_id' => ['nullable', 'integer', 'exists:waste_categories,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = WasteLog::query()->where('user_id', $request->user()->id);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('dropped_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('dropped_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return response()->json($this->service->paginate($query, (int) ($filters['per_page'] ?? 15)));
    }

    /** POST /waste-submissions : create a pending drop-off with one or more items. */
    public function store(StoreWasteSubmissionRequest $request): JsonResponse
    {
        $logs = $this->service->create(
            $request->user(),
            $request->validated('items'),
            $request->validated('notes'),
        );

        return response()->json(['data' => (new WasteSubmissionResource($logs))->resolve()], 201);
    }

    /** GET /waste-submissions/{id} : owner or staff only. */
    public function show(Request $request, string $submissionId): JsonResponse
    {
        $logs = $this->service->find($submissionId, $request->user());

        return response()->json(['data' => (new WasteSubmissionResource($logs))->resolve()]);
    }

    /** GET /waste-submissions/summary?month=YYYY-MM : the "Monthly summary" card. */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $userId = $request->user()->id;

        $byCategory = WasteLog::query()
            ->join('waste_categories as c', 'c.id', '=', 'waste_logs.category_id')
            ->where('waste_logs.user_id', $userId)
            ->where('waste_logs.status', WasteLog::STATUS_VERIFIED)
            ->whereBetween('waste_logs.dropped_at', [$start, $end])
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.name')
            ->get([
                'c.id as category_id',
                'c.name as category',
                DB::raw('SUM(waste_logs.weight_kg) as total_kg'),
                DB::raw('SUM(waste_logs.points_awarded) as total_points'),
            ])
            ->map(fn ($row) => [
                'category_id' => (int) $row->category_id,
                'category' => $row->category,
                'total_kg' => round((float) $row->total_kg, 3),
                'total_points' => (int) $row->total_points,
            ]);

        $countSubmissions = fn (string $status) => WasteLog::where('user_id', $userId)
            ->where('status', $status)
            ->whereBetween('dropped_at', [$start, $end])
            ->distinct()
            ->count('submission_id');

        return response()->json(['data' => [
            'month' => $month,
            'total_kg' => round((float) $byCategory->sum('total_kg'), 3),
            'total_points' => (int) $byCategory->sum('total_points'),
            'verified_submissions' => $countSubmissions(WasteLog::STATUS_VERIFIED),
            'pending_submissions' => $countSubmissions(WasteLog::STATUS_PENDING),
            'by_category' => $byCategory->values(),
        ]]);
    }
}
