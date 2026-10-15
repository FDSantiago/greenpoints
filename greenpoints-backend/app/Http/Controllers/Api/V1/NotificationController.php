<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    /** GET /notifications?status=all|unread&per_page= : newest first. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->user()->notifications()->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['status'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $page->setCollection($page->getCollection()->map(fn ($n) => (new NotificationResource($n))->resolve()));

        return response()->json($page);
    }

    /** GET /notifications/unread-count : cheap enough to poll for the bell badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    /** POST /notifications/{id}/read : idempotent. */
    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->first();
        abort_if(! $notification, 404);

        $notification->markAsRead();

        return response()->json(['data' => (new NotificationResource($notification))->resolve()]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }

    /** DELETE /notifications/{id} : dismiss one. */
    public function destroy(Request $request, string $notificationId): Response
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->first();
        abort_if(! $notification, 404);

        $notification->delete();

        return response()->noContent();
    }
}
