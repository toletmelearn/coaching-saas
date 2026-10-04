# Phase 13: Platform Admin Control Panel

**Status:** Complete. `composer test` (964 tests, 963 passed, 1 skipped — the documented
MySQL-only preflight test), `composer test:mysql` (932/932), `composer test:js` (29/29),
`composer lint`, and `composer analyse` (0 errors) all pass with zero regressions to
Phases 1–12.
> Test count at time of writing; see README for the current totals.

---

## What ships

The `/admin` panel stops needing a developer for day-to-day operations. Seven
capabilities, all reachable from the nav, all `platform_admin`-only, every state change
audit-logged:

- **A — Service settings** (`/admin/settings/services`): Bunny and Jitsi/mail
  configuration edited through the UI, secrets encrypted at rest, **Test Connection**
  buttons (Bunny does a live `GET https://api.bunny.net/videolibrary`; Jitsi does a local
  `JitsiJwt::verify()` round-trip), throttled 10/min.
- **B — Health checklist** (`/admin/health`): the 13 `app:preflight` checks rendered from
  the same `PreflightChecks::run()` code the command uses, a **Re-run** button, and an
  `APP_DEBUG`-gated **Fix** per checkable failure.
- **C — Backups** (`/admin/backups`): list/run/download/delete spatie/laravel-backup
  archives on the existing `backups` disk.
- **D — Log viewer** (`/admin/logs`): last-100-line tail of `laravel.log` with `?q=`
  search over the last 5 MB. Read-only.
- **E — Login-as-owner + Bunny usage**: per-institute impersonation (signed, single-use,
  60-second URL) and a per-tenant Bunny storage/traffic/video-count report.
- **F — Audit log** (`/admin/audit`): `admin_audit_logs` table + filterable, paginated UI
  covering every privileged action (login, create_tenant, reset_password, update_setting,
  impersonate + the panel's own ops).
- **G — System `.env` editor** (`/admin/settings/system`): allowlisted keys only, plus
  clear-cache / optimize buttons.

## Decisions confirmed before implementation (Step 1 clarifications)

- **No new package.** spatie/laravel-backup `^10.3` (already installed) covers C;
  everything else is first-party code.
- **Impersonation is a signed URL, not a stored token.** `InstituteController::loginAsOwner`
  mints `URL::temporarySignedRoute('admin.impersonate', now()->addSeconds(60), [user,
  admin])` under `URL::forceRootUrl` on the tenant host (restored in a `finally`), so the
  HMAC covers host + expiry; consumption on the tenant host is `signed` middleware →
  `Cache::add` single-use burn → owner-only role check → audit row **before**
  `Auth::guard('tenant')->login` + session regeneration → redirect `/dashboard`.
  Opened with `target="_blank"`.
- **`.env` allowlist.** `SystemEnvController::ALLOWED` = APP_NAME, APP_URL, MAIL_*,
  SESSION_LIFETIME, SESSION_SECURE_COOKIE, VIDEO_DRIVER, PLATFORM_DOMAIN,
  TENANT_BASE_DOMAIN, CENTRAL_DOMAINS. `APP_KEY`, `DB_*`, `APP_ENV`, `APP_DEBUG` are
  deliberately excluded (APP_DEBUG is fixable only through the health Fix). Blank value =
  skip; newline in a value = rejected; non-allowlisted key = `ValidationException`.
- **Health Fix is double-gated** on `APP_DEBUG` (hidden in the render **and** a server-side
  403 on the endpoint), reads its value from `PreflightChecks`' server-side fix map only
  (posted `key`/`value` fields are ignored), runs `config:clear` through `ArtisanRunner`,
  and audits `health_fix:<KEY>`.
- **Preflight extraction.** The 13 checks moved verbatim into
  `App\Support\Preflight\PreflightChecks::run()` (byte-identical messages verified); the
  command keeps gate/formatting/exit codes, the panel consumes the outcomes.
- **ServiceSettings is DB-first with config fallback**, consumed by `BunnyVideoProvider`,
  the live-class join path and the preflight checks — no boot-time config overlay.
- **Bunny Usage never reads the ApiKey** to display usage: it calls
  `GET https://api.bunny.net/videolibrary/{id}` with the key in the `AccessKey` header and
  reports `StorageUsage`/`TrafficUsage`/`VideoCount`, with a local `LessonVideo` sum as
  fallback under `runAs`.
- **Audit table shape is exact:** `UPDATED_AT = null`, no foreign keys, no `tenant_id`;
  typed `target_type` (`env:KEY`, `setting:KEY`, `health_fix:KEY`, `backup:filename`,
  `tenant`, `user`). Actions: the five required (login, create_tenant, reset_password,
  update_setting, impersonate) plus backup_run, backup_delete, clear_cache, optimize,
  suspend_tenant, reactivate_tenant — attempts and failures recorded too.
- **Route split.** The new routes form their own group behind
  `PlatformAdminAuth:platform_admin`; legacy admin routes keep their long-standing
  redirect-on-tenant-session behaviour (pinned by `PlatformAdminGuardTest`). New routes
  take no names (they live in the central-domain loop); the tenant-side
  `admin/impersonate` route is named (registered once, outside the loop) because signed
  routing needs a name.

## Middleware: why `PlatformAdminAuth` is a subclass of `Authenticate`

The first implementation used a standalone `RejectTenantSession` middleware declared
*before* `auth:platform_admin`, relying on route middleware running in declaration order.
It failed its own contract (302 instead of 403) because **the router sorts resolved
middleware against the priority list after resolving names**
(`Router::resolveMiddleware` → `SortedMiddleware`; the priority list is
`Illuminate\Foundation\Configuration\Middleware::$defaultPriority`, extended in
`bootstrap/app.php` via `prependToPriorityList`). `Authenticate` matches that list through
its `AuthenticatesRequests` interface and gets spliced ahead of any standalone middleware
sitting after the `web` group — so auth fails first with a redirect and the standalone
403 never runs.

The fix: `App\Http\Middleware\PlatformAdminAuth extends Authenticate` and checks tenant
identity inside `authenticate()` before delegating to the parent. The check therefore
*travels* to whatever slot sorting picks for auth — which is after `StartSession` (a real
tenant session cookie is readable there, unlike a middleware hoisted before the group) and
before `SubstituteBindings`. Guests get the exact redirect they always had. The probe that
found this is recorded in the git history of this phase; `RejectTenantSession` and its
alias were deleted.

## Schema

### `admin_audit_logs` (platform-level, deliberately not tenant-owned)

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `admin_id` | bigint nullable | **no FK** — a deleted admin renders as raw id |
| `action` | string, indexed | `login`, `create_tenant`, `reset_password`, `update_setting`, `impersonate`, `backup_run`, `backup_delete`, `clear_cache`, `optimize`, `suspend_tenant`, `reactivate_tenant` |
| `target_type` | string nullable | typed: `env:KEY`, `setting:KEY`, `health_fix:KEY`, `backup:filename`, `tenant`, `user` |
| `target_id` | bigint nullable | |
| `ip_address` | string nullable | |
| `created_at` | timestamp | the only timestamp — `UPDATED_AT = null`, append-only |

No `tenant_id`: the actor is a platform admin and the row is platform history; filtering
happens on `action` (`scopeFilterByAction`) and the admin relation is a plain `BelongsTo`.

## Feature notes

### A — Service settings and Test Connection

`ServiceSettings::set/get` writes encrypted values (Eloquent encrypted cast) and reads
`config/services.php` as the fallback, so a fresh install works with only `.env`. The
services form masks `MAIL_PASSWORD` only (the other secrets are write-only fields: leave
blank to keep). Test Connection never echoes the secret back.

### B — Health Fix contract

Fix values exist only in `PreflightChecks`' fix map (e.g. `debug → APP_DEBUG=false`,
`session_secure → SESSION_SECURE_COOKIE=true`); the endpoint reads `check` and ignores
everything else, so a crafted POST cannot write an arbitrary key. The run is
`config:clear` through `ArtisanRunner` (Symfony `BufferedOutput`, no shell) and an
`update_setting` / `health_fix:KEY` audit row.

### C — Backups

Run = `backup:run --disable-notifications` through `ArtisanRunner`; both success and
failure audit (`backup_run`) and both flash (`status` / `error` + `backup_output`).
Download and delete accept a **bare `*.zip` filename only** — traversal (`../.env`),
nested paths, non-zip names and missing files are all 404, asserted both ways (the real
file still exists after a traversal attempt).

### E — Impersonation threat model

Covered in detail in [SECURITY.md](../../SECURITY.md) § Platform admin control panel. The
eight shapes tested: mint, consume (audit-before-login, exactly one row), replay (403,
count stays 1), expiry via `travel(61)` (403), wrong host (403 — HMAC covers host),
non-owner (403 — role check after signature), cross-tenant user id (404 — TenantScope),
ownerless institute (validation error `owner`).

## Required tests (57 new, 8 files, `tests/Feature/Platform/Admin/`)

| file | tests | covers |
|---|---|---|
| `AdminRouteGuardTest` | 4 | every Phase-13 route: 403 for tenant session, redirect for guest, 200 for platform admin, and the 403 **not** leaking onto legacy routes |
| `ServiceSettingsAdminTest` | 9 | store/read round-trip, secret masking, Test Connection (Http::fake), config fallback |
| `HealthPanelTest` | 5 | renders 13 checks, Fix hidden+403 with `APP_DEBUG=false`, Fix writes via injected `EnvFile` + `config:clear` + audit, passing/unknown check → 404, client-supplied `key`/`value` ignored |
| `BackupPanelTest` | 7 | zip-only listing, empty state, download, traversal/nested/non-zip/missing → 404, delete + audit, run success/failure through `ArtisanRunner` |
| `LogViewerTest` | 4 | tail-100 on a 150-line log, `?q=` search, no-match empty state, missing file state |
| `AuditLogTest` | 9 | login/failure, create_tenant, reset_password, suspend/reactivate rows, newest-first + filter + pagination, deleted-admin fallback |
| `SystemEnvTest` | 7 | allowlist listing, write + audit, non-allowlisted → 422 + untouched file, newline → 422, blank = skip, clear-cache/optimize through `ArtisanRunner` + audit, exact `ALLOWED` contents |
| `ImpersonationTest` | 12 | the eight shapes above + bunny-usage live figures (Http::fake), unprovisioned and missing-key states, tenant user blocked from mint |

Test seams used throughout: `EnvFile`/`LogTailer`/`ArtisanRunner` container bindings (the
real `.env` is never touched), `Http::fake` for Bunny, `Storage::fake('backups')`,
`travel()`, `inTenant()`/`freshRequestCycle()`.

## Files changed

- **New:** `app/Http/Controllers/Admin/{HealthController,ServiceSettingsController,BackupsController,LogViewerController,AuditLogController,SystemEnvController}.php`,
  `app/Http/Controllers/ImpersonationController.php`, `app/Http/Middleware/PlatformAdminAuth.php`,
  `app/Models/AdminAuditLog.php`, `app/Support/Admin/{ServiceSettings,EnvFile,ArtisanRunner,LogTailer,BunnyUsage}.php`,
  `app/Support/Preflight/PreflightChecks.php`, migration
  `2026_10_04_000001_create_admin_audit_logs_table.php`, views
  `admin/{health,backups,logs,audit}.blade.php`, `admin/settings/{services,system}.blade.php`,
  `admin/institutes/bunny-usage.blade.php`, the 8 test files above.
- **Modified:** `routes/web.php` (Phase-13 group + signed tenant route),
  `bootstrap/app.php` (no alias left — `RejectTenantSession` deleted),
  `app/Console/Commands/AppPreflightCommand.php` (delegates to `PreflightChecks`),
  `app/Services/Video/BunnyVideoProvider.php` + `app/Http/Controllers/LiveClassController.php`
  (read `ServiceSettings`), `app/Support/LiveClasses/JitsiJwt.php` (`verify()` added),
  `app/Http/Controllers/Admin/InstituteController.php` (login-as, bunny-usage, audit
  hooks), `app/Http/Controllers/Auth/PlatformAdminLoginController.php` (login audit),
  `lang/en/platform.php`, `resources/views/layouts/app.blade.php` (nav), `components/icon.blade.php`,
  `admin/institutes/show.blade.php` (Open-as-owner + usage link).

## Drift recorded this phase

- **C12 — ROADMAP renumber.** The admin panel took **Phase 13**, so Stage C shifted:
  custom domains → 14, queues → 15, catch-all → 16 (recorded in `PROJECT_BRIEF.md` §3).
  `TENANCY.md`'s stale "Phase 13: queues at scale" line was corrected to Phase 15.

## Post-shipment fix (Phase 13.1, 2026-10-04)

**Symptom.** The first real POST to `/admin/institutes` returned a 500:
`SQLSTATE[42S02]: Base table or view not found: 1146 — Table 'coaching_saas.admin_audit_logs' doesn't exist`.
All five quality gates were green, including 57 Phase 13 tests that write and read
audit rows.

**Diagnosis — case (a): the migration existed but was never applied to local MySQL.**

- `php artisan migrate:status | grep -i audit` → `2026_10_04_000001_create_admin_audit_logs_table .. Pending`
  (plus the two Phase 12.1 live-class migrations — three were pending in total).
- The migration file was committed with the phase (`git log -- database/migrations/` →
  `3c02c8f Phase 13: platform admin control panel`).
- The stale-schema-dump theory (case (b)) was impossible: `database/schema/` has **never
  existed** in this repo's history, `config/database.php` has no schema config, and no
  file or doc references `schema:dump`.

**Why the tests stayed green.** `RefreshDatabase` rebuilds each test database from the
migrations on every run (SQLite `:memory:` and the `coaching_saas_test` MySQL database),
so the table always existed in tests while the long-lived local `coaching_saas` database
drifted behind the code. This class of drift — code ahead of an un-migrated dev/prod
database — is invisible to the suite by construction.

**Fix.** `php artisan migrate` — no code written, no migration written. It applied the
audit table plus the two already-committed live-class migrations
(`2026_10_09_000001_create_live_classes_table`, `2026_10_09_000002_create_live_class_attendance_table`).
Schema dump regeneration: not applicable (none exists, see above).

**Verification (browser, end to end).** Logged in at `/admin/login`, created
"Phase 13.1 Test Institute" (`p131fix`) via `/admin/institutes/create`:

- tenant created with subdomain domain `p131fix.coaching.test` (`tenant_domains.type = subdomain`);
- owner `ownerp131@coaching.test` created with `must_change_password = 1`;
- the redirect **did** show the temporary password (`wd38-jasb`, "shown once") — no second bug;
- an `admin_audit_logs` row with `action = create_tenant`, `target_type = tenant`,
  `target_id = 3`, `admin_id = 1` was written;
- `/admin/audit` lists the row ("create tenant · tenant #3 · 127.0.0.1").

**Tests added.** `tests/Feature/Platform/Admin/AuditLogTest.php` gained the canary
`the admin_audit_logs table exists after migration` (`Schema::hasTable('admin_audit_logs')`).
The required second test — `institute creation is recorded against the new tenant` —
already existed and is a real POST + `assertDatabaseHas`, not a mock. Both were proven
against a fresh schema build with the migration file temporarily disabled: 2 fail
(`no such table: admin_audit_logs`), and 2 pass again once it is restored. Suite totals
moved 982 → **983** (SQLite) and 950 → **951** (MySQL); JS unchanged at 29.

## Assumptions

- A platform admin who can already edit `.env`-adjacent settings needs no further
  separation of duties *within* the panel; the audit log is the after-the-fact control.
- `MAIL_PASSWORD` is the only value masked in the services form: the others are
  write-only secrets (blank = keep), so displaying them would add risk without adding
  utility.
- Backup archives stay on the existing `backups` disk — off-box replication is the deploy
  runbook's job, not the panel's.
- The log viewer tails the single `storage/logs/laravel.log` via `LogTailer`; multi-file
  browsing is out of scope (the platform runs one app log).

## Unresolved risks

- **Live Bunny Test Connection and Usage are verified with `Http::fake` only** — no real
  Bunny account key exists in the test environment; the first real key should be exercised
  once by hand (same standing caveat as Phase 5's Bunny integration).
- **`EnvFile` writes are not inter-process locked.** Two simultaneous panel saves could
  race; practically impossible with a single-digit admin count, and the splice design keeps
  each individual write atomic-ish (read-modify-write of one key).
- **Health Fix covers the checkable subset only** (debug, session cookie, video driver,
  writable paths, mail) — fixes with no safe automated action stay report-only.
- **The panel assumes `APP_DEBUG` is the local/exception-mode flag.** On a production box
  with `APP_DEBUG=true` the Fix buttons are visible to platform admins; that is the
  intended trade (the operator who can see stack traces is trusted to fix them).
