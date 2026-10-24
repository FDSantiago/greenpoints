# GreenPoints API: notifications (v1)

In-app notifications for the bell icon and the notifications screen. All endpoints need `Authorization: Bearer <token>`
and only ever touch the caller's own notifications (someone else's id is a 404).

## What triggers one
| Event | Style | Title |
|---|---|---|
| Staff verify a drop-off | `goal` (gold) | "{n} points have been added to your account!" |
| Staff verify, but it earned 0 points (very light) | `info` | "Your drop-off was verified" |
| Staff reject a drop-off | `alert` (red) | "Drop-off rejected" (body has the reason) |
| Staff mark a redemption ready | `info` | "Your reward is ready for pickup" (body has the code and the pickup place) |
| Staff hand it over | `info` | "Reward collected" |
| Staff cancel a redemption | `alert` | "Redemption cancelled" (points refunded) |
| Admin adjusts points | `goal` if +, `alert` if - | "Points adjusted: +50" |

Not notified: submitting waste, redeeming, or cancelling your own redemption (you just did those).
`style` maps to the three cards in the wireframe: `info` = white/green, `alert` = red, `goal` = gold.

## Endpoints
### GET /notifications?status=all|unread&per_page=20
Newest first, standard paginator (`{ current_page, data, last_page, total, ... }`).
```json
{ "id": "0199c1f2-....", "style": "goal",
  "title": "20 points have been added to your account!", "body": "Your 2 kg drop-off was verified.",
  "link": { "type": "submission", "id": "9c1d....-uuid" },
  "read": false, "read_at": null, "created_at": "2026-10-16T08:00:00.000000Z" }
```
`link.type` is `submission` (id = submission uuid), `redemption` (id = redemption id) or `wallet` (id = null).
Use it for click-through: submission -> `GET /waste-submissions/{id}`, redemption -> `GET /redemptions/{id}`, wallet -> `GET /wallet`.

### GET /notifications/stream (live, server-sent events)
`Content-Type: text/event-stream`. Same Bearer auth as everything else, so open it with `fetch()` and read the body
(the browser's `EventSource` can't send an `Authorization` header). Events:
```
event: unread                         <- on connect, and whenever the count changes (incl. reads in another tab)
data: {"unread":3}

id: 0199c1f2-....                     <- one per new notification, same shape as the list items
event: notification
data: {"id":"0199c1f2-....","style":"goal","title":"...", ...}

: ping                                <- keep-alive comment, ignore
```
- A fresh connection does **not** replay old notifications; it only sends the current `unread` count.
- Every event's `id:` is the stream's cursor. The server closes the stream after ~55 s (`GREENPOINTS_SSE_LIFETIME`)
  to free the PHP worker. Reconnect after the `retry:` delay and send `Last-Event-ID: <last id you got>`
  (or `?last_event_id=`) to receive anything created while you were disconnected.
- Each open stream holds one PHP worker, so run the dev server with several workers:
  `PHP_CLI_SERVER_WORKERS=16 php artisan serve --host 0.0.0.0 --no-reload` (`--no-reload` is required, or Laravel
  ignores the worker count). docker-compose already does this. On a plain single-worker `php artisan serve` the stream
  answers once and sends `retry: 15000` instead of staying open, so it degrades to polling rather than blocking
  every other request.

### GET /notifications/unread-count
`{ "data": { "unread": 3 } }`. Fallback for clients that can't hold a stream open. `GET /dashboard` also returns `unread_notifications`.

### POST /notifications/{id}/read
Marks one as read (safe to repeat); returns the notification.

### POST /notifications/read-all
`{ "data": { "marked": 7 } }`

### DELETE /notifications/{id}
Dismisses one (204).

## React tips
- Keep one `/notifications/stream` open while logged in: `unread` drives the bell badge, `notification` can refresh the list or show a toast.
- On click: mark it read, then navigate using `link`.
- Notifications are database-backed and reach open streams within ~2 s (`GREENPOINTS_SSE_POLL_INTERVAL`); no push / email yet. Notifications are stored after the action commits, and a failure to store one never breaks the action itself (it is logged in `storage/logs/laravel.log`).

## Adding a new notification type (backend)
1. `app/Notifications/YourThing.php` extending `AppNotification`; call `parent::__construct()` and return `$this->payload($style, $title, $body, $linkType, $linkId)` from `toArray()`.
2. Send it with `Notifier::send($user, new YourThing(...))` after the transaction that caused it has committed.

Old read notifications are never deleted automatically; if the table grows, schedule `$user->notifications()->whereNotNull('read_at')->where('created_at', '<', now()->subMonths(3))->delete()` in a small command.
