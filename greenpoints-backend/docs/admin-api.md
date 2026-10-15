# GreenPoints API: admin (v1)

Everything under `/api/v1/admin` needs a user with role `admin` (403 for staff and students, 401 without a token).
Same conventions as the other docs. Lists of users and report history use Laravel's paginator shape
(`{ current_page, data, total, ... }`); the small lists (groups, categories, rewards) return `{ "data": [...] }`.
Deletes return **204 with no body**.

## Rules of thumb
- Things with history are never hard-deleted: delete returns **409** and tells you to deactivate instead (`PATCH ... { "is_active": false }`).
- Price and rate changes (reward `points_cost`, category `points_per_kg`) only affect the future; points already awarded or spent are snapshots.
- Admins can't change their own role, deactivate or delete themselves (422).

## Users
- `GET /admin/users?role=&group_id=&is_active=&q=&per_page=` : `q` searches name and email.
- `POST /admin/users` : `{ name, email, password (min 8), role: student|staff|admin, group_id? }` -> 201
- `GET /admin/users/{id}` ; `PATCH /admin/users/{id}` : any of `name, email, password, role, group_id, is_active`
- `DELETE /admin/users/{id}` : only users with no waste logs, points or redemptions (else 409)
- `GET /admin/users/{id}/transactions` : the user's full points ledger
- `POST /admin/users/{id}/points-adjustments` : `{ "points": 50, "note": "Event bonus" }` (non-zero, within +-100000, can't make the balance negative)
  -> 201 `{ "data": <transaction>, "balance": 80 }`. The admin's name is stored in the ledger note.

A user:
```json
{ "id": 7, "name": "Ana Reyes", "email": "ana@school.test", "role": "student", "is_active": true,
  "group": { "id": 1, "name": "11-A" }, "points_balance": 1250, "created_at": "..." }
```
Deactivating a user (or changing their password) revokes all their tokens at once; a deactivated user gets **403** at login.

## Groups (classes / zones)
`GET/POST /admin/groups`, `GET/PATCH/DELETE /admin/groups/{id}`. Body: `{ name (unique), type: class|zone }`.
Items include `members_count`. Deleting a group with members returns 409.

## Waste categories
`GET/POST /admin/waste-categories`, `GET/PATCH/DELETE /admin/waste-categories/{id}`.
Body: `{ name (unique), points_per_kg (0..10000), is_recyclable?, is_active? }`. The list includes inactive ones and a `logs_count`.
Inactive categories disappear from `GET /waste-categories` (the student endpoint). Delete returns 409 once any waste log uses it.

## Rewards
`GET/POST /admin/rewards`, `GET/PATCH/DELETE /admin/rewards/{id}`.
Body: `{ name (unique), description?, image_url? (a URL), points_cost (1..1000000), stock_qty (0..100000), is_active? }`.
Items include `redemptions_count`. Inactive rewards are hidden from students and can't be redeemed (404). Delete returns 409 after the first redemption.
(`image_url` is a link; there is no image upload yet.)

## Impact reports ("kg diverted from landfill")
Only **verified** waste counts, by the month it was dropped off, attributed to the student's **current** group.

- `GET /admin/reports/impact?month=2026-10` : live numbers (month defaults to the current one)
```json
{ "data": { "month": "2026-10",
  "totals": { "kg": 41.5, "points": 380, "submissions": 12, "ungrouped_kg": 0 },
  "groups": [{ "group_id": 1, "name": "11-A", "students": 30, "total_kg": 22.3, "total_points": 210,
               "submissions": 7, "kg_per_student": 0.743 }],
  "by_category": [{ "category_id": 1, "category": "Plastic", "is_recyclable": true, "total_kg": 12, "total_points": 120 }] } }
```
- `POST /admin/reports/impact/generate` `{ "month": "2026-10" }` : stores or refreshes one `impact_reports` row per group (safe to call repeatedly)
- `GET /admin/reports/impact/history?group_id=&per_page=` : stored snapshots, newest month first (good for trend charts)
- `GET /admin/reports/impact/export?month=2026-10&type=groups|categories` : CSV download (`Authorization` header required, so fetch it as a blob in React)

### Monthly job
`php artisan greenpoints:generate-impact-reports [YYYY-MM]` stores last month's snapshot (or the given month).
To run it automatically on the 1st of each month, add this line to the bottom of `routes/console.php`:
```php
\Illuminate\Support\Facades\Schedule::command('greenpoints:generate-impact-reports')->monthlyOn(1, '01:00');
```
Check with `php artisan schedule:list`. On a server a cron entry must call `php artisan schedule:run` every minute; in development use `php artisan schedule:work`.
