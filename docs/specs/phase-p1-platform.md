# Phase P1: Platform Home and Admin

**Status:** Complete (including the P1.1 cleanup pass). `composer test`, `composer
test:mysql`, `composer lint`, and `composer analyse` all pass with zero regressions to
Phases 1–5.

---

## Overview

Phase P1 replaces the placeholder platform home page with a real marketing landing page
(central domains only: `localhost`, `127.0.0.1`, `coaching.test`, `platform.coaching.test`
by default, per `config('tenancy.central_domains')`) and builds out the platform admin
area: a dashboard, an institutes list/detail with suspend/reactivate and owner-password
reset, and a demo-requests inbox. `demo_requests` is the first platform-level (non-tenant)
table in the app.

---

## A. Public landing page

`App\Http\Controllers\PlatformHomeController::show()` renders `platform.home` — hero, 6
feature blocks, a 3-step "how it works", a "Coming soon" pricing badge, a demo-request
form, and a footer. Fully server-rendered Blade, no JS framework, mobile-first at 360px
(the app's existing `min-w-[360px]` body class + the shared `<x-field>`/`<x-button>`
components already establish this). The header brand name comes from
`config('app.name', 'Coaching SaaS')` (so renaming the product later needs no template
changes), with a small "Admin login" link.

### Demo requests

`demo_requests` (migration `2026_10_02_000001_create_demo_requests_table.php`) is
deliberately **not** tenant-owned — a prospect submits this before any tenant exists, so
there is no `tenant_id`, no `BelongsToTenant`, no composite FK. Columns: `name`, `phone`
(normalized via the existing `User::normalizePhone()`), `email` (nullable), `institute_name`,
`city`, `message` (nullable), `status` (`DemoRequestStatus` enum: `new`/`contacted`),
`contacted_at`, `ip_address`. `status`/`contacted_at`/`ip_address` are guarded (set only via
`forceFill()`), matching every other tenant-owned model's mass-assignment convention even
though this one isn't tenant-owned.

`App\Http\Controllers\DemoRequestController::store()`:
- **Honeypot**: a `website` field, hidden via inline CSS (`position: absolute; left:
  -9999px`) and `tabindex="-1"`, that a real visitor never sees or fills but a
  script-fills-every-field bot will. A filled honeypot redirects to the same success state
  with nothing stored — no error, no signal for the bot to adapt to.
- **Rate limit**: 5/hour per IP (`RateLimiter` facade, same mechanism as the platform admin
  and tenant login limiters), checked before validation so even invalid submissions count
  toward it.
- **Route registration**: only inside the `foreach (central_domains)` loop — a tenant
  subdomain has no matching route at all, so it 404s the same way `/admin/login` already
  does on a tenant domain, with no extra in-controller check needed.

---

## B. Platform admin area

All routes: `auth:platform_admin` guard, central domains only, same `Route::domain()` loop
as the existing `/admin/login`.

- **`PlatformStats`** (`app/Support/PlatformStats.php`) is the single place cross-tenant
  counts are computed. Tenant-owned models (`User`, `Course`) throw
  `MissingTenantContextException` with no ambient tenant context by design (TENANCY.md) —
  correctly, since there's no single tenant to scope to here. `PlatformStats` uses
  `DB::table()` aggregate queries grouped by `tenant_id` instead (the one documented
  raw-SQL exception in TENANCY.md, for exactly this kind of system-level report), never
  `withoutGlobalScope()` on the models themselves. `Tenant` itself isn't tenant-owned, so
  plain Eloquent queries against it are fine as-is.
  - `dashboardCounts()`: active/suspended institute counts, total students, total courses,
    new demo requests.
  - `countsByTenant()`: per-institute student/course counts, two grouped queries (not
    N+1), keyed by `tenant_id`.
  - `ownerNamesByTenant()`: first owner's name per institute, one grouped query.
- **Institutes** (`App\Http\Controllers\Admin\InstituteController`): list (search by
  name/subdomain, paginated), create, detail (counts, owners, suspend/reactivate,
  reset-owner-password), all via plain `url()`-based redirects (see "Route names" below).
- **`App\Actions\CreateInstituteAction`** is the single source of truth for "create a
  tenant + its domain + an owner account" — used identically by
  `php artisan tenant:create` and the admin's create-institute form. Same validation
  (name/subdomain/owner required, subdomain format `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$`),
  same reserved-subdomain list, same duplicate-domain check, thrown as a
  `ValidationException` either way (the console command catches it and prints each
  message; the HTTP form lets it redirect back with errors, as usual).
- **Suspend / Reactivate**: `Tenant::$status` → `TenantStatus::Suspended`/`Active`. A new
  `App\Http\Middleware\CheckTenantSuspended`, **appended** to the `web` group (unlike
  `ResolveTenant`, which is *prepended* and runs before the session starts — this
  middleware needs a working session to log a suspended tenant's user out, so it must run
  after Laravel's own session middleware). No-op for central domains and for any
  non-suspended tenant; for a suspended one, logs out the `tenant` guard if authenticated,
  invalidates the session, and renders `errors.tenant-suspended` at HTTP 503 for
  **every** request on that domain, guest or not.
- **Reset owner password**: generates a new temporary password (same
  `TemporaryPasswordGenerator` as everywhere else), shown once via the same session-flash
  pattern as the tenant "People" page (`temporary_password` /
  `temporary_password_for`), and clears that owner's `LoginRateLimiter` lockout. An
  institute can have more than one `role=owner` user; the show page renders a plain button
  when there's exactly one, or an `owner_id` `<select>` when there are several — the
  controller uses the submitted `owner_id` (tenant-scoped via `TenantContext::runAs()`,
  so it can never target another tenant's user) when present, falling back to the single
  owner otherwise.
- **Demo requests** (`App\Http\Controllers\Admin\DemoRequestController`): paginated list,
  "Mark as contacted" (`status` → `Contacted`, `contacted_at` → `now()`).

### Route names (P1.1 fix)

The original Phase P1 cut gave several of these routes `->name(...)` (`admin.login`,
`admin.institutes.index`, etc.) inside the `foreach (central_domains as $centralDomain)`
loop. `Route::domain()` runs that same registration once **per** central domain, so a
shared route name ends up assigned to several different `Route` objects — this is
harmless for normal request matching and for `route:list` (both match by domain first),
but `php artisan route:cache` (part of `optimize`, part of every deploy per
docs/DEPLOY.md) serializes routes into one flat name-keyed collection and throws
`LogicException: Another route has already been assigned name [...]` the instant a second
domain tries to register the same name — meaning **every production deploy following
docs/DEPLOY.md would have failed** at `php artisan optimize`. Separately, since
`route($name)` resolves to whichever `Route` object last claimed that name in the lookup
table (the last-registered central domain, `platform.coaching.test` by config default),
every `redirect()->route('admin.institutes.show', ...)` call would have generated a URL
on *that* domain regardless of which central-domain alias the admin was actually using.

Fixed by removing every `->name(...)` in that loop and switching the controllers' six
`redirect()->route(...)` calls to plain `redirect(url('/admin/institutes/...'))`, matching
this codebase's own existing convention elsewhere (e.g. `Manage\LessonController` already
redirects by plain path, never a named route). Verified by a test that runs
`php artisan route:cache` for real (it would have failed before this fix) and a second
test asserting no `(domain, method, uri)` combination is ever registered twice.

---

## C. Local-only platform admin + `local:hosts`

`TenantSeeder` (local/testing only, same guard as the demo tenant/owner/student) also
creates `admin@coaching.test` / `password` as a `PlatformAdmin`. `php artisan app:preflight`
fails in production if that account exists (extends the existing `checkNoDemoData()`
check alongside the demo tenant/domain checks).

`php artisan local:hosts` (new in P1.1; local/testing only — refuses to run anywhere else,
since a real deploy has no local hosts file at all) prints a `127.0.0.1 <domain>` line for
every `tenant_domains` row plus every central domain other than `localhost`/`127.0.0.1`
(which already resolve without an entry), ready to paste into
`C:\Windows\System32\drivers\etc\hosts`.

---

## Files changed

**Backend:** `app/Actions/CreateInstituteAction.php`, `app/Enums/DemoRequestStatus.php`,
`app/Models/DemoRequest.php`, `database/factories/DemoRequestFactory.php`,
`database/migrations/2026_10_02_000001_create_demo_requests_table.php`,
`app/Support/PlatformStats.php`, `app/Http/Middleware/CheckTenantSuspended.php`,
`app/Http/Controllers/{PlatformHomeController,DemoRequestController}.php`,
`app/Http/Controllers/Admin/{InstituteController,DemoRequestController}.php`,
`app/Console/Commands/LocalHostsCommand.php` (P1.1).

**Modified existing:** `app/Console/Commands/{TenantCreateCommand,AppPreflightCommand}.php`,
`app/Http/Controllers/PlatformAdminDashboardController.php`,
`database/seeders/TenantSeeder.php`, `bootstrap/app.php` (`CheckTenantSuspended`
registration), `routes/web.php`.

**Views:** `resources/views/platform/home.blade.php`,
`resources/views/admin/dashboard.blade.php`,
`resources/views/admin/institutes/{index,create,show}.blade.php`,
`resources/views/admin/demo-requests/index.blade.php`,
`resources/views/errors/tenant-suspended.blade.php`,
`resources/views/layouts/app.blade.php` (platform-domain header variants).

**Lang:** `lang/en/platform.php` (rewritten).

**Docs:** `README.md`, `docs/DEPLOY.md`, this file.

**Tests:** `tests/Feature/Platform/{HomePageTest,DemoRequestTest,PlatformStatsTest,
RouteRegistrationTest}.php`, `tests/Feature/Platform/Admin/{InstituteTest,
DemoRequestAdminTest}.php`, `tests/Feature/Console/LocalHostsCommandTest.php`, plus the
one new test appended to the existing `tests/Feature/Console/AppPreflightCommandTest.php`
and one updated test in `tests/Feature/Courses/PublicPagesUiTest.php` (see "Test
corrections" below). `phpunit.mysql.xml`: new `Platform` suite.

---

## Test corrections (approved before touching an already-committed/locked test)

1. **`PublicPagesUiTest`, "the central domain home page shows a simple translated
   platform page, not the Laravel welcome page"** — asserted the *old* placeholder lang
   keys (`platform.home.heading`/`.message`), which the brief explicitly asked to replace
   with the real landing page. Updated to assert the new hero heading and demo-form
   heading instead — same intent (a real page, not the framework's default welcome view),
   updated for what the page now actually is.
2. **`AppPreflightCommandTest`** — one new test added (additive only): fails in
   production when the local demo platform admin account exists.

---

## Assumptions

- Central-domain header variants (guest marketing page vs. authenticated platform-admin
  nav) are both new `@elseif`/`@else` branches in the existing shared
  `resources/views/layouts/app.blade.php`, rather than a separate layout file — keeps
  "one layout" true and matches "reuse the shared Blade components and layout."
- An institute's "owner" for password-reset purposes is any `role=owner` user; the schema
  doesn't enforce exactly one owner per tenant, so the multi-owner dropdown (P1.1) is a
  real, not hypothetical, case.
- `local:hosts`'s environment guard is `in(['local', 'testing'])` rather than merely
  `!== 'production'`, so it also refuses on a `staging` environment name — a superset of
  "refuse in production," chosen since a hosts-file convenience command has no reason to
  run anywhere real DNS/Cloudflare is in play.

## Unresolved risks

- No automated test drives the demo-request form or the admin UI through an actual
  browser/JS engine — only server-rendered HTML assertions. Acceptable given "no JS
  framework" (there's no client-side behavior to miss), but noted for completeness.
- The suspended-tenant page reuses the shared layout, so its header still renders the
  normal guest nav (Courses/Login links) — clicking either just returns another 503
  rather than being hidden outright. Cosmetic, not a security or correctness issue,
  flagged for whoever next touches that page.
