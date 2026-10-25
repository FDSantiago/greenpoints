# GreenPoints ↔ School Registry integration

CIT 305 Systems Integration and Architecture: how System 1 (GreenPoints) uses System 2 (School Registry).

| | System 1: GreenPoints backend | System 2: School Registry |
|---|---|---|
| Purpose | Recycling rewards: students log waste, earn points, redeem rewards | Official list of students and their sections |
| Role in the integration | **Client**: asks who a student is before creating their account | **Server**: answers student and roster lookups |
| Technology | Laravel 13 (PHP 8.3), Sanctum, MySQL 8.0 | Bun + Hono (TypeScript), MySQL 8.4 / MariaDB |
| Contract | — | `sia_school-registry/docs/openapi.yaml` (OpenAPI 3) |

**Why they talk:** GreenPoints must only give accounts to real, currently enrolled students, and put each
student in the right class group. The School Registry is the source of truth for both.

All examples below were captured from real runs on 2026-10-25 (local dev containers, registry seeded
with its fictional data). API keys and tokens are replaced with `••••••`.

## 1. Architecture

```
                         SYSTEM INTEGRATION ARCHITECTURE

  ┌──────────────────────────┐
  │  Student's browser       │
  │  GreenPoints web (React) │
  └────────────┬─────────────┘
               │ POST /api/v1/auth/register   (JSON: student_id, email, password)
               ▼
  ┌────────────────────────────────────────────┐            ┌───────────────────┐
  │  SYSTEM 1: GreenPoints backend (Laravel)   │  SQL       │  GreenPoints DB   │
  │                                            │───────────▶│  MySQL 8.0        │
  │  AuthController → StudentRegistrationSvc   │            │  users.student_id │
  │  RosterSyncService, Admin RegistryCtrl     │            │  users.course     │
  │  ┌──────────────────────────────────────┐  │            │  class_groups     │
  │  │ Integration layer:                   │  │            └───────────────────┘
  │  │ SchoolRegistryClient (HTTP client)   │  │
  │  │ timeout 3 s, 2 retries, typed errors │  │
  │  └──────────────────┬───────────────────┘  │
  └─────────────────────┼──────────────────────┘
                        │  REST over HTTP, GET only
                        │  Headers: X-API-Key (read-only key), X-Request-Id
                        │  ─────────▶ GET /api/v1/students/{student_id}
                        │  ─────────▶ GET /api/v1/sections/{section}/students
                        │  ─────────▶ GET /health
                        │  ◀───────── JSON (student / roster), or XML roster with ?format=xml
                        ▼
  ┌────────────────────────────────────────────┐            ┌───────────────────┐
  │  SYSTEM 2: School Registry (Bun + Hono)    │  SQL       │  Registry DB      │
  │  API-key auth (read / admin), rate limit   │───────────▶│  MySQL 8.4        │
  │  60/min per key, one error shape           │            │  students         │
  └────────────────────────────────────────────┘            └───────────────────┘
```

- **Communication direction:** System 1 calls System 2. System 2 never calls System 1.
- **Data format:** JSON (snake_case) for everything GreenPoints reads. The registry can also export a
  roster as XML (`Accept: application/xml` or `?format=xml`); that is shown in the demo.
- **Security:** the registry key goes in the `X-API-Key` header. GreenPoints only has the **read** key, so it can
  only call GET endpoints. The key lives in `.env` (`SCHOOL_REGISTRY_KEY`) and is never logged, returned or committed.
- **Traceability:** GreenPoints sends an `X-Request-Id` (it reuses the caller's id if valid). Both systems
  log it, so one request can be followed across both logs.
- **Separate databases:** each system owns its own database. GreenPoints copies only what it needs
  (student_id, name, course, section as class group).

## 2. Data exchanged

The request is `GET /api/v1/students/2024-00309` with headers `X-API-Key` and `X-Request-Id`. The response is a JSON student:

| Field | Example | What GreenPoints does with it |
|---|---|---|
| `student_id` | `2024-00309` | stored as `users.student_id` (unique) |
| `first_name`, `last_name` | `Ana Maria`, `O'Reilly` | `users.name` = "Ana Maria O'Reilly" (the name typed in the form is ignored) |
| `course` | `BSIT` | stored as `users.course` |
| `year_level` | `3` | read, not stored |
| `section` | `IT3A` | the user joins class group `IT3A` (created if missing) |
| `status` | `Active` / `Inactive` / `Graduated` | only `Active` may register |

## 3. Endpoints

**System 2 endpoints GreenPoints calls** (all GET, read key):

| Endpoint | Used for | Answers GreenPoints handles |
|---|---|---|
| `GET /api/v1/students/{student_id}` | registration check | 200 student, 404 `STUDENT_NOT_FOUND`, 401/403 |
| `GET /api/v1/sections/{section}/students` | roster sync | 200 `{section, count, students[]}`, 404 `SECTION_NOT_FOUND` (empty) |
| `GET /health` | admin status page | 200 `{status, version, time}` |

**GreenPoints endpoints that use the integration:**

| Endpoint | Who | What |
|---|---|---|
| `POST /api/v1/auth/register` | anyone | needs `student_id` when `SCHOOL_REGISTRY_ENABLED=true` |
| `POST /api/v1/admin/registry/sync` `{section?}` | admin | pull rosters for class groups; report matched / updated / missing |
| `GET /api/v1/admin/registry/health` | admin | registry health + client config (never the key) + last call |
| `php artisan greenpoints:sync-roster {section?}` | server | same as the sync endpoint, as a table |

## 4. Data flow: a student registers

1. The student fills in the form (Student ID, name, email, password) and the browser sends `POST /api/v1/auth/register` (JSON).
2. GreenPoints validates the request: `student_id` is required, must look like `2026-00123`, and must not already
   have an account. Any failure here is a 422, and System 2 is not called.
3. `SchoolRegistryClient` sends `GET /api/v1/students/{student_id}` to System 2 with `X-API-Key` and `X-Request-Id`.
4. System 2 checks the key, looks the student up in its own database and answers with JSON.
5. GreenPoints deserializes the JSON into a `RegistryStudent` object and checks its shape.
6. Decision:
   - 404: GreenPoints answers 422 "This student ID was not found in the School Registry."
   - status is not `Active`: GreenPoints answers 422 "This student is not active in the School Registry (status: …)."
   - `Active`: in one database transaction, find or create class group `section`, then create the user with the
     registry's name and course and store `student_id`.
   - timeout, connection error, 5xx (after 2 retries), 429, 401/403 or a malformed answer: GreenPoints answers 503
     `REGISTRY_UNAVAILABLE` and creates nothing.
7. GreenPoints answers 201 with an API token and the user. The web app shows the student's dashboard, or the error message.

## 5. Real examples

### 5.1 System 2 directly: student lookup (JSON)

```
> GET http://sia_school-registry_devcontainer-app-1:4000/api/v1/students/2024-00309
> Accept: application/json
> X-Request-Id: demo-004230-26317
> X-API-Key: •••••• (registry read key, hidden)

< HTTP/1.1 200 OK
< Content-Type: application/json
< X-Request-Id: demo-004230-26317
<
{
  "student_id": "2024-00309",
  "first_name": "Ana Maria",
  "last_name": "O'Reilly",
  "course": "BSIT",
  "year_level": 3,
  "section": "IT3A",
  "status": "Active",
  "created_at": "2026-10-24T23:46:31.758Z",
  "updated_at": "2026-10-24T23:46:31.758Z"
}
```

### 5.2 System 2 directly: section roster (XML)

```
> GET http://sia_school-registry_devcontainer-app-1:4000/api/v1/sections/IT1A/students?status=Inactive&format=xml
> X-API-Key: •••••• (registry read key, hidden)

< HTTP/1.1 200 OK
< Content-Type: application/xml; charset=utf-8
< X-Request-Id: demo-004230-29070
<
<?xml version="1.0" encoding="UTF-8"?>
<roster>
  <section>IT1A</section>
  <count>1</count>
  <students>
    <student>
      <student_id>2026-00109</student_id>
      <first_name>Ivan</first_name>
      <last_name>Castillo</last_name>
      <course>BSIT</course>
      <year_level>1</year_level>
      <section>IT1A</section>
      <status>Inactive</status>
      <created_at>2026-10-24T23:46:31.757Z</created_at>
      <updated_at>2026-10-24T23:46:31.757Z</updated_at>
    </student>
  </students>
</roster>
```

### 5.3 GreenPoints: successful registration (System 1 → System 2 → System 1)

```
> POST http://localhost:8000/api/v1/auth/register
> Accept: application/json
> X-Request-Id: demo-004230-13579
> Content-Type: application/json
>
> {
>   "student_id": "2024-00309",
>   "name": "Typed In The Form",
>   "email": "demo-2024-00309@student.test",
>   "password": "demo-pass-2024-00309",
>   "password_confirmation": "demo-pass-2024-00309"
> }

< HTTP/1.1 201 Created
< Content-Type: application/json
<
{
  "token": "•••••• (API token, hidden)",
  "user": {
    "id": 10,
    "name": "Ana Maria O'Reilly",
    "email": "demo-2024-00309@student.test",
    "role": "student",
    "student_id": "2024-00309",
    "course": "BSIT",
    "group": {
      "id": 5,
      "name": "IT3A",
      "type": "class"
    },
    "created_at": "2026-10-25T00:42:30.000000Z"
  }
}
```

The same request id appears in both logs:

```
GreenPoints  storage/logs/laravel.log:
[2026-10-25 00:42:30] local.INFO: school_registry.call {"method":"GET","path":"/api/v1/students/2024-00309","status":200,"ms":1,"request_id":"demo-004230-13579","attempt":1}

School Registry log:
{"level":"info","time":"2026-10-25T00:42:30.249Z","request_id":"demo-004230-13579","method":"GET","path":"/api/v1/students/2024-00309","status":200,"ms":0.3,"client":"read"}
```

### 5.4 GreenPoints: unknown student id

```
> POST http://localhost:8000/api/v1/auth/register
> {"student_id": "2026-99999", "name": "Typed In The Form", "email": "demo-2026-99999@student.test", ...}

< HTTP/1.1 422 Unprocessable Content
{
  "message": "This student ID was not found in the School Registry.",
  "errors": {
    "student_id": [
      "This student ID was not found in the School Registry."
    ]
  }
}
```

### 5.5 GreenPoints: inactive student

```
> POST http://localhost:8000/api/v1/auth/register
> {"student_id": "2026-00109", "name": "Typed In The Form", "email": "demo-2026-00109@student.test", ...}

< HTTP/1.1 422 Unprocessable Content
{
  "message": "This student is not active in the School Registry (status: Inactive).",
  "errors": {
    "student_id": [
      "This student is not active in the School Registry (status: Inactive)."
    ]
  }
}
```

### 5.6 GreenPoints: School Registry stopped

```
> POST http://localhost:8000/api/v1/auth/register
> X-Request-Id: demo-004236-21722
> {"student_id": "2026-00101", "name": "Typed In The Form", "email": "demo-2026-00101@student.test", ...}

< HTTP/1.1 503 Service Unavailable
{
  "error": {
    "code": "REGISTRY_UNAVAILABLE",
    "message": "The School Registry is unavailable right now. Please try again later.",
    "details": {
      "request_id": "demo-004236-21722"
    }
  }
}
```

GreenPoints log for that request: one attempt plus two retries, then the 503. Afterwards the database held 0 accounts for `2026-00101`.

```
[2026-10-25 00:42:36] local.INFO: school_registry.call {"method":"GET","path":"/api/v1/students/2026-00101","status":"connection_error","ms":1,"request_id":"demo-004236-21722","attempt":1}
[2026-10-25 00:42:36] local.INFO: school_registry.call {"method":"GET","path":"/api/v1/students/2026-00101","status":"connection_error","ms":1,"request_id":"demo-004236-21722","attempt":2}
[2026-10-25 00:42:36] local.INFO: school_registry.call {"method":"GET","path":"/api/v1/students/2026-00101","status":"connection_error","ms":1,"request_id":"demo-004236-21722","attempt":3}
[2026-10-25 00:42:36] local.WARNING: school_registry.unavailable {"exception":"RegistryUnavailable","message":"The School Registry could not be reached.","request_id":"demo-004236-21722"}
```

### 5.7 Admin: integration status

`GET /api/v1/admin/registry/health` (admin token). This was captured at the start of the second demo run, so
`last_call` shows the failed final attempt from the first run's outage. The registry itself is reachable again:

```json
{
  "data": {
    "registry": {
      "reachable": true,
      "status": "ok",
      "version": "1.0.0",
      "time": "2026-10-25T00:42:30.186Z",
      "latency_ms": 4
    },
    "client": {
      "enabled": true,
      "base_url": "http://sia_school-registry_devcontainer-app-1:4000",
      "key_configured": true,
      "timeout_seconds": 3,
      "retries": 2
    },
    "last_call": {
      "method": "GET",
      "path": "/api/v1/students/2026-00101",
      "status": "connection_error",
      "ms": 1,
      "request_id": "demo-004146-5829",
      "attempt": 3,
      "at": "2026-10-25T00:41:46+00:00"
    }
  }
}
```

### 5.8 Admin: roster sync

`POST /api/v1/admin/registry/sync` with `{"section":"IT3A"}`. The second run gave the same answer, which shows the sync is idempotent:

```json
{"data":{"sections":[{"section":"IT3A","registry_students":9,"matched":1,"updated":0,"missing_in_registry":0,"without_student_id":0,"missing_student_ids":[]}],"totals":{"registry_students":9,"matched":1,"updated":0,"missing_in_registry":0,"without_student_id":0}}}
```

`php artisan greenpoints:sync-roster`, which covers all class groups. 11-A, 11-B and 12-A are seeded GreenPoints groups that the registry doesn't know:

```
+---------+-------------------+---------+---------+---------------------+--------------------+
| section | registry_students | matched | updated | missing_in_registry | without_student_id |
+---------+-------------------+---------+---------+---------------------+--------------------+
| 11-A    | 0                 | 0       | 0       | 0                   | 2                  |
| 11-B    | 0                 | 0       | 0       | 0                   | 2                  |
| 12-A    | 0                 | 0       | 0       | 0                   | 2                  |
| IT3A    | 9                 | 1       | 0       | 0                   | 0                  |
| TOTAL   | 9                 | 1       | 0       | 0                   | 6                  |
+---------+-------------------+---------+---------+---------------------+--------------------+
```

## 6. Failure behaviour

| What happens at System 2 | What GreenPoints does | Retried? | HTTP to the student |
|---|---|---|---|
| Student not found (404) | field error on `student_id` | no | 422 |
| Student Inactive / Graduated | field error on `student_id` | no | 422 |
| Wrong or missing key (401/403), or no key configured | logs a warning; nothing created | no | 503 `REGISTRY_UNAVAILABLE` |
| Down / connection refused / timeout (3 s) | 2 retries (200 ms, 400 ms), then gives up; nothing created | yes | 503 `REGISTRY_UNAVAILABLE` |
| 5xx | 2 retries; success if it recovers | yes | 201 or 503 |
| Rate limited (429) | gives up straight away | no | 503 `REGISTRY_UNAVAILABLE` |
| Answer not in the contract's shape | `RegistryBadResponse`; nothing created | no | 503 `REGISTRY_UNAVAILABLE` |

- Nothing is written before System 2 has answered, so a failure never leaves a half-created account.
- The 503 body carries the `request_id`, which matches both logs.
- Roster sync never deletes users. Users missing from a roster are only reported.
- With `SCHOOL_REGISTRY_ENABLED=false`, registration works exactly as it did before the integration.
- `GET /admin/registry/health` always answers 200 and reports an unreachable registry as `"reachable": false`.

## 7. Integration test cases

Results come from the real runs on 2026-10-25. The demo cases are from `scripts/demo-integration.sh` (section 5). The E2E cases are from `SCHOOL_REGISTRY_E2E=1 php artisan test --group=e2e` against the running registry.

| ID | Scenario | Request | Expected | Actual |
|---|---|---|---|---|
| TC-01 | Valid, Active student registers | `POST /auth/register` `student_id=2024-00309` | 201; name, course and group taken from System 2 | 201; name "Ana Maria O'Reilly" (not the typed name), course BSIT, group IT3A (class) ✅ |
| TC-02 | Unknown student id (invalid data) | `POST /auth/register` `student_id=2026-99999` | 422 on `student_id`, no account | 422 "This student ID was not found in the School Registry." ✅ |
| TC-03 | Missing required field | `POST /auth/register` without `student_id` | 422 "required", System 2 not called | 422 `{"message":"Your student ID is required.","errors":{"student_id":["Your student ID is required."]}}` (curl); "System 2 not called" proven by `Http::assertNothingSent()` in the automated test ✅ |
| TC-04 | Inactive student | `POST /auth/register` `student_id=2026-00109` | 422 "not active", no account | 422 "This student is not active in the School Registry (status: Inactive)." ✅ |
| TC-05 | System 2 stopped | `POST /auth/register` `student_id=2026-00101` while the registry was stopped | 503 `REGISTRY_UNAVAILABLE`, no account | 503 `REGISTRY_UNAVAILABLE`, 3 attempts logged, 0 accounts for 2026-00101 ✅ |
| TC-06 | Invalid API request (wrong key) | registration with `SCHOOL_REGISTRY_KEY` set to a wrong value | System 2: 401; GreenPoints: 503, no account | E2E-05 `RegistryAuthFailed` ✅; E2E-11 503 `REGISTRY_UNAVAILABLE`, 0 users ✅ |
| TC-07 | System 1 shows System 2's data | `GET /api/v1/students/2024-00309` (System 2) vs the 201 above (System 1) | the same student data on both sides | The registry returned Ana Maria O'Reilly / BSIT / IT3A, and the GreenPoints user shows the same values ✅ |
| TC-08 | XML exchange | `GET /sections/IT1A/students?status=Inactive&format=xml` | 200 `application/xml` roster | 200 `application/xml; charset=utf-8`, 1 `<student>` (2026-00109) ✅ |
| TC-09 | Roster sync, run twice | `POST /admin/registry/sync` `{"section":"IT3A"}` ×2 | same counts both times, nothing deleted | both runs: registry_students 9, matched 1, updated 0, missing 0 ✅; E2E-12: first run updated 1, second run updated 0 ✅ |
| TC-10 | Integration status | `GET /admin/registry/health` (admin) | reachable, config shown, no key | reachable true, `key_configured: true`, no key in the body ✅ (E2E-13 ✅) |

**Automated tests** (`php artisan test`, MySQL `greenpoints_test`, `Http::fake()`, no network):
46 passed (554 assertions), 13 skipped (the e2e group, which only runs when `SCHOOL_REGISTRY_E2E` is set). They cover all the cases above plus 5xx-then-success, 5xx every time,
timeout, 429, malformed answers, missing config, admin-only access and registry disabled.

**E2E group against the real registry** (13 passed, 38 assertions):

```
   PASS  Tests\Unit\SchoolRegistryE2ETest
  ✓ it E2E-01 reaches the registry health check
  ✓ it E2E-02 finds an Active student
  ✓ it E2E-03 finds an Inactive student
  ✓ it E2E-04 reports an unknown student id
  ✓ it E2E-05 rejects a wrong API key
  ✓ it E2E-06 pulls a section roster as JSON
  ✓ it E2E-07 returns an empty roster for an unknown section
   PASS  Tests\Feature\SchoolRegistryRegistrationE2ETest
  ✓ it E2E-08 registers an Active student with registry data
  ✓ it E2E-09 rejects an unknown student id with 422
  ✓ it E2E-10 rejects an Inactive student with 422
  ✓ it E2E-11 returns 503 when the registry rejects the key
  ✓ it E2E-12 syncs a real roster idempotently
  ✓ it E2E-13 reports the real registry health to an admin
  Tests:    13 passed (38 assertions)
```

## 8. Running it

### Configuration (`.env`)

```
SCHOOL_REGISTRY_ENABLED=true        # false (default): registration works as before
SCHOOL_REGISTRY_URL=http://...      # registry base URL, no trailing /api/v1
SCHOOL_REGISTRY_KEY=...             # the registry READ key; never commit it
SCHOOL_REGISTRY_TIMEOUT=3           # seconds per attempt
SCHOOL_REGISTRY_RETRIES=2           # extra attempts on connection errors / 5xx
```

### Option A: the two dev containers (two terminals)

1. **Terminal 1 (System 2):** in the `sia_school-registry` dev container, run `bun run seed`, then `bun run dev` (port 4000).
2. **Connect the networks:** the two dev containers are on different Docker networks, so once per container start, run this on the host:
   `docker network connect ipt_final_project_devcontainer_default sia_school-registry_devcontainer-app-1`
   Then `SCHOOL_REGISTRY_URL=http://sia_school-registry_devcontainer-app-1:4000`, and `SCHOOL_REGISTRY_KEY` is the
   registry's `REGISTRY_READ_KEY`.
3. **Terminal 2 (System 1):** in the GreenPoints dev container, run `cd greenpoints-backend && php artisan migrate && php artisan serve --host=0.0.0.0 --port=8000`.

### Option B: one compose file

```sh
export SCHOOL_REGISTRY_KEY=<registry read key> REGISTRY_ADMIN_KEY=<registry admin key>
docker compose -f docker-compose.integration.yml up --build
# GreenPoints http://localhost:8001, School Registry http://localhost:4001 (re-seeded on every start)
docker compose -f docker-compose.integration.yml down -v      # stop and delete both databases
```

### Option C: the hosted registry (live demo)

Set `SCHOOL_REGISTRY_URL` and `SCHOOL_REGISTRY_KEY` in `.env` to the hosted instance's values (they are commented out
in `.env`). During the demo, stop and start the registry on the hosting platform.

### Tests

```sh
php artisan test                                     # needs a greenpoints_test database on the MySQL server
SCHOOL_REGISTRY_E2E=1 php artisan test --group=e2e   # against the registry in SCHOOL_REGISTRY_URL
```

Create the test database once with `CREATE DATABASE greenpoints_test;`.

### Demo script

```sh
scripts/demo-integration.sh                          # in the GreenPoints dev container
GP_ADMIN_TOKEN=<admin token> scripts/demo-integration.sh   # also shows the admin status endpoint
GP_URL=http://localhost:8001 REGISTRY_URL=http://localhost:4001 REGISTRY_KEY=... scripts/demo-integration.sh   # option B
```

The script pauses twice: once to let you stop the registry, and once to let you start it again.

Each run registers `2024-00309` for real, so a second run gets 422 "An account with this student ID already exists."
Either run it with another Active seeded student (`ACTIVE_ID=2026-00103`, for example), or remove the demo accounts. Demo accounts have
no points history, so they can be deleted:

```sh
php artisan tinker --execute '$u = App\Models\User::where("email","like","demo-%@student.test")->get(); $u->each(fn ($x) => $x->tokens()->delete()); $u->each->delete(); echo $u->count();'
```
