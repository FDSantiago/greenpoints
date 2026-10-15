# GreenPoints API: core flow (v1)

Base URL: `/api/v1`. All endpoints below need `Authorization: Bearer <token>` and `Accept: application/json`.
Staff endpoints need a user with role `staff` or `admin` (otherwise 403).

## Student endpoints

### GET /waste-categories
```json
{
  "data": [{ "id": 1, "name": "Plastic", "is_recyclable": true, "points_per_kg": 10 }],
  "condition_multipliers": { "clean": 1, "contaminated": 0.5 }
}
```
Estimated points for an item = `weight_kg * points_per_kg * condition_multipliers[condition]`.
The server always recalculates; never send points from the client.

### POST /waste-submissions  (201)
```json
{
  "notes": "Grade 11 room 204",
  "items": [
    { "category_id": 1, "weight_kg": 1.2, "waste_condition": "clean" },
    { "category_id": 3, "weight_kg": 0.5, "waste_condition": "contaminated" }
  ]
}
```
`waste_condition` is optional (`clean` | `contaminated`, default `clean`). 1 to 10 items. Response:
```json
{ "data": {
  "id": "9c1d...-uuid", "status": "pending", "submitted_at": "2026-10-13T08:00:00.000000Z",
  "notes": "Grade 11 room 204", "total_weight_kg": 1.7, "points_awarded": null,
  "verified_at": null, "reject_reason": null,
  "items": [{ "id": 12, "category": { "id": 1, "name": "Plastic" }, "weight_kg": 1.2,
              "condition": "clean", "contamination_factor": 1, "points_awarded": null }]
} }
```
Errors: 422 `{ "message": "...", "errors": { "items.0.weight_kg": ["..."] } }`.

### GET /waste-submissions
History, one entry per drop-off, newest first. Query: `status` (pending|verified|rejected), `category_id`, `from`, `to` (dates), `per_page` (max 50), `page`.
Laravel paginator shape: `{ "current_page": 1, "data": [ <submission>, ... ], "last_page": 3, "per_page": 15, "total": 40, ... }`.

### GET /waste-submissions/{id}
One submission (same shape as above). 404 if it isn't yours (staff can see all).

### GET /waste-submissions/summary?month=2026-10
Month defaults to the current one. Only verified items count toward kg and points.
```json
{ "data": { "month": "2026-10", "total_kg": 4.2, "total_points": 31,
            "verified_submissions": 3, "pending_submissions": 1,
            "by_category": [{ "category_id": 1, "category": "Plastic", "total_kg": 2.4, "total_points": 24 }] } }
```

## Staff endpoints

### GET /staff/submissions?status=pending
Review queue across all users (default `pending`). Same pagination and shape as the student history, plus `user: { id, name, group }` on each submission.

### POST /staff/submissions/{id}/verify
Body is optional. Staff may correct what they actually weighed:
```json
{ "items": [{ "id": 12, "weight_kg": 1.1, "waste_condition": "contaminated" }] }
```
Verifies the whole submission, awards points to the student (ledger row per item with points > 0) and returns the updated submission.
Errors: 409 already processed, 403 own submission, 404 unknown id, 422 bad item id.

### POST /staff/submissions/{id}/reject
`{ "reason": "Not sorted properly" }` (required). No points are awarded.

## Status flow
`pending` -> `verified` (points awarded) or `rejected` (reason shown to the student). Terminal states can't be changed.
