# Phase 12: Live Classes via Jitsi Meet

**Status:** Complete. `composer test` (906 tests, 905 passed, 1 skipped — the documented
MySQL-only preflight test), `composer test:mysql` (874/874), `composer test:js` (29/29),
`composer lint`, and `composer analyse` (0 errors) all pass with zero regressions to
Phases 1–11.
> Test count at time of writing; see README for the current totals.

---

## What ships

A teacher schedules a live class against a course (optionally pinned to one lesson), a
student enrolled in that course sees it on the dashboard and the course page and hits
**Join** inside the open window, and the server answers with a 302 to a Jitsi room it
minted itself. While the student sits there, a heartbeat beacon credits attended seconds
using server timestamps only; the teacher opens an attendance report — every enrolled
student, attended time, absent list, optional `?min_minutes=` filter, CSV export — and the
whole feature can be switched off with one env flag so a deployment that doesn't want it
loses every route, widget and preflight requirement.

The operational guide lives in [LIVE_CLASSES.md](../../LIVE_CLASSES.md); this file is the
implementation record.

## Decisions confirmed before implementation (Step 1 clarifications)

- **Decision A — JaaS (8x8.vc) free tier.** One `JITSI_APP_ID` + `JITSI_APP_SECRET` pair
  in `.env`, read via `config/services.php` (`jitsi.*`), used for exactly one thing:
  signing the short-lived join JWT. No per-tenant JaaS configuration exists or is planned
  for this phase — rooms are isolated by their unguessable names plus our own
  enrolment-gated redirect, not by per-tenant JaaS apps.
- **Decision B — recording is off.** JaaS recording is a paid add-on;
  `LIVE_CLASSES_RECORDING_ENABLED=false` by default, and the "this class may be recorded"
  notice on the class page renders **only** when that flag is on. Nothing else in the code
  path changes with it.
- **`created_by` is nullable** (Clarification 1) with the standard composite FK
  `restrictOnDelete`, mirroring `courses.created_by`: a class row is never hostage to its
  creator record. Tested both ways (insert with a live creator; delete the creator's user
  row semantics covered by the composite-FK tests).
- **Room names must be unpredictable** (Clarification 2): 12 byte-identical classes in one
  tenant produce 12 distinct names, all matching `/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/`, and
  none containing the course id, class id, tenant id or title.

## Feature flag and preflight

`coaching.live_classes_enabled` (`.env`: `LIVE_CLASSES_ENABLED`, **default false**) is the
one switch. When false: every live-class controller's first statement is
`abort_unless(..., 404)` (so routes, dashboard widgets and course sections all behave as if
the feature doesn't exist), and `app:preflight` neither requires nor mentions Jitsi.

When true, `app:preflight` **fails** (exit 1) unless both `services.jitsi.app_id` and
`services.jitsi.app_secret` are set, and prints a success line containing `Jitsi` on a
green run. The literal word `Jitsi` in both outputs is part of the
`LiveClassPreflightTest` contract. This is Decision A's deploy-time half: a deployment
that flips the flag on without keys would otherwise 500 on every join while the UI still
advertised a Join button.

## Schema

### `live_classes` (tenant-owned)

| Column | Notes |
|---|---|
| `id`, `tenant_id` | standard |
| `course_id` | composite FK → `courses(tenant_id, id)`, `restrictOnDelete` (`tenantForeign`) |
| `lesson_id` nullable | composite FK → `lessons(tenant_id, id)`, **`restrictOnDelete`** — see note |
| `created_by` nullable | composite FK → `users(tenant_id, id)`, `restrictOnDelete` (Clarification 1) |
| `title` | required, ≤150 chars (validated in store/update) |
| `description` nullable | free text |
| `starts_at` | required, not in the past on store |
| `ends_at` nullable | must be after `starts_at` when given; when absent, `effectiveEndsAt()` = `starts_at + 90 min` (`DEFAULT_DURATION_MINUTES`) |
| `status` | string default `scheduled`; `App\Enums\LiveClassStatus`: `scheduled → live → ended`, `cancelled` terminal |
| `jitsi_room_name` | **server-generated only** (below), 64 chars |
| timestamps | |

Indexes: `UNIQUE(tenant_id, id)` (`tenantKeys()`), `UNIQUE(tenant_id, jitsi_room_name)`,
`(tenant_id, course_id, starts_at)` (course page), `(tenant_id, status, starts_at)`
(dashboard).

**The lesson FK is `restrictOnDelete` via the macro, not `nullOnDelete`** — this was a
MySQL-only fix during Step 2 (the local MariaDB/MySQL suite caught it as `errno 150`:
`ON DELETE SET NULL` requires *every* FK column to be nullable, and the composite FK's
`tenant_id` never is; that's exactly why the codebase's macro hardcodes
`restrictOnDelete`). Consequence, documented rather than hidden: a lesson with a linked
live class must be unlinked (or the class deleted) before the lesson can be deleted, and
`Manage\LessonController::destroy` now turns that FK refusal (SQLSTATE 23000) into a
redirect back to the lesson edit page with a friendly `live_classes.lesson_in_use` error
instead of a 500. Same rule as courses and creators; no session record can vanish
silently.

**Room-name generation** runs in `LiveClass::booted()`'s `creating` hook (after
`BelongsToTenant`'s own hook, so `tenant_id` is already set) and only when the column is
empty: 32 random letters (`A–Za–z`, ~181 bits via `random_int`) + `-` + 8 characters
derived from `sha256(tenant_id)` mapped into `[a-f]`. The result is **digit-free by
design** — no numeric id (class, course, tenant) can ever appear inside a room name or be
read back out of one — while still giving every tenant a visually distinct suffix. The
column is not in `$fillable`; hostile posted values (`jitsi_room_name=client-supplied-room`)
are ignored, and the unique key makes a forced duplicate loud.

### `live_class_attendance` (tenant-owned)

One row per **stay**: `tenant_id`, `live_class_id` (composite FK → `live_classes`,
`cascadeOnDelete` — a deleted class takes its attendance, nothing else references these
rows), `user_id` (composite FK → `users`, `restrictOnDelete`), `joined_at`,
`last_seen_at` (both NOT NULL), `left_at` nullable (open stay), `duration_seconds`
unsigned int default 0, timestamps.

Indexes: `UNIQUE(tenant_id, id)`, `UNIQUE(tenant_id, live_class_id, user_id, joined_at)`
— named **`live_class_attendance_stay_unique`** explicitly, because the auto-generated
name is 74 chars and MySQL caps identifiers at 64 (`errno 1059`, caught by the MySQL
gate; the schema test matches indexes by columns, never by name) — plus
`(tenant_id, live_class_id, user_id)` for the report. The unique key lets the same
student rejoin after a gap (a second row at a second instant) while making an
accidental double-write at the same instant loud.

Every attendance column is written through `forceFill()`; none of them is mass-assignable
(asserted).

## Join flow (server-minted URL and JWT)

1. `GET /live-classes/{liveClass}` (student area) — 404 if the flag is off, `view` policy
   (staff/owner, or a valid enrolment in the class's course), then renders countdown /
   Join / live badge from the same window config the policy reads, so the button and the
   gate can never disagree. The page **never** contains the Jitsi URL or the room name
   (asserted).
2. `GET /live-classes/{liveClass}/join` — flag gate, then **authorise first** (a stranger
   gets a flat 403 whether or not the class is joinable, and never a flash explaining the
   schedule), then the refusal check: `cancelled` / `already_ended` / `not_yet_open`
   redirect back to the show page with an `errors['live_class']` flash; open windows
   continue.
3. Attendance opens (or resumes the open row if its `last_seen_at` is ≤60 s old — a
   refresh is the same session; a longer gap starts a second row).
4. `JitsiJwt::make()` mints an HS256 JWT: `iss`/`aud` = app id, `iat`, `exp` = +120 min
   (`TTL_MINUTES` — long enough for a full class, short enough to be useless later),
   `context.user.{name,email}`; base64url, no padding, so a hostile display name
   round-trips byte-for-byte and can never break the query string.
5. `JitsiJoinUrl::build()` → `https://8x8.vc/{rawurlencoded app id}/{rawurlencoded
   room}?jwt={raw JWT}` and `redirect()->away()`.

The app secret never appears in a response body, a redirect, or captured logs (asserted);
the JWT is signed and consumed within one request — no token endpoint, nothing to replay
against our own app.

## Why 15 minutes (Clarification 3)

`coaching.live_class_join_window_minutes` (env `LIVE_CLASSES_JOIN_WINDOW_MINUTES`,
default **15**, single source of truth read by both the policy and the show page):

- **Classes start late in real life.** A teacher opens the room, fumbles with screen
  share, waits for latecomers — the join gate is deliberately more generous than the
  schedule so nobody is locked out for being *early* or for the host being *slightly*
  behind. Fifteen minutes is the difference between "I joined before the bell" and "the
  door was already shut".
- **Early is harmless, late is enforced elsewhere.** Opening the room early costs nothing:
  Jitsi rooms don't "start" — nobody is admitted past `ends_at` anyway because
  `live-classes:update-status` flips the class to `ended` and the gate reads `status`.
- **It is a window, not a timestamp.** The same 15 minutes appears in the policy
  (`join`), the show page's countdown-to-Join logic, and the heartbeat authorisation, all
  reading the one config key — change the env var and every surface moves together
  (there is a test for the boundary on both sides: not joinable 16 minutes out, joinable
  inside the window).
- **15 was chosen, not defaulted.** Long enough to absorb a late host and a class-link
  forward on WhatsApp; short enough that a stale link doesn't look "open" for most of an
  hour before the scheduled start. Nothing in Jitsi imposes it — it is purely our
  door policy.

## Attendance and heartbeats

`POST /live-classes/{liveClass}/heartbeat` — **the request body is ignored outright**:

- **Policy before throttle.** Authorisation (`join`) runs first, so every refusal —
  not enrolled, cancelled, ended, still outside the window — is a flat **403**, and only
  an authorised student can consume the rate-limit budget. The third beat inside a
  60-second window is a flat **429** (policy `RateLimiter`, key
  `live-class-heartbeat:{tenant}:{user}:{class}`, 2 attempts / 60 s decay —
  `live_class_heartbeat_max_attempts` / `live_class_heartbeat_decay_seconds`).
- **Server timestamps only.** `creditHeartbeat()` computes the elapsed wall-clock seconds
  since the row's own server-set `last_seen_at`, **capped at 60 per beat**, then advances
  `last_seen_at`. A client that posts a forged `joined_at`/`duration_seconds` (or none at
  all) changes nothing — asserted, including the "hammering cannot inflate duration
  beyond twice the real elapsed time" bound.
- **Open stays and gaps.** An open row (`left_at IS NULL`) resumes within 60 s; a longer
  gap opens a second row so the report can distinguish one long stay from two short ones.
- **The stale sweep** closes rows whose heartbeats went quiet for
  `live_class_stale_minutes` (default 5), setting `left_at = last_seen_at` — the last
  moment the server actually saw the student, never `now()`, so a stalled connection
  cannot inflate a stay.

## Scheduled commands (`routes/console.php`, every minute, `onOneServer()`)

- **`live-classes:update-status`** — the state machine: `scheduled → live` once
  `starts_at` has passed; `live → ended` once `effectiveEndsAt()` (or `ends_at`) has
  passed. `cancelled` and `ended` are never touched. Status is the single source of truth
  every gate reads — one place decides, one place can be audited. Runs as a system
  command: raw `DB::table` candidate scan across all tenants (the same documented
  exception `videos:sync` uses), then each row is re-entered through
  `TenantContext::runAs()` of its own tenant before the model layer sees it.
- **`live-classes:close-stale-attendance`** — the sweep above.

`php artisan schedule:list` on a clean checkout shows both on `* * * * *` next to
`videos:sync` (captured for this spec's report).

## Teacher report and CSV export

`GET /manage/courses/{course}/live-classes/{liveClass}/attendance` (flag gate, then
`manageLiveClasses` policy on the course):

- Every enrolled student: attendees sorted by `duration_seconds` desc (ties by
  `joined_at`), absentees (active enrolments with no attendance row) listed too —
  "n of N attended".
- `?min_minutes=N` filters attendees to stays ≥ N minutes **and hides the absentee list
  entirely** — a filtered view must not lie about who was absent from a filtered-out seat.
- **Bounded queries.** Attendance + users in one eager pair, absentees + users in one
  more, enrolled count in one: measured **9 queries for a 5-student tenant and 9 for a
  100-student tenant — growth 0**, under the asserted cap of 15 with room to spare
  (Clarification 4c's re-tune proved unnecessary; see "Step 1's [UNVERIFIED] markers"
  below).
- `GET …/attendance/export` streams a CSV (`streamDownload`, UTF-8 **BOM** so Excel
  renders names correctly) with headers `Student, Email, Joined at, Left at, Minutes`,
  every cell passed through `CsvFormulaGuard::escape()` — a student named
  `=HYPERLINK(…)` lands as inert text (asserted).

## Security posture

- Enrolment/staff gate re-derived server-side on **every** entry point (show, join,
  heartbeat, report) — never trusted from the client.
- Room name + URL leave the server only inside a 302 `Location` header.
- Cross-tenant: composite FKs make a cross-tenant `course_id`/`lesson_id`/`user_id`
  reference a `QueryException`, and the scoped `{liveClass}` binding resolves through
  `{course}` (`scopeBindings()`), so another tenant's class 404s before any policy runs.
- Mass assignment: `jitsi_room_name`, `status`, `tenant_id`, `created_by` on
  `live_classes` and every attendance timestamp column are ignored on `fill()`.
- Rate limit on heartbeats; `must.change.password` users are bounced to the password form
  before joining; guests land on the tenant login page.
- `LIVE_CLASSES_ENABLED=false` means 404 everywhere — no route names leak existence.

## Required tests

79 tests in `tests/Feature/LiveClasses/` (11 files) + `tests/Unit/Support/LiveClasses/`
(2 files), green on both SQLite and MySQL:

1. **Scheduling** (`LiveClassSchedulingTest`): server-owned fields come out server-derived
   (including hostile posted `jitsi_room_name`/`status`/`created_by`/`tenant_id`);
   validation (title ≤150, `starts_at` required/not past, `ends_at` after `starts_at`,
   lesson must belong to the course); staff-with-permission yes, student no; room names
   unpredictable and id/title-free (12 distinct, both regex and containment tests); edit
   rules incl. cancelled-not-editable; delete = owner yes, student no; index 403s students.
2. **Schema** (`LiveClassSchemaTest`): every column on both tables; the four index/uniqueness
   contracts; five cross-tenant negative cases raising `QueryException`; two mass-assignment
   negatives; `created_by` nullable.
3. **Join** (`LiveClassJoinTest`): the happy 302 + opened attendance row; window boundaries
   on both sides; ended/cancelled refusals; owner joins without enrolment; guest → login;
   must-change-password bounce; the page never exposes URL or room name.
4. **Attendance** (`LiveClassAttendanceTest`): first beat credits elapsed seconds; ≤60 s per
   beat; 2/min/user/class → 429; ignored client timestamps; hammering bound; rejoin/gap
   rows; stale-sweep command; not-enrolled and not-open refusals are 403 (policy-before-
   throttle ordering is load-bearing here).
5. **Status** (`LiveClassStatusTest`): stays scheduled until start; scheduled→live;
   live→ended; no `ends_at` = 90-minute default; cancelled never transitions; both commands
   scheduled every minute.
6. **Report** (`LiveClassReportTest`): full roster with absentees; student 403s, staff OK;
   `?min_minutes` filter; formula-guarded CSV; the N+1 bound (≤15 queries, ≤+2 growth).
7. **Security** (`LiveClassSecurityTest`): cross-tenant 404/403 on all three verbs; secret
   never in body/redirect/logs; hostile display name round-trip.
8. **Student UI** (`LiveClassStudentUiTest`): lang file + every key resolves; dashboard
   live/starting cards and their absence when off; course-page section; join/countdown/
   ended page states; attended indicator.
9. **Preflight / flag** (`LiveClassPreflightTest`): production without keys fails, with keys
   passes, flag-off needs nothing — all three asserted with `Jitsi` in the output.
10. **Recording** (`LiveClassRecordingTest`): disclosure off by default, renders only when
    enabled (Decision B).
11. **Mobile** (`LiveClassMobileGuardTest`): class page and report pass the mobile-safety
    checker.
12. **Units**: JWT (claims, HMAC, hostile name, URL-safety) and join URL (shape, encoding).

## Files changed

**Migrations:** `2026_10_09_000001_create_live_classes_table.php`,
`2026_10_09_000002_create_live_class_attendance_table.php`.

**Backend:** `app/Enums/LiveClassStatus.php`, `app/Models/{LiveClass,LiveClassAttendance}.php`,
`app/Support/LiveClasses/{JitsiJwt,JitsiJoinUrl}.php`,
`app/Policies/{LiveClassPolicy,CoursePolicy (manageLiveClasses)}.php`,
`app/Http/Controllers/LiveClassController.php` (show/join/heartbeat),
`app/Http/Controllers/Manage/{LiveClassController,LiveClassAttendanceController}.php`,
`app/Console/Commands/{LiveClassesUpdateStatusCommand,LiveClassesCloseStaleAttendanceCommand}.php`.

**Modified existing:** `app/Providers/AppServiceProvider.php` (policy registration),
`app/Models/Course.php` (`liveClasses()` relation), `app/Http/Controllers/{DashboardController,CourseController}.php`
(live sections, flag-gated, student branch only), `app/Http/Controllers/Manage/LessonController.php`
(FK-refusal guard on `destroy`, see schema note),
`app/Console/Commands/AppPreflightCommand.php` (`checkJitsiConfig` + success line),
`routes/{web,console}.php`, `config/{coaching,services}.php`, `.env.example`,
`lang/en/live_classes.php`, `resources/views/live-classes/show.blade.php`,
`resources/views/manage/live-classes/{index,create,edit,attendance}.blade.php`,
`resources/views/{dashboard/student,courses/show}.blade.php`.

**Docs:** `LIVE_CLASSES.md` (new, repo root), this file, `README.md`, `ROADMAP.md`,
`SECURITY.md`, `AGENT_RULES.md` (invariant 21), `docs/DEPLOY.md`,
`docs/DEPLOY_RUNBOOK.md`, `PROJECT_BRIEF.md`.

**Tests:** Step 1 committed the 11+2 files and fixtures (`1177a9c`, clarifications in
`5bd533c`); Step 2's only test-file edits are the two scaffolding corrections below.

## Test corrections (scaffolding fixes, assertions byte-identical)

The process anticipated this category: tests written before the code occasionally encode
an assumption the running framework doesn't honour. Two such fixes were applied to
already-committed Step-1 files, **neither touching a single assertion** — every
`expect()` line is byte-for-byte what Step 1 wrote:

1. **`tests/Feature/LiveClasses/LiveClassSchemaTest.php`** — the mass-assignment test
   called `$row->update(...)` bare. The locked `BelongsToTenant` saving hook throws when
   no tenant context is set, and Laravel's `save()` fires `saving` unconditionally
   (`Model.php:1411`), so the row could never save outside a context. The existing block
   is now wrapped in `inTenant($f['tenant'], …)` like every other save in the suite; the
   test's point (`fill()`'s guarding) is unchanged, and the five assertions are identical,
   just indented into the closure.
2. **`tests/Support/LiveClassFixtures.php`** — `attend()` defaulted `left_at` to `now()`,
   i.e. every fixture stay arrived already closed, while the test that reads it asserts
   `toBeNull()` (an open stay, which is exactly what the join path writes) and the report
   fixture passes `left_at` explicitly when it wants a closed one. Default changed to
   `null`; no assertion changed.

Both were reported here rather than silently applied, per the Step-1 contract.

## Step 1's three [UNVERIFIED] markers — resolved

1. **`schedule:list` regex (a)** — resolved by pasting the real output: both
   `live-classes:update-status` and `live-classes:close-stale-attendance` appear on
   `* * * * *` (alongside `videos:sync`), captured from `php artisan schedule:list` on a
   clean checkout; the schedule test passes on SQLite *and* MySQL.
2. **`PendingCommand` ordering (b)** — resolved by reading the framework: `PendingCommand::run()`
   asserts the expected exit code *before* `verifyExpectations()`, so the preflight test's
   `expectsExit(1)`-then-`expectsOutputToContain('Jitsi')` ordering is sound; the test
   passes in both directions (keys missing → 1, keys present → 0).
3. **N+1 bound of 15 queries / +2 growth (c)** — measured, not assumed: **9 queries at 5
   students, 9 at 100 (growth 0)**. The bound stands as written; no re-tune needed.

## Assumptions

- JaaS join URL shape `https://8x8.vc/{app_id}/{room}?jwt=…` follows 8x8's public
  "meet.jit.si/{app_id}/{room}" convention for branded deployments; verified against the
  JaaS docs reachable at spec time but not against a real tenant's app id (no JaaS
  account exists yet — see Unresolved risks).
- A course-level class (no lesson) is a first-class case, not a degradation — hence
  `lesson_id` nullable end-to-end and the course page's section listing.
- Dashboard/course queries only run inside the student branch and only when the student
  has enrolments, so the flag's "renders nothing" guarantee costs zero queries for staff.
- `effectiveEndsAt()`'s 90-minute default (`coaching.live_class_default_duration_minutes`)
  is a product choice, not a Jitsi constraint — Jitsi free-tier rooms have no hard cap
  shorter than that.
- The report's `min_minutes` filter operates on `duration_seconds` (server-credited time),
  never on wall-clock stay length — a student who joined and closed the tab is credited
  only the seconds their heartbeats survived.

## Unresolved risks

- **No real JaaS app id has been exercised end-to-end.** The JWT claims and URL shape are
  unit-tested against the documented contract, but the first deployment with real
  `JITSI_APP_ID`/`JITSI_APP_SECRET` should open one room and confirm 8x8 accepts the
  token (and that `exp` slack of 120 min matches their expectations).
- **Heartbeat cadence depends on the student's browser.** The server caps credit at 60 s
  per beat and sweeps stale rows, but there is no browser-level test of the beacon JS
  itself (same status as Phase 5's upload UI — server endpoints are tested, the page JS
  is exercised manually).
- **Recording (Decision B) is notice-only.** Flipping `LIVE_CLASSES_RECORDING_ENABLED`
  renders the disclosure; actually starting/stopping a JaaS recording is out of scope
  until a paid plan exists.
- **Concurrent heartbeat writes** are protected by the unique stay key and the
  row-resume logic, but true concurrency isn't reproducible in a single-process test run
  (same standing caveat as Phase 5's `lockForUpdate()`).
