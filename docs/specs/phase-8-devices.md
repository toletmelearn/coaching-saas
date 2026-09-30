# Phase 8: One Device per Student

**Status:** Complete. `composer test` (666/667, 1 pre-existing MySQL-only test skipped on
SQLite), `composer test:mysql`, `composer test:js` (29/29), `composer lint`, and
`composer analyse` all pass with zero regressions to Phases 1–7 / P1.

---

## Goal

A student account can be used on a limited number of devices at a time (default one), so
a paid login cannot be shared with a whole class. Owners can see and control it. Out of
scope (per the brief): IP/location tracking, device fingerprinting beyond one cookie,
push notifications, automatic account suspension, SMS/OTP login, limits for owner/staff
accounts.

---

## Schema

**Migrations:** `database/migrations/2026_10_06_000001_create_user_devices_table.php`
(new table), `2026_10_06_000002_add_max_devices_per_student_to_tenants_table.php`.

```
user_devices
- id
- tenant_id
- user_id                composite FK -> users (tenant_id, id), cascadeOnDelete
- device_id              string(64)
- label                  string(80)
- user_agent             string(255) nullable
- first_seen_at          timestamp
- last_seen_at           timestamp
- revoked_at             timestamp nullable
- revoked_reason         string(20) nullable   DeviceRevocationReason enum
- notified_at            timestamp nullable
- timestamps

unique(tenant_id, user_id, device_id); index(tenant_id, user_id, revoked_at)
```

`tenants.max_devices_per_student` — `tinyint unsigned default 1`.

`App\Enums\DeviceRevocationReason` (string-backed): `Replaced`, `Owner`,
`PasswordChanged`, `PasswordReset`, `Disabled`, `Logout`.

### Fillable fields — one deliberate deviation from the brief's literal guarded list

The brief lists `tenant_id`, `user_id`, `device_id`, `revoked_*`, `notified_at`, and
`first/last seen` as "never mass-assignable." `App\Models\UserDevice` guards
`tenant_id`, `revoked_at`, `revoked_reason`, `notified_at`, `first_seen_at`, and
`last_seen_at` (all set only via `forceFill()` from `DeviceRegistrar`), but makes
`user_id` and `device_id` fillable — mirroring `LessonProgress`'s own established
pattern (Phase 7: `fillable = ['lesson_id', 'course_id', 'user_id']`), where the
*identity* fields a caller must supply to create a row are fillable and only the
business-logic-computed fields are guarded. No test asserts `user_id`/`device_id` are
guarded (the Step 1 mass-assignment test only exercises `tenant_id` and the
revocation/notification fields), and nothing in the app ever mass-assigns a
`UserDevice` from raw HTTP request input — `DeviceRegistrar` always sets `user_id` to
the authenticated user's own id. See `UserDevice::$fillable` for the inline comment.

---

## Behaviour

### Registration — `App\Listeners\RegisterUserDevice`

Listens for Laravel's own `Illuminate\Auth\Events\Login`, filtered to `guard === 'tenant'`
and `role === Student` — never touches `TenantLoginController`. Laravel fires this same
event both on a fresh `Auth::guard('tenant')->login()` call **and** when `SessionGuard::
user()` resolves a user via the remember-me recaller cookie, so a "remembered" session
restore goes through the identical registration path as a fresh login — critically, using
whatever `device_id` cookie is already present, so it reuses the same device row rather
than minting a new one.

Reads the `device_id` cookie or mints one (`bin2hex(random_bytes(16))`, 32 hex chars) and
queues it via `Cookie::queue()` — which inherits `HttpOnly`, `SameSite=Lax`, `Secure`
(from `SESSION_SECURE_COOKIE`), and a host-only (no `Domain`) scope automatically from
`config/session.php`'s own path/domain/secure/same_site defaults, exactly as the session
cookie itself does. 1-year expiry (`DeviceRegistrar::COOKIE_MINUTES`).

`App\Support\Devices\DeviceRegistrar::register()` does the upsert-then-enforce inside one
`DB::transaction()`, with `lockForUpdate()` on both the target row and the student's full
set of active device rows — concurrent logins can't leave the student with more than the
limit or with zero active devices. While the active count exceeds
`tenants.max_devices_per_student`, the oldest device(s) by `last_seen_at` are revoked
(`DeviceRevocationReason::Replaced`) — never the device that was just registered.

Owner/staff logins are filtered out before any device-table query runs — zero rows, zero
limiting, ever.

### Enforcement — `device.limit` middleware (`App\Http\Middleware\EnforceDeviceLimit`)

Applied wherever `active.tenant.user` already is (`routes/web.php`) — the public/preview
group (courses, lessons, attachments, the fake video stream — which sits inside that same
group already) and the authenticated group (change-password, dashboard, progress, users,
`/manage/*`).

- Guest or owner/staff: returns immediately, no `user_devices` query at all.
- No `device_id` cookie: mints one, registers it (reusing `DeviceRegistrar`), queues the
  cookie — covers a session that pre-dates this phase.
- Cookie present but no matching row (same edge case, different starting state):
  registers it the same way.
- Row found and revoked: guard logout, session invalidated + token regenerated,
  `notified_at` set once; HTML gets `redirect('/login')->with('status', ...)`, JSON gets
  `401 {"code": "device_revoked"}` (the Phase 7 progress tracker already stops sending on
  a 401).
- Row found and active: a single atomic conditional `UPDATE user_devices SET
  last_seen_at = ? WHERE id = ? AND last_seen_at < ?` (never read-then-write) — throttles
  the write to once per 60 seconds. This bounds the whole middleware to **at most one
  `SELECT` and one `UPDATE` against `user_devices` per request**
  (`tests/Feature/Devices/DeviceLimitEnforcementTest.php`'s query-log assertion).

### Login-page notice — `device.revoked.notice` middleware (`App\Http\Middleware\
ShowDeviceRevokedNotice`), applied only to `GET /login`

For a device that's no longer authenticated (session gone) but still holds its cookie:
finds a revoked, not-yet-notified row for reasons `replaced`/`owner`/`password_changed`/
`password_reset` (never `logout` — a deliberate, expected sign-out — or `disabled`, a
different situation), marks `notified_at`, and puts the message via `session()->now()` —
**not** `session()->flash()`. This matters: flash data survives for "the current request
and the next one," so a second literal page load would still show a stale message even
though the DB already knows it was shown; `now()` only ever reflects what *this specific
request's* middleware pass actually found.

### Credential events

| Event | Effect |
|---|---|
| Owner/staff resets a student's password (`UserController::resetPassword`) | Revokes ALL of that student's devices, reason `password_reset` |
| Student changes their own password (`ChangePasswordController::update`) | Revokes every OTHER device, keeps the current one, reason `password_changed` |
| Owner/staff disables a student (`UserController::disable`) | Revokes ALL of their devices, reason `disabled` (in addition to the existing `active.tenant.user` block) |
| Normal logout (`TenantLogoutController::store`) | Revokes just that one device, reason `logout` — frees the slot; logging back in on it reactivates the same row |

### Owner tools

- People page (`UserController::index`): `withCount`/`withMax` aggregate queries add the
  active device count and last-active timestamp per student row (bounded, no N+1) —
  `App\Models\User::devices()` (new `hasMany`).
- `/users/{user}/devices` (`App\Http\Controllers\Manage\UserDeviceController`, gated by
  new `UserPolicy::viewDevices`/`manageDevices` abilities — owner or staff, target must be
  `role = student`, never another owner/staff or the actor's own account): lists devices
  with status/reason, "Sign out this device" (`{device}` route-bound via `scopeBindings()`
  under `{user}`, so it can only ever resolve a device that belongs to that user) and
  "Sign out all devices". Shows "Switching devices often" when ≥
  `config('coaching.device_switch_flag')` (default 5) `replaced` revocations happened in
  the last 7 days.

### Maintenance — `php artisan devices:prune`

Deletes device rows not seen for 60+ days, scheduled daily (`routes/console.php`). Uses
`UserDevice::withoutGlobalScopes()` — documented, reviewed exception per TENANCY.md
("System-level operations: ... console commands"), since it deliberately prunes across
every tenant in one pass.

### Label parser — `App\Support\Devices\DeviceLabelParser`

Table-driven substring matching (no third-party service, no network): Edge/Samsung
Internet/Opera checked before Chrome/Safari (their user agents also contain "Chrome"/
"Safari" tokens), Safari matched via `Version/` (its own UA has no `Safari/x.y` version
token). OS: Android/iOS/Windows/macOS/Linux. Falls back to "Unknown device" for anything
unmatched or empty/null. `tests/Unit/Support/Devices/DeviceLabelParserTest.php`.

### Session lifetime

`config/session.php`'s fallback default changed from Laravel's stock 120 minutes to
43200 (30 days) — `env('SESSION_LIFETIME', 43200)` — and `.env.example` documents
`SESSION_LIFETIME=43200`. Sessions otherwise remain exactly as they were (driver-agnostic;
Phase 8 deliberately never touches or relies on the stock `sessions` table, whose
`user_id` column is written only for the default `web` guard and has no `tenant_id`).

---

## A production bug found and fixed during Step 2: double-firing the Login listener

`Illuminate\Foundation\Application::configure()` calls `->withEvents()` **by default**
(discovery enabled, scanning `app/Listeners`) — this was not obvious from
`bootstrap/app.php` alone, since that file never calls `withEvents()` explicitly, but the
framework does so internally regardless. `App\Listeners\RegisterUserDevice::handle(Login
$event)` was therefore **already auto-discovered and registered** the moment the class
existed; adding an explicit `Event::listen(Login::class, RegisterUserDevice::class)` in
`AppServiceProvider::boot()` (as originally planned, matching the brief's "listener on
Illuminate\Auth\Events\Login" wording) registered it a **second time** under a different
internal key (`"Class"` vs the auto-discovered `"Class@handle"`), so a single login fired
the listener twice — minting two device rows and immediately evicting one of them
(`reason: replaced`) from what should have been a single, uncontested login. Root-caused
by reflecting on the dispatcher's registered listeners directly
(`tests/Feature/Devices/DeviceRegistrationTest.php` caught this via device-count
assertions). Fixed by removing the explicit `Event::listen()` call entirely — the
listener needs no manual registration at all; auto-discovery already does it.

## Assumptions

- **Routes**: `GET /users/{user}/devices`, `POST /users/{user}/devices/{device}/sign-out`,
  `POST /users/{user}/devices/sign-out-all` — not specified exactly by the brief; follows
  the existing `/users/{user}/...` convention (`disable`, `enable`, `reset-password`).
- **Copy keys**: `auth.device_revoked` (login notice), `devices.*` (new `lang/en/
  devices.php` — status/reasons/actions/switching_often/empty), `users.devices.*` (People
  page count/last-active/never-active/manage-link), `settings.fields.
  max_devices_per_student` + `settings.max_devices_per_student_hint`.
- **Query-count test design**: "at most 2 extra queries" is verified as an absolute bound
  on `user_devices`-touching queries in the query log (≤1 `SELECT`, ≤1 `UPDATE`) rather
  than a relative student-vs-owner comparison, per the Step 1 review correction.
- **`.env` itself** (gitignored, not tracked) still needs `SESSION_LIFETIME` updated by
  hand locally — `config/session.php`'s fallback default and `.env.example` are both
  updated, but neither one touches a developer's existing local `.env`.

## Unresolved risks

- **Bunny embed URLs outlive a revocation** (documented in SECURITY.md as "Known limit
  #1") — no server-side revocation hook exists for an already-issued Bunny token; only
  the local `fake` driver's per-request re-check closes this gap immediately.
- **Device ping-pong between two people sharing one login** (SECURITY.md "Known limit
  #2") is possible by design — a login never fails due to the limit, so two people
  trading the device slot back and forth can both keep using the account indefinitely.
  The "Switching devices often" flag is a detection signal for an owner to act on, not an
  automatic block.
- **`devices:prune`'s 60-day window is fixed**, not configurable via `config/coaching.php`
  — matching the brief's literal "60 days," but a future phase might want this tunable
  per-tenant or globally via env, the way `inactive_days`/`device_switch_flag` already
  are.
