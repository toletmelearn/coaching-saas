# Phase 3: Tenant Users & Authentication

**Status:** Complete
**Phase Goal:** The first tenant-owned product model (`User`), tenant-scoped authentication,
role-based user management, and platform-admin auth — all under `BelongsToTenant`.

---

## Overview

Phase 3 is the first tenant-owned domain model built on top of the Phase 1/2 tenancy
framework (`TenantContext`, `TenantScope`, `BelongsToTenant`, `TenantBuilder`). It adds:

- A `users` table extended with tenant scoping, role/status, and first-login password change.
- Two new auth guards (`tenant`, session-based via a tenant-aware provider) alongside the
  existing `platform_admin` guard.
- Login, logout, and forced-password-change flows for tenant users.
- Login and rate limiting for platform admins.
- Role-based user management (list, create, view, edit, disable/enable, reset password)
  under a `UserPolicy`.
- Minimal Blade screens, all copy routed through `lang/en/*.php` (no hardcoded strings).
- A demo tenant + owner account seeded for local development.

No separate brief document existed for this phase when implementation started (see history
below); the test suite in `tests/Feature/Auth` and `tests/Feature/Users`, refined over two
correction passes, is the actual contract this phase was built against.

---

## Schema

**Migration:** `database/migrations/2026_09_28_000001_add_tenant_auth_columns_to_users_table.php`

Extends the stock `users` table (rather than a separate migration creating `users` fresh,
since `users` is created before `tenants` exists in migration order):

- `tenant_id` — `foreignId` → `tenants.id`, `restrictOnDelete()`, **not nullable**. Composite
  `unique(tenant_id, id)` via the `tenantKeys()` macro (Phase 2), matching every other
  tenant-owned parent table.
- `email` — changed to **nullable** (was `unique()` globally; now `unique(tenant_id, email)`,
  so the same email may exist across different tenants but not twice within one).
- `phone` — new, nullable, `unique(tenant_id, phone)`.
- `role` — string, default `student`. One of `owner` / `staff` / `student`. Not a DB enum
  (kept as a plain string column so app-level role expansion doesn't need a migration).
- `status` — string, default `active`. One of `active` / `disabled`.
- `must_change_password` — boolean, default `false`.
- `last_login_at` — nullable timestamp.
- **CHECK constraint: `email IS NOT NULL OR phone IS NOT NULL`.** Laravel's schema builder
  has no cross-database CHECK constraint API. Implemented per-driver in the migration:
  - MySQL: a native `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)`.
  - SQLite: SQLite cannot add a CHECK constraint via `ALTER TABLE` (only at `CREATE TABLE`
    time), but *can* add triggers at any time — so it's emulated with `BEFORE INSERT` and
    `BEFORE UPDATE` triggers that `RAISE(ABORT, ...)` on violation. Functionally equivalent:
    a real `QueryException` from the database, not an application-level check.

## User model

`app/Models/User.php`:

- Uses `BelongsToTenant` (Phase 2 trait) — every query is tenant-scoped, `tenant_id`
  auto-fills on create, is immutable after, and `MissingTenantContextException` is thrown if
  no tenant context is set.
- `#[Fillable(['name', 'email', 'phone', 'password'])]` — `role`, `status`, `tenant_id`, and
  `must_change_password` are **not** mass-assignable. They're set via `forceFill()` (in
  `UserFactory`'s named states for tests, and in `UserController`/`ChangePasswordController`
  for the app) — never accepted directly from request input.
- `protected $attributes = ['role' => 'student', 'status' => 'active', 'must_change_password'
  => false]` — model-level defaults, matching the DB column defaults.
- A `saving` hook lowercases `email` and normalizes `phone` (via `User::normalizePhone()`) —
  strips a leading `+91`/`91` country code or a leading trunk `0`, and all non-digit
  characters, down to a canonical 10-digit form.

## Guards and the tenant-aware user provider

- New `tenant` guard (`config/auth.php`), session driver, backed by a new `tenant_users`
  provider using a custom driver `tenant_eloquent` → `App\Auth\TenantUserProvider`
  (registered in `AppServiceProvider::boot()`).
- `TenantUserProvider` extends `EloquentUserProvider` and only adds one thing: it catches
  `MissingTenantContextException` from each retrieve method and returns `null` instead of
  letting it propagate. Everything else — the actual tenant scoping of *which* user a given
  id resolves to — comes for free from `User`'s `BelongsToTenant` global scope. This is what
  makes "a session id from tenant A can never resolve a user under tenant B's hostname" hold
  (TENANCY.md / SECURITY.md's session-replay invariant): the scope itself prevents
  cross-tenant resolution; the provider only prevents that from turning into an application
  error when there's no tenant context at all (e.g. a request to the central domain).

## TenantContext reset at request boundaries (infrastructure fix, not Phase-3-specific)

`TenantContext` is a `scoped()` container binding — meant to reset automatically at a
request boundary. That reset previously only happened inside `TenantContext::runAs()`'s
`finally` block; nothing reset it at the top-level HTTP request boundary itself. In
production (one process per request) this didn't matter. It broke as soon as Phase 3 added
real routes/controllers that multiple requests could hit within one test method (and would
equally break under Octane, where the container persists across requests).

Fixed in `ResolveTenant` middleware: `TenantContext::reset()` is now called both at the very
start of `handle()` and in a `finally` block at the end, bracketing the context tightly to
one request. See `TenantContext::reset()` and the new note in TENANCY.md ("TenantContext
reset at request boundaries") about the consequence for `afterResponse()`/terminable
middleware. `TenantScope`, `TenantBuilder`, and `BelongsToTenant` itself (the three
explicitly locked Phase 2 files) were not touched.

A related, test-only gotcha (not fixed in application code, since there's no clean
application-level fix — see "Decisions made during Step 2" below): Laravel's `SessionGuard`
caches its resolved user in memory for the life of the guard instance, and the test HTTP
client reuses guard instances across simulated requests within one test. `tests/Pest.php`
adds a `freshRequestCycle()` helper (`app('auth')->forgetGuards()`) that tests call between
two simulated requests when the second one must resolve auth fresh from the session.

## Routing

`routes/web.php`:

- **Tenant routes** (`/login`, `/logout`, `/auth/change-password`, `/dashboard`, `/users/*`)
  sit behind `require.tenant` (404s on the central domain) then `auth:tenant`, then
  `active.tenant.user` (logs out and redirects to `/login` if the authenticated user's
  `status` is no longer `active` — this is what makes disabling a logged-in user take effect
  on their very next request), then `must.change.password` (redirects to
  `/auth/change-password` if the flag is set) for everything except the change-password
  routes themselves.
- **Platform admin routes** (`/admin/login`, `/admin/dashboard`, `/admin/logout`) are
  registered via `Route::domain($centralDomain)` for each configured central domain
  (`config('tenancy.central_domains')`) — routing-level restriction, not just an in-controller
  check. `POST /admin/login` is *also* registered once more with no domain restriction, as a
  fallback: a tenant-subdomain attempt still needs a real response (a session validation
  error, per the test contract) rather than a bare 404, and the domain-restricted
  registration always wins when the host genuinely is a central domain.
- Unauthenticated redirects are guard-aware without route-name collisions: `bootstrap/app.php`
  uses `$middleware->redirectGuestsTo(fn ($request) => $request->is('admin/*') ? '/admin/login'
  : '/login')`.

## Role-based user management (`UserPolicy`)

| Ability | Rule |
|---|---|
| `viewAny` / `create` | owner or staff |
| `createWithRole($role)` | owner: any role. staff: only `student` (posting `role=owner`/`role=staff` as staff is **403, and no user is created** — not silently downgraded to student; decision A below) |
| `view` | owner/staff: any user in tenant. anyone: self |
| `update` | owner: any user. anyone: self (name only) |
| `changeRole` | blocks demoting the last active owner (`role=owner`, `status=active`, count ≤ 1) |
| `disable` | owner only; blocks disabling the last active owner |
| `enable` | owner only; **not** subject to the last-owner rule (re-activating only ever increases the active-owner count) |
| `resetPassword` | owner: anyone. staff: students only |

**Temporary passwords.** Owner/staff creating a user never supply a password. `UserController
::store()` generates one (`Str::password(12)`), flashes it once to the redirect under the
session key **`temporary_password`**, and sets `must_change_password = true`. The created
user logs in with that password and is redirected straight to `/auth/change-password`.

**Contact requirement.** `email IS NOT NULL OR phone IS NOT NULL` is enforced twice: the DB
CHECK constraint/trigger (model level — see Schema above), and at the HTTP layer in
`UserController::store()`, which throws a `ValidationException` on both `email` and `phone`
when neither is present, before any user is created.

---

## Decisions made during Step 1 (test-writing) that shaped the implementation

These were made explicit mid-way through Step 1 (test correction) and are binding on Step 2
(this implementation), not just the tests:

- **A. Silent stripping vs. rejection on mass-assignment via the create-user HTTP endpoint.**
  `tenant_id`, `status`, and `must_change_password` posted in the create-user request are
  silently stripped/overridden (the created user gets the correct value regardless of what
  was posted). A **privileged `role`** posted by staff (`owner` or `staff`) is different: the
  whole request is rejected with **403, and no user is created** — not silently downgraded.
- **B. Mass-assignment defaults.** `User::$attributes` declares `role => student`,
  `status => active`. Confirmed `AppServiceProvider` has no `Model::shouldBeStrict()` /
  `preventSilentlyDiscardingAttributes()`, so a guarded field posted via raw mass assignment
  is silently discarded (no exception) and the attribute falls back to this model default —
  this is what the mass-assignment tests in `UserSchemaTest` assert.
- **C. "At least one of email/phone."** Enforced at two levels, kept separately tested: the
  model/DB-level CHECK constraint (`UserSchemaTest`), and an HTTP-level validation error on
  both fields with no user created (`UserManagementTest`).
- **D. Temporary password generation.** See "Role-based user management" above. The flash key
  is fixed as `temporary_password`.

## Decisions made during Step 2 (implementation) — test corrections, approved case by case

Five tests from Step 1 turned out to have bugs once real routes/controllers existed to run
them against (not implementation bugs) — each was reported with a proposed fix before being
changed, per the "tests are the contract" rule:

1. **`PlatformAdminGuardTest`, 4 tests** — a positive-control `actingAs($otherActor,
   $otherGuard)` left that guard permanently authenticated for the rest of the test (Laravel's
   `actingAs()` sets the guard's user directly; nothing else clears it). Fixed by calling
   `freshRequestCycle()` between the positive control and the negative assertion.
2. **`SessionIsolationTest`, 4 tests** — expected the `tenant` guard to re-resolve from
   session fresh on a second simulated request; `SessionGuard` caches its resolved user
   per-instance for the guard's lifetime, and the test client reuses guard instances across
   simulated requests in one test (a known Octane-style testing gotcha, not an app bug — real
   production gets a fresh process, hence a fresh guard, per request). Fixed with the same
   `freshRequestCycle()` helper — proposed as `app('auth')->forgetGuards()` and adopted
   verbatim rather than splitting the tests (splitting would make the cross-tenant half
   vacuous: a request carrying no session for tenant A would trivially "pass" even if
   isolation were broken).
3. **`TenantLoginTest`, "login with email succeeds..."** — queried `User::where(...)` directly
   outside `inTenant()`, right after an HTTP request. Once `TenantContext` correctly resets
   after each request (see above), this legitimately needs a tenant context. Wrapped in
   `inTenant()`.
4. **`TenantLoginTest`, "wrong password and unknown identifier..."** — called
   `$response->session()`, which is `protected` on `TestResponse` (not part of its public
   API), so it silently fell through to `__call()` forwarding onto the raw `RedirectResponse`,
   which doesn't have it either. Root cause traced further: even the correct read path
   (`app('session.store')->get('errors')`) returns a raw array until
   `assertSessionHasErrors()` is called first, then a `ViewErrorBag` — an internal Laravel
   session-store quirk under the `array` session driver, confirmed by direct experiment, not
   an application bug. Fixed by calling `assertSessionHasErrors()` before reading each
   response's message via `app('session.store')->get('errors')->first()`.
5. **`UserSchemaTest`, "all phone formats normalize..."** — created 3 users in the *same*
   tenant whose raw phone inputs all normalize to the same canonical number, directly
   conflicting with `unique(tenant_id, phone)` (the very constraint the preceding test in the
   same file checks). Fixed by giving each variant its own tenant.

Also approved in the same pass: rate limiting for platform admin login (key: normalized email
+ IP, 5/min, mirroring the tenant login limiter), the missing "re-enable a disabled user"
action (owner-only, not subject to the last-owner rule), and routing-level central-domain
restriction for `/admin/*` (see Routing above) — each with its own test.

---

## Files changed (Step 2 implementation)

Application code: `app/Models/User.php`, `app/Auth/TenantUserProvider.php`,
`app/Policies/UserPolicy.php`, `app/Http/Controllers/{DashboardController,
PlatformAdminDashboardController, UserController}.php`,
`app/Http/Controllers/Auth/{TenantLoginController, TenantLogoutController,
ChangePasswordController, PlatformAdminLoginController, PlatformAdminLogoutController}.php`,
`app/Http/Middleware/{RedirectIfMustChangePassword, EnsureActiveTenantUser}.php`,
`app/Providers/AppServiceProvider.php`, `config/auth.php`, `routes/web.php`,
`bootstrap/app.php`, `database/migrations/2026_09_28_000001_add_tenant_auth_columns_to_users_table.php`,
`database/seeders/{TenantSeeder,DatabaseSeeder}.php`, `resources/views/**`, `lang/en/**`.

Infrastructure fix (not locked Phase 2 files): `app/Support/TenantContext.php` (added
`reset()`), `app/Http/Middleware/ResolveTenant.php` (calls it at request start/end).

Documentation: `TENANCY.md` (TenantContext reset note), `SECURITY.md`
(`password_reset_tokens` not yet tenant-scoped), `README.md` (local credentials), this file.
`phpstan.neon`: removed the now-stale `trait.unused` ignore rule for `BelongsToTenant` (it's
genuinely used now).

Test changes: see `git diff --stat` in the Step 2 completion report for the exact diff against
the Step 1 commit — every change there is one of the 5 approved corrections above, plus 3 new
tests for new functionality (re-enable, admin rate limiting ×2) and the `freshRequestCycle()`
helper in `tests/Pest.php`.
