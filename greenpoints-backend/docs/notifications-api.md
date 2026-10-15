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

### GET /notifications/unread-count
`{ "data": { "unread": 3 } }`. Cheap: poll it every 30-60 s for the badge. `GET /dashboard` also returns `unread_notifications`.

### POST /notifications/{id}/read
Marks one as read (safe to repeat); returns the notification.

### POST /notifications/read-all
`{ "data": { "marked": 7 } }`

### DELETE /notifications/{id}
Dismisses one (204).

## React tips
- Poll `unread-count`, and refetch the list when the bell is opened (or after the user's own actions like redeeming).
- On click: mark it read, then navigate using `link`.
- Delivery is instant and database-backed (no push / email yet). Notifications are stored after the action commits, and a failure to store one never breaks the action itself (it is logged in `storage/logs/laravel.log`).

## Adding a new notification type (backend)
1. `app/Notifications/YourThing.php` extending `AppNotification`; call `parent::__construct()` and return `$this->payload($style, $title, $body, $linkType, $linkId)` from `toArray()`.
2. Send it with `Notifier::send($user, new YourThing(...))` after the transaction that caused it has committed.

Old read notifications are never deleted automatically; if the table grows, schedule `$user->notifications()->whereNotNull('read_at')->where('created_at', '<', now()->subMonths(3))->delete()` in a small command.
