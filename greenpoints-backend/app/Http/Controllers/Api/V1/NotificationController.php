<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * GET /notifications/stream : server-sent events for the bell and the alerts screen.
     *
     * Sends `unread` ({ unread }) on connect and whenever the count changes, and `notification`
     * (same shape as the list) for each new one. Every event's SSE id is the stream's cursor, so a
     * reconnect that sends Last-Event-ID (or ?last_event_id=) gets whatever arrived in between; a fresh
     * connection starts from "now". The stream ends after a fixed lifetime and the client reconnects.
     *
     * On a single-worker `php artisan serve` (no --no-reload / PHP_CLI_SERVER_WORKERS) a held-open
     * stream would block every other request, so it answers once and asks the client to come back
     * later, which turns it into polling.
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();
        $config = config('greenpoints.notification_stream');

        if (PHP_SAPI === 'cli-server' && (int) getenv('PHP_CLI_SERVER_WORKERS') < 2) {
            $config = ['lifetime' => 0, 'retry_ms' => 15000] + $config;
        }

        // Notification ids are ordered UUIDs, so "newer than the cursor" is a plain string comparison.
        // The nil UUID sorts before all of them, for users who have no notifications yet.
        $cursor = $request->header('Last-Event-ID') ?: $request->query('last_event_id');
        if (! is_string($cursor) || ! Str::isUuid($cursor)) {
            $cursor = $user->notifications()->max('id') ?? '00000000-0000-0000-0000-000000000000';
        }

        return response()->stream(function () use ($user, $config, $cursor) {
            $deadline = now()->addSeconds($config['lifetime']);
            $unread = null;
            $send = function (string $event, array $data, string $id) {
                echo "id: {$id}\nevent: {$event}\ndata: ".json_encode($data)."\n\n";
            };

            echo "retry: {$config['retry_ms']}\n\n";

            while (true) {
                $fresh = $user->notifications()
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(50)
                    ->get();

                foreach ($fresh as $notification) {
                    $send('notification', (new NotificationResource($notification))->resolve(), $notification->id);
                    $cursor = $notification->id;
                }

                $count = $user->unreadNotifications()->count();
                if ($count !== $unread) {
                    $send('unread', ['unread' => $unread = $count], $cursor);
                } else {
                    echo ": ping\n\n"; // keeps proxies from timing out and lets PHP notice a closed tab
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted() || now()->greaterThanOrEqualTo($deadline)) {
                    break;
                }

                Sleep::for($config['poll_interval'])->seconds();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
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
