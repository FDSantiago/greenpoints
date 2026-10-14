<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waste\RejectSubmissionRequest;
use App\Http\Requests\Waste\VerifySubmissionRequest;
use App\Http\Resources\WasteSubmissionResource;
use App\Models\WasteLog;
use App\Services\WasteSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubmissionController extends Controller
{
    public function __construct(private WasteSubmissionService $service)
    {
    }

    /** GET /staff/submissions?status=pending : review queue across all users (default: pending). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = WasteLog::query()->where('status', $filters['status'] ?? WasteLog::STATUS_PENDING);

        return response()->json($this->service->paginate($query, (int) ($filters['per_page'] ?? 15)));
    }

    /** POST /staff/submissions/{id}/verify : confirm (optionally correcting weights) and award points. */
    public function verify(VerifySubmissionRequest $request, string $submissionId): JsonResponse
    {
        $logs = $this->service->verify($submissionId, $request->user(), $request->validated('items') ?? []);

        return response()->json(['data' => (new WasteSubmissionResource($logs))->resolve()]);
    }

    /** POST /staff/submissions/{id}/reject : reject with a reason, no points. */
    public function reject(RejectSubmissionRequest $request, string $submissionId): JsonResponse
    {
        $logs = $this->service->reject($submissionId, $request->user(), $request->validated('reason'));

        return response()->json(['data' => (new WasteSubmissionResource($logs))->resolve()]);
    }
}
