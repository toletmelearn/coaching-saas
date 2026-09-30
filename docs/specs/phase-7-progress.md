# Phase 7: Lesson Progress Tracking

**Status:** Complete. `composer test` (598/599, 1 pre-existing MySQL-only test skipped on
SQLite), `composer test:mysql` (567/567), `composer test:js` (29/29), `composer lint`, and
`composer analyse` all pass with zero regressions to Phases 1–6 / P1.

---

## Goal

Students see how far they've got in a course and can resume a video where they left off.
Teachers see who is keeping up and who has gone quiet. Out of scope (per the brief):
certificates, quizzes, grades, leaderboards, notifications/emails, analytics of anonymous
visitors, tracking of YouTube free previews, per-second watch heatmaps, one-device-per-student.

---

## Schema

New migration `2026_10_05_000001_create_lesson_progress_table.php` — new table only, no
edits to an already-applied migration.

```
lesson_progress
- id
- tenant_id
- lesson_id              composite FK -> lessons (tenant_id, id), cascadeOnDelete
- course_id              composite FK -> courses (tenant_id, id) — denormalised for fast course summaries
- user_id                composite FK -> users (tenant_id, id)
- watched_seconds        unsigned int default 0
- last_position_seconds  unsigned int default 0
- duration_seconds       unsigned int nullable
- completed_at           timestamp nullable
- completed_manually     bool default false
- last_heartbeat_at      timestamp nullable
- last_activity_at       timestamp nullable
- timestamps

tenantKeys(); unique(tenant_id, lesson_id, user_id);
index(tenant_id, course_id, user_id); index(tenant_id, course_id, last_activity_at)
```

`App\Models\LessonProgress` sets `$table = 'lesson_progress'` explicitly — Laravel's
pluraliser treats "progress" as uncountable, so the naming-convention default
(`lesson_progresss`) would be wrong.

**Fillable:** `lesson_id`, `course_id`, `user_id` only — everything else
(`tenant_id`, `watched_seconds`, `duration_seconds`, `last_position_seconds`,
`completed_at`, `completed_manually`, `last_heartbeat_at`, `last_activity_at`) is guarded,
set only via `forceFill()` from `ProgressRecorder`'s computed output. **Invariant:**
`course_id` must equal the lesson's own `course_id` — enforced in `LessonProgress::booted()`'s
`saving` hook (mirrors `Lesson`'s own chapter/course-match guard from Phase 4), throwing
`App\Exceptions\LessonProgressCourseMismatchException`. The composite FK independently
rejects a cross-tenant `course_id`/`lesson_id`/`user_id` at the DB level regardless of
model events (tested via a raw `DB::table()` insert, bypassing Eloquent entirely).

`database/factories/LessonProgressFactory.php` provides `forceFill()`-based states
(`completed()`, `manuallyCompleted()`, `watched($seconds, $duration)`,
`atPosition($seconds, $duration)`, `inactiveSince($days)`) for tests — mirroring
`LessonVideoFactory`'s existing pattern, since every field a test needs to set beyond the
three relation ids is guarded.

---

## `App\Support\ProgressRecorder` — pure accounting

A **pure class**: plain arrays in (`$current` state, `$input` heartbeat data), a plain
array out, plus an injected `$now` (`DateTimeInterface`) — no Eloquent, no ambient clock
access anywhere in the class. This is what keeps `tests/Unit/Support/Progress/
ProgressRecorderTest.php` fully database-free. The state array's keys match the
`lesson_progress` columns 1:1, so the controller can `forceFill()` the result straight
onto the model.

Rules (`applyHeartbeat()`):

- **Duration**: the first value seen is stored as-is; a later value is accepted only if
  it's within 10% of the currently-stored value, otherwise ignored (protects against one
  bad/racing heartbeat corrupting the completion threshold).
- **Accepted delta** = `min(played, secondsSinceLastHeartbeat + 5, 60)`; the very first
  heartbeat for a row is capped at 20s regardless of the claimed `played` value.
  `watched_seconds` only ever increases (`watched + delta`, never decreases) and is
  capped at `duration`.
- **`last_position_seconds`** = the heartbeat's `position`, clamped to `[0, duration]` —
  last-write-wins by server time, matching the brief exactly (a duplicate/out-of-order
  heartbeat can move the *position* backwards — that's fine, it never reduces
  *watched_seconds*, which is the completion signal).
- **Completion**: sets `completed_at` (and leaves `completed_manually = false`) the
  moment `watched_seconds >= 85% * duration`, and only if not already completed —
  sticky, never re-derived or cleared by a later heartbeat.
- **`last_activity_at`** only updates when the accepted delta is `> 0`.

`resumePosition(int $lastPositionSeconds, ?int $durationSeconds): int` — returns the
stored position when it's between 10 seconds and 95% of the duration, else `0`. Called
both server-side (`data-start-position` on the lesson page) and mirrored exactly in
`resources/js/progress-tracker.js`'s `resumePosition()` (same two constants, same rule),
tested independently in PHP and in `tests/js/progress-tracker.test.mjs`.

---

## Endpoints

`App\Http\Controllers\LessonProgressController`, registered inside the *existing*
`auth:tenant` -> `active.tenant.user` -> `must.change.password` group in `routes/web.php`
(the same stack `/dashboard` already uses) — **not** nested under `/manage`, since these
are student-facing routes:

- `POST /lessons/{lesson}/progress` (`heartbeat`) — body `position`, `duration`, `played`
  (all required numeric, bounded 0–86400 / 1–86400 / 0–120), `ended` (optional bool).
  Returns `{ watched_seconds, completed }`.
- `PUT /lessons/{lesson}/completion` (`completion`) — body `completed` (required bool).
  Refused (422) for a lesson with a ready protected video when `completed: true`
  (auto-complete only); refused (422) when `completed: false` on a row that isn't a
  *manual* completion (an auto-completed video lesson can't be unmarked here). A plain
  (non-XHR) form submission — the lesson page's "Mark as complete" button needs no JS —
  redirects back instead of returning JSON.

Both actions:

- Call `LessonAccess::isStaffOrOwner()` first — owner/staff get a `204` and nothing is
  stored (never re-implementing that check, always calling the existing service).
- Then call `LessonAccess::lessonAccess()` **and** `LessonAccess::hasValidEnrolment()` —
  `lessonAccess()` alone returns `'ok'` for a free-preview lesson even without an
  enrolment, but progress is only ever recorded for a genuinely, validly enrolled
  student (Product decision #1). `'not_found'` -> 404; anything else not `'ok'`, or no
  valid enrolment -> 403.
- Throttle at `config('coaching.progress_heartbeat_max_attempts')` (12) per minute, keyed
  by tenant + user + lesson id, independently for each action. `abort(429)` — Laravel's
  default exception rendering gives the friendly `errors/429.blade.php` page for HTML,
  plain JSON for a request that `expectsJson()`.
- Do the load-or-create + `lockForUpdate()` + `ProgressRecorder` call + save **inside one
  `DB::transaction()`**, so two concurrent heartbeats for the same
  `(tenant, lesson, user)` (two tabs/devices) never race each other's update.
- 404 for another tenant's lesson (implicit route-model binding, already tenant-scoped —
  no special handling needed) and for a draft lesson (`lessonAccess()` returns
  `'not_found'`).

`App\Http\Controllers\Manage\CourseProgressController` (`/manage/courses/{course}/
progress`, `/manage/courses/{course}/progress/{user}`), gated by a new `viewProgress`
ability on `CoursePolicy` (owner or staff — same `canManageCourses()` check every other
management-area ability already uses).

- **Index**: aggregates completed-lesson counts and `MAX(last_activity_at)` **in one SQL
  subquery** (`leftJoinSub`), grouped by student — never one query per student. Filters:
  `?filter=inactive_7d` (no activity, or last activity older than
  `config('coaching.inactive_days')`, default 7) and `?filter=not_started` (no
  `last_activity_at` at all). Sort: `?sort=progress` / `?sort=last_active`. Paginated at
  25/page (Laravel's default paginator). Counts every enrolment (active or revoked —
  shown as "Access ended"), matching the brief's "counts only students with an
  enrolment" rule. Verified bounded (< 15 queries regardless of student count) by a
  dedicated query-count test with 30 students.
- **Show** (per-student): every published lesson in course order
  (`Lesson::orderedPublishedForCourse()`) with its status (Completed / In progress X% /
  Not started).
- A student gets a `403` (the framework's standard `AuthorizationException` rendering)
  for either route.

---

## Browser behaviour

`resources/js/progress-tracker.js` — a **plain ESM module with zero DOM access**
(no `document`/`window` anywhere), imported directly by `tests/js/
progress-tracker.test.mjs` under Node and by `resources/js/lesson-progress.js` in the
browser:

- `createTracker({ onSend })` — converts player events into `played` deltas: ignores a
  backwards jump (never reports negative played time) and a forward gap over 3 seconds
  (a seek, not real watching); flushes (`onSend`) every 15s of accumulated played time,
  and on pause/ended/hidden/pagehide — but only when something actually happened since
  the last flush (a plain idle tick sends nothing, so a paused, untouched tab doesn't
  spam heartbeats). `.stop()` permanently disables all future sends — the tab that just
  got a 401/403/redirect from the server stops trying.
- `resumePosition()` — the identical 10s/95%-of-duration rule as
  `ProgressRecorder::resumePosition()` (client-side use: nothing here, since the server
  already computes `data-start-position`; kept for symmetry/documentation and covered by
  its own test).
- `parseTimeUpdatePayload()` — accepts Bunny's `player.js` `timeupdate` event data as
  either an object or a JSON string, returns `null` (never throws) for anything else.

`resources/js/lesson-progress.js` — a **separate Vite entry** (registered in
`vite.config.js`'s `input`, not bundled into `app.js`), loaded only from `resources/views/
lessons/show.blade.php` via `@push('scripts')`, and only when the page actually has a
recordable-student player wrapper (`@if ($isRecordableStudent && $videoPlayback)`) —
every other page, and every lesson page for a guest/owner/staff/non-video lesson, never
loads it at all. Self-guards again at runtime (`document.querySelector('[data-progress-
url]')`) as a second line of defence.

- Sends heartbeats via `fetch(url, { keepalive: true, headers: { 'X-CSRF-TOKEN': ... ,
  Accept: 'application/json' } })` — `keepalive` so a flush triggered by `pagehide`
  still completes after the tab starts closing. Failures are caught and silently
  ignored (retried at the next natural tick, never blocking playback).
- **`fake` driver**: wires the tracker to the `<video>` element's native `timeupdate`/
  `pause`/`ended` events; applies `data-start-position` via `video.currentTime` on
  `loadedmetadata`.
- **`bunny` driver**: `player.js` (npm dependency, pinned `0.1.0`) is loaded via a
  **dynamic `import()`** — only when `data-driver="bunny"`, never bundled into the main
  entry and never loaded from a CDN. Wires `playerjs.Player`'s `timeupdate`/`pause`/
  `ended` events (parsing `timeupdate`'s payload through `parseTimeUpdatePayload()`) and
  calls `player.setCurrentTime()` for resume once the player fires `ready`.
- `visibilitychange` -> `tracker.onHidden()`; `pagehide` -> `tracker.onPageHide()`.

The service worker (`resources/js/sw.js`) was **not modified** — it only ever intercepts
`GET` requests, so the heartbeat (`POST`) and completion (`PUT`) requests are untouched by
it regardless.

---

## UI

- **Lesson page** (`lessons/show.blade.php`): the video wrapper carries
  `data-progress-url`, `data-start-position`, `data-driver`, `data-completed` — rendered
  only when `$isRecordableStudent` (built by *calling* `LessonAccess::isStaffOrOwner()`
  and `hasValidEnrolment()`, never by re-deriving or changing the locked access rules) —
  never for owner/staff/guest. A "Resuming from X:XX" note appears above the player when
  `resumePosition() > 0`. A small "Completed" badge overlays the player once auto-
  completed. For any lesson *without* a ready protected video (notes-only, YouTube
  preview, "coming soon"), a plain HTML form (no JS required) submits `PUT .../
  completion` with a "Mark as complete" / "Completed ✓ — Mark as not complete" button.
- **Course page** (`courses/show.blade.php`): a ✓ beside each completed lesson and
  "X of Y lessons complete (Z%)" with a progress bar — computed in `CourseController::
  show()`, shown only for a viewer with a currently-valid enrolment (never owner/staff
  previewing, never a non-enrolled viewer of a free-preview lesson).
- **Student dashboard** (`dashboard/student.blade.php`): each active course shows a
  progress bar and a "Continue" link to the **first lesson the student can open that
  they haven't completed yet** (`DashboardController::show()` — the `access->
  lessonAccess() === 'ok'` check from Phase 4.5 is unchanged, just intersected with "not
  already completed"; with no progress at all this is identical to Phase 4.5's original
  "first openable lesson" behaviour, so its existing test still passes unmodified). Once
  every lesson in a course is completed, the link becomes "Course complete" pointing at
  the course page instead.
- **Teacher progress table** (`manage/courses/progress/index.blade.php`): student name +
  "Access ended" tag for a revoked enrolment, phone/email, enrolled-since date, "X of Y
  lessons" with a small bar, last-active ("3 days ago"/"Never"), filter/sort controls,
  pagination footer. **Per-student page** (`.../progress/show.blade.php`): every
  published lesson with Completed / In progress X% / Not started.

---

## Root-cause fix: central-domain 500 on a tenant-scoped bound route parameter

Discovered while testing the new `/lessons/{lesson}/progress` route: a request to any
`require.tenant`-protected route with an **implicit Eloquent route-model binding on a
tenant-scoped model** (e.g. `Lesson`, `Course`), hit on the **central domain**, threw an
uncaught `App\Exceptions\MissingTenantContextException` (500) instead of `require.tenant`'s
intended 404.

**Cause:** Laravel's default middleware *priority* list always runs
`Illuminate\Routing\Middleware\SubstituteBindings` (implicit route-model binding) before
`Illuminate\Auth\Middleware\Authenticate` — and, crucially, before *any* route-specific
middleware not itself in that priority list, regardless of how deeply nested the route's
`Route::middleware(...)->group()` calls are. `require.tenant` (`App\Http\Middleware\
RequireTenant`) was never in that list, so on the central domain — where `ResolveTenant`
deliberately leaves no tenant set — `SubstituteBindings` tried to resolve `{lesson}`
through `TenantScope` before `require.tenant` ever got a chance to 404 the request.

This is genuinely pre-existing architecture, not something Phase 7 introduced — no
earlier route happened to combine "reachable on the central domain" with "has a
tenant-scoped bound Eloquent parameter" in a way that exercised it.

**Fix** (`bootstrap/app.php`): `$middleware->prependToPriorityList(before:
SubstituteBindings::class, prepend: RequireTenant::class)` — pins `require.tenant`
immediately before `SubstituteBindings` in the priority list, so no tenant-scoped
route-model binding can ever run before the tenant is confirmed to exist. **Does not**
touch `TenantScope`, `TenantBuilder`, `BelongsToTenant`, `TenantUserProvider`,
`LessonAccess`, or the auth middleware themselves — an additive, narrowly-scoped
ordering fix. Verified with `tests/Feature/Tenancy/CentralDomainRouteBindingTest.php`
(a central-domain request to both an existing route, `/courses/{slug}`, and the new
`/lessons/{lesson}/progress`, each with a positive control on a real tenant domain), and
the full suite re-run to confirm zero regressions elsewhere.

A global `MissingTenantContextException` -> 404 exception-render mapping was considered
and rejected — it would silently turn any *other*, genuinely-unexpected occurrence of
that exception into an opaque 404 instead of surfacing as a loud 500, hiding real bugs
rather than fixing this one at its root.

---

## Files changed

**Migration:** `database/migrations/2026_10_05_000001_create_lesson_progress_table.php`.

**Backend:** `app/Exceptions/LessonProgressCourseMismatchException.php`,
`app/Models/LessonProgress.php`, `app/Support/ProgressRecorder.php`,
`database/factories/LessonProgressFactory.php`,
`app/Http/Controllers/LessonProgressController.php`,
`app/Http/Controllers/Manage/CourseProgressController.php`.

**Modified existing:** `app/Http/Controllers/{CourseController,DashboardController,
LessonController}.php` (progress summaries/data attributes — all built by calling
`LessonAccess`, never changing it), `app/Policies/CoursePolicy.php` (`viewProgress`
ability), `routes/web.php`, `config/coaching.php` (`progress_heartbeat_max_attempts`,
`progress_heartbeat_decay_seconds`, `inactive_days`), `bootstrap/app.php` (middleware
priority root-cause fix, see above).

**Frontend:** `resources/js/{progress-tracker,lesson-progress}.js`, `vite.config.js`
(new entry), `resources/views/layouts/app.blade.php` (`@stack('scripts')`),
`resources/views/lessons/show.blade.php`, `resources/views/courses/show.blade.php`,
`resources/views/dashboard/student.blade.php`, `resources/views/manage/courses/
progress/{index,show}.blade.php`, `package.json`/`package-lock.json` (`player.js`,
pinned exact `0.1.0`).

**Lang:** `lang/en/progress.php`.

**Docs:** `README.md`, `SECURITY.md`, this file.

**Tests:** `tests/Feature/Progress/*.php` (Step 1 commit, then corrected — see below),
`tests/Unit/Support/Progress/ProgressRecorderTest.php`,
`tests/Feature/Progress/LessonProgressFactoryTest.php` (new),
`tests/Feature/Tenancy/CentralDomainRouteBindingTest.php` (new — root-cause fix
regression test), `tests/js/progress-tracker.test.mjs`.

---

## Test corrections (Step 1 → Step 2, all pre-approved, none weaken what a test proves)

Same category as every earlier phase's precedent — bugs found once real code existed to
run the Step 1 tests against, each confirmed with the user before touching an
already-committed test file. `git diff --stat tests/` against the Step 1 commit
(`eac8513`) and a one-line before/after per changed test are in the Step 2 completion
report (not duplicated here to avoid drifting out of sync with the actual diff).

Categories, in brief:

1. An undefined-variable bug (missing `use ($tenant, ...)` in a closure) —
   `LessonProgressSchemaTest`.
2. A cross-tenant-course test split into two: a raw `DB::table()` insert proving the
   composite FK independently of model events (`QueryException`), and a separate model-
   path test proving `LessonProgressCourseMismatchException` (the model's own `saving`
   hook correctly fires first when both protections would otherwise apply) —
   `LessonProgressSchemaTest`.
3. Pest's `toThrow()` only recognises `class_exists()`, not `interface_exists()`, so
   `toThrow(Throwable::class)` silently degraded into a message-substring check — fixed
   to assert `LessonProgressCourseMismatchException::class` specifically —
   `LessonProgressSchemaTest`.
4. A first-heartbeat cap (20s) misunderstanding in my own test data, fixed while
   strengthening the test to prove both directions: a later heartbeat after real
   elapsed time *adds* to `watched_seconds`, and a duplicate/out-of-order heartbeat
   never reduces it — `ProgressRecorderTest`.
5. Several fixtures created `LessonProgress` rows via `::create()` passing fields that
   are (correctly, per the model's own guarded-fields schema test) not mass-assignable
   — silently dropped, so the fixtures didn't represent the state their test names
   claimed. Added `LessonProgressFactory` (`forceFill()`-based states, mirroring
   `LessonVideoFactory`'s established pattern) with its own test proving each state,
   and swapped every affected fixture — `ResumeAndLessonPageMarkupTest`, `StudentUiTest`,
   `TeacherProgressTest`, `CompletionEndpointTest`.
6. `EnsureActiveTenantUser` (`active.tenant.user`) always redirects to `/login`
   regardless of `Accept` — existing, locked-adjacent behaviour, not something Phase 7
   should special-case — fixed the disabled-student test's expected status —
   `HeartbeatEndpointTest`. Additionally hardened `progress-tracker.js`/`lesson-
   progress.js` so a 401/403/redirected response stops all future heartbeats from that
   page view, tested in `progress-tracker.test.mjs`.
7. A domain-collision fix from earlier in Step 2 used `Str::random()`, which can
   produce uppercase letters, breaking a case-sensitive redirect-URL assertion —
   lowercased — `HeartbeatEndpointTest`, `CompletionEndpointTest`, `TeacherProgressTest`.
8. A tenant-isolation test used `actingAs()` with a cross-tenant user, which bypasses
   the real tenant-scoped session/user-provider resolution entirely and can't happen via
   an actual login — rewritten to the pattern already established elsewhere in this
   codebase (`PlaybackAccessTest`): same actor, same domain, the *other* tenant's
   resource id — `TeacherProgressTest`.
9. The root-cause middleware-priority fix above (`bootstrap/app.php`) plus its
   regression test (`CentralDomainRouteBindingTest`, new).

Also, while fixing #5/#8 above, two further mechanical bugs were found and fixed under
the same "keep or strengthen what the test proves" rule: (a) `TeacherProgressTest`'s
"not started" filter test used student names "Not Started Student" and "Started
Student" — the latter is a literal substring of the former, so `assertDontSee('Started
Student')` could never pass regardless of whether filtering worked; renamed to "Active
Learner". (b) `ResumeAndLessonPageMarkupTest`'s guest test called `actingAs($student)`
then a plain `$this->get()` for "the guest" without resetting the test client's
resolved auth user first — `actingAs()` persists for the rest of the test method, not
just the next request, so the "guest" request was silently still authenticated as the
student; added the existing `freshRequestCycle()` helper (already established in
`tests/Pest.php`, used by earlier phases' equivalent tests) between the two requests.

---

## Assumptions

- **Route paths**: `/lessons/{lesson}/progress` and `/lessons/{lesson}/completion` are
  flat (not nested under `/courses/{course}`), exactly as specified in the brief —
  `{lesson}` is resolved directly via tenant-scoped implicit route-model binding, no
  `scopeBindings()` needed since there's no parent segment.
- **Teacher route paths** (`/manage/courses/{course}/progress[/{user}]`) follow Phase 4's
  established `/manage/...` convention; not fixed by the brief.
- **`inactive_days`** defaults to 7 (`config('coaching.inactive_days')`, overridable via
  `COACHING_INACTIVE_DAYS`), matching the brief's own "7+ days" wording for the filter
  label.
- **The "Mark as complete" control is a plain HTML form**, not JS/fetch-driven — the
  brief only specifies the student-facing behaviour ("taps Mark as complete"), not the
  transport; a plain form keeps it working without JS and needs no CSRF-header
  plumbing beyond Blade's own `@csrf`. `LessonProgressController::completion()` still
  returns JSON when the request `expectsJson()` (used by every completion test).
- **Duration tolerance (10%)** and the **first-heartbeat cap (20s)** are exactly as
  stated in the brief's accounting rules; no additional tolerance/caps were invented.

## Unresolved risks

- **Per-request nature of the teacher table's aggregation** — the `leftJoinSub` groups
  by `lesson_progress.user_id` at read time; fine at this phase's scale (bounded query
  count verified up to 30 students), but a very large cohort (many hundreds of students
  per course) would eventually want a materialized/cached summary rather than a live
  aggregate on every page load.
- **`player.js` (Bunny's embed protocol library)** is pinned at `0.1.0` (the only
  version published to npm as of this phase); its actual `timeupdate` payload shape
  should be reconfirmed against a real Bunny embed during the trial, per Phase 5's own
  flagged risk (still unresolved — no real Bunny library has been exercised in this
  codebase yet, `fake` driver only).
- **No browser-level test coverage** for `resources/js/lesson-progress.js`'s DOM wiring
  itself (event listener attachment, `fetch` call shape) — only its pure logic
  (`progress-tracker.js`) and the server-side endpoints it calls are tested, matching
  Phase 5's video-upload JS precedent.
