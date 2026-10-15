# GreenPoints API: wallet, rewards, leaderboard, dashboard (v1)

Same conventions as `core-flow-api.md`: base `/api/v1`, `Authorization: Bearer <token>`, `Accept: application/json`.
Staff endpoints need role `staff`/`admin` (403 otherwise). Lists use Laravel's paginator shape:
`{ "current_page": 1, "data": [...], "last_page": 1, "per_page": 15, "total": 3, ... }`.

## Points model (read this first)
Points live in an append-only ledger. **Balance = sum of the user's ledger rows**; it is never stored or edited.
- verified waste -> `earn` (+)
- redeem a reward -> `redeem` (-)
- cancelled redemption -> `refund` (+)
- Leaderboards count only `earn` rows, so spending points never lowers rank.

## Dashboard
### GET /dashboard
One call for the home screen:
```json
{ "data": {
  "user": { "id": 7, "name": "Ana Reyes", "group": "11-A" },
  "points": { "balance": 1250, "earned_this_month": 1250,
              "next_reward": { "id": 2, "name": "Watering plants", "points_cost": 1800, "points_needed": 550, "progress_percent": 69 } },
  "rank": { "individual": { "rank": 3, "points": 80 }, "group": { "name": "11-A", "rank": 1, "points": 340 }, "period": "weekly" },
  "pending_submissions": 1,
  "recent_submissions": [ <submission>, ... up to 3 ],
  "active_redemptions": [ <redemption>, ... pending or ready ]
} }
```
`rank.group` is null for users without a group. `next_reward` is null when the user can afford every in-stock reward.

## Wallet
### GET /wallet
`{ "data": { "balance": 1250, "earned_this_month": 1250, "next_reward": { ... } | null } }`

### GET /wallet/transactions?type=earned|redeemed&per_page=20
`earned` = positive rows (earn, refund), `redeemed` = negative rows; omit `type` for everything. Newest first.
```json
{ "id": 41, "type": "earn", "points": 12, "note": "Waste drop-off verified", "created_at": "...",
  "waste": { "submission_id": "uuid", "category": "Plastic", "weight_kg": 1.2 } }
{ "id": 42, "type": "redeem", "points": -1000, "note": "Redeemed: Canteen coffee voucher", "created_at": "...",
  "redemption": { "id": 5, "reward": "Canteen coffee voucher" } }
```
(`waste` / `redemption` appear only on the matching kind of row.)

## Rewards
### GET /rewards?affordable=true
```json
{ "balance": 1250, "data": [{ "id": 3, "name": "Canteen coffee voucher", "description": "...", "image_url": null,
                              "points_cost": 1000, "stock_qty": 50, "in_stock": true, "can_afford": true }] }
```
Cheapest first. `affordable=true` keeps only rewards the user can buy right now.

### GET /rewards/{id}
Reward fields plus the redeem-screen preview:
```json
{ "data": { "id": 3, "...": "...", "preview": { "balance": 1250, "cost": 1000, "balance_after": 250, "can_afford": true,
            "pickup": { "location": "Campus sustainability desk", "note": "Ready in 2-3 school days. Show your redemption code at pickup." } } } }
```

### POST /rewards/{id}/redeem
Optional header **`Idempotency-Key`** (1-64 chars: letters, digits, `_ - : .`). Generate ONE per button press
(`crypto.randomUUID()`) and reuse it if you retry. Responses:
- **201** created: `{ "data": <redemption>, "balance_after": 250 }`
- **200** replay of the same key: same body, plus header `Idempotent-Replayed: true` (no second charge)
- **422** not enough points (`errors.points`) or bad key; **409** out of stock; **404** unknown/inactive reward

A redemption:
```json
{ "id": 5, "code": "K7M2QX9A", "status": "pending", "points_spent": 1000,
  "reward": { "id": 3, "name": "Canteen coffee voucher", "image_url": null },
  "pickup": { "location": "...", "note": "..." }, "redeemed_at": "...", "fulfilled_at": null }
```
Status flow: `pending` -> `ready` -> `claimed`; or `cancelled` (refund) from pending/ready.

### GET /redemptions?status=
My redemptions, newest first (paginated). **GET /redemptions/{id}**: owner or staff (404 for others).

### POST /redemptions/{id}/cancel
Owner can cancel while `pending`; staff can cancel `pending` or `ready`. Refunds the points and returns the stock.
`{ "data": <redemption>, "balance": 1250 }`. 409 if it can't be cancelled any more.

## Staff: redemptions
- **GET /staff/redemptions?status=&code=**: default is the `pending` queue. `code=K7M2QX9A` (case-insensitive) searches all statuses. Each item has `user: { id, name, group }`.
- **POST /staff/redemptions/{id}/ready**: pending -> ready. **POST /staff/redemptions/{id}/claim**: pending|ready -> claimed (records who and when).
- 409 on a wrong status; 403 if the staff member is processing their own redemption.

## Leaderboard
### GET /leaderboard?scope=group|individual&period=weekly|monthly|all&metric=total|per_member&limit=20
Defaults: `scope=group`, `period=weekly`, `metric=total`. `metric=per_member` (group scope) = points / students in the group.
```json
{ "data": { "scope": "group", "period": "weekly", "metric": "per_member", "from": "...", "to": "...",
  "entries": [{ "rank": 1, "id": 1, "name": "11-A", "points": 340, "members": 2, "per_member": 170, "score": 170 }],
  "me": { "rank": 1, "id": 1, "name": "11-A", "...": "..." } } }
```
Ties share a rank (1, 2, 2, 4). `me` is the caller's own row (their group in group scope), even when outside the top `limit`; null if they have no group.
Individual scope entries are `{ rank, id, name, points, score }`. Only students are ranked.
