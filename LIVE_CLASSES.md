# Live classes

## Provider: 8x8 Jitsi as a Service (JaaS)

- **One JaaS app id + secret per deployment, in `.env`** (`JITSI_APP_ID`,
  `JITSI_APP_SECRET`, read via `config/services.php`) — Decision A, confirmed before
  implementation. Its only job is signing the short-lived join JWT server-side; nothing
  else in the app reads it, it is never rendered, never logged, never accepted from a
  client. No per-tenant JaaS configuration exists or is planned: room isolation comes from
  unguessable room names plus our own enrolment-gated redirect, not from per-tenant apps.
- `LIVE_CLASSES_ENABLED=false` is the default. With the flag off, every live-class route
  404s, the dashboard and course-page widgets render nothing, and `app:preflight` neither
  requires nor mentions Jitsi. With it on, preflight **fails** unless both keys are set —
  a deployment can't half-enable the feature.

## Scheduling

- A live class belongs to a **course**, optionally pinned to **one lesson** (`lesson_id`
  nullable both ways — a course-level class is first-class). Owner/staff schedule it from
  `/manage/courses/{course}/live-classes`; students never can.
- **The room name is generated, never supplied.** `LiveClass` builds it in a `creating`
  hook: 32 random letters + `-` + 8 characters derived from the tenant id, digit-free by
  design so no numeric id (class, course, tenant) can ever appear inside one — see
  `docs/specs/phase-12-live-classes.md` for the unpredictability contract and its tests.
- **Status is a state machine run by the scheduler, not by page views:**
  `scheduled → live` at `starts_at`, `live → ended` at `ends_at` (or start + 90 minutes
  when no end was given), `cancelled` terminal. `php artisan live-classes:update-status`
  runs every minute, so one place decides what "ended" means and one place can be audited.

## Joining

- **The join is server-minted end to end.** The class page never contains the Jitsi URL or
  the room name; hitting Join runs the policy (staff/owner, or a valid enrolment — re-derived
  server-side every time), then the server signs an HS256 JWT (`iss`/`aud` = app id, two-hour
  `exp`, the viewer's name/email under `context.user`) and 302s to
  `https://8x8.vc/{app id}/{room}?jwt=…`. The secret exists in exactly one place: the
  signing call.
- **Why 15 minutes** (`LIVE_CLASSES_JOIN_WINDOW_MINUTES`, one config key read by the
  policy, the countdown and the heartbeat gate, so the button and the gate can never
  disagree): classes start late in real life — the door opens early for people who are
  early, and closes for good when the scheduler flips the class to `ended`. Fifteen
  minutes is the difference between "joined before the bell" and "the door was already
  shut"; full rationale in `docs/specs/phase-12-live-classes.md` ("Why 15 minutes").
- Refusals are honest on the page (cancelled / not open yet / already ended, as a flash
  message on the class page) and flat **403** everywhere the client is code, never
  leaking the schedule to a stranger.

## Attendance

- **The heartbeat body is ignored outright.** Every timestamp and the credited duration
  are server-derived: elapsed wall-clock since the row's own server-set `last_seen_at`,
  **capped at 60 seconds per beat**, so a silent or absent tab is never billed as attended
  time it did not survive.
- Rate-limited to **2 beats per minute per user per class** (third → 429), with policy
  checked *before* the throttle so an unauthorised caller gets 403 and never consumes a
  student's budget.
- One row per stay: rejoin within 60 s resumes the open row (a refresh is the same
  session); a longer gap opens a second row so the report can tell one long stay from two
  short ones. `php artisan live-classes:close-stale-attendance` (every minute) closes rows
  quiet for 5 minutes, setting `left_at` to the row's own `last_seen_at` — the last moment
  the server actually saw the student, never `now()`.
- Tenancy is schema-level like everything else: composite FKs on both tables,
  `UNIQUE(tenant_id, jitsi_room_name)`, and attendance cascades when its class is deleted
  while a class can't be silently deleted out from under a lesson (restrict + a friendly
  lesson-page error instead of a 500).

## Report

- `/manage/courses/{course}/live-classes/{id}/attendance` lists **every enrolled student**
  — attendees by credited minutes, absentees included — with `?min_minutes=N` filtering the
  attendee list and hiding the absentee list (a filtered view must not lie about who was
  absent from a filtered-out seat). Bounded at 9 queries whether the tenant has five
  students or a hundred.
- CSV export streams with a UTF-8 BOM and every cell formula-guarded
  (`CsvFormulaGuard`), so a student named `=HYPERLINK(…)` lands as inert text.

## Recording — off by decision

- **Decision B:** JaaS recording is a paid add-on, so
  `LIVE_CLASSES_RECORDING_ENABLED=false` by default and the "this class may be recorded"
  notice renders only when it is on. Nothing else changes with the flag; starting or
  stopping a recording is out of scope until a paid plan exists.

See docs/specs/phase-12-live-classes.md for the full implementation.
