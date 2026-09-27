# Phase 1: Central Tables and Tenant Resolution — Design

Status: approved. Implements Stage A / Phase 1 from [ROADMAP.md](../../../ROADMAP.md):
central tables (`tenants`, `tenant_domains`, `platform_admins`, `system_settings`) and
hostname-based tenant resolution, per [ARCHITECTURE.md](../../../ARCHITECTURE.md) and
[TENANCY.md](../../../TENANCY.md).

## Scope

In scope: schema for the four central tables, their Eloquent models, a `TenantContext`
container binding, hostname-based resolution middleware, a `platform_admin` auth guard, a
demo seeder, and tests proving fail-closed resolution.

Out of scope (later phases, do not build now): `BelongsToTenant` trait, composite foreign
keys on tenant-owned tables (there are none yet — nothing is tenant-owned until Phase 2+),
tenant status enforcement (`EnsureTenantActive` — Phase 4), platform admin routes/UI, Bunny
Stream fields, payment gateway credential storage.

## Schema

### `tenants`

| column      | type                      | notes                                    |
|-------------|---------------------------|-------------------------------------------|
| id          | bigint, PK                |                                             |
| name        | string                    |                                             |
| status      | string                    | backed by PHP enum `TenantStatus` (below), not a DB `ENUM` column |
| timezone    | string, default `Asia/Kolkata` |                                        |
| currency    | string, default `INR`     |                                             |
| timestamps  |                           |                                             |

`TenantStatus` enum (`App\Enums\TenantStatus`): `Trial`, `Active`, `Suspended`, `Archived`
(backed string enum, e.g. `'trial'`, `'active'`, `'suspended'`, `'archived'`). No enforcement
of status in Phase 1 — resolution succeeds regardless of status (see Resolution below).

No `bunny_library_id` (Phase 6) or `payment_gateway_config` (Stage C —
`payment_gateway_accounts` will hold that later, encrypted, per SECURITY.md). Adding either
now would be a placeholder column with no enforcement, contradicting Phase 0's "no
migrations beyond defaults / do not implement payments or video" boundary.

### `tenant_domains`

| column       | type                              | notes |
|--------------|-----------------------------------|-------|
| id           | bigint, PK                        | |
| tenant_id    | bigint, FK → tenants.id, `restrictOnDelete()` | tenants are archived, never hard-deleted, so a restrict (not cascade) is the correct guard — deleting a tenant row while domains still reference it is a bug, not a normal flow |
| domain       | string, unique                    | stored lowercase, no port, no trailing dot (normalized at write time by a model mutator, and defensively at lookup time by the middleware) |
| type         | string                            | backed by PHP enum `DomainType`: `Subdomain`, `Custom` |
| is_primary   | boolean, default false            | |
| verified_at  | timestamp, nullable               | subdomains are inserted with `verified_at` set at creation; custom domains (Stage C) start null until DNS verification |
| timestamps   |                                    | |

### `platform_admins`

| column          | type                     | notes |
|-----------------|--------------------------|-------|
| id              | bigint, PK               | |
| name            | string                   | |
| email           | string, unique           | |
| password        | string                   | hashed |
| status          | string                   | backed by PHP enum `PlatformAdminStatus`: `Active`, `Suspended` |
| last_login_at   | timestamp, nullable      | |
| remember_token  | string, nullable         | |
| timestamps      |                          | |

Independent table from the tenant-scoped `users` table — never joined or unioned with it.

### `system_settings`

| column     | type            | notes |
|------------|-----------------|-------|
| id         | bigint, PK      | |
| key        | string, unique  | |
| value      | json            | |
| timestamps |                 | |

`App\Models\SystemSetting` gets two static helpers:
- `SystemSetting::get(string $key, mixed $default = null): mixed`
- `SystemSetting::set(string $key, mixed $value): void`

No caching layer in Phase 1 — a straight table read/write. Caching can be added later
without changing the call sites if it becomes a hot path.

## Config: `config/tenancy.php`

```php
return [
    'central_domains' => explode(',', env('CENTRAL_DOMAINS', 'localhost,127.0.0.1,coaching.test,platform.coaching.test')),
];
```

Hosts in this list are never looked up in `tenant_domains` — they're where the platform
marketing site / admin panel will live (built in later phases). Configurable via `.env` so
CI/local can differ from production without code changes.

## TenantContext

`App\Support\TenantContext`, bound with `$this->app->scoped(TenantContext::class, ...)` in
`AppServiceProvider` — **`scoped()`, not `singleton()`**, so it resets between queue jobs
and Octane requests rather than leaking a resolved tenant from one request/job into the
next.

```php
final class TenantContext
{
    private ?Tenant $tenant = null;
    private bool $set = false;

    public function set(Tenant $tenant): void
    {
        if ($this->set) {
            throw new \RuntimeException('TenantContext is already set for this request.');
        }

        $this->tenant = $tenant;
        $this->set = true;
    }

    public function has(): bool
    {
        return $this->set && $this->tenant !== null;
    }

    public function get(): Tenant
    {
        if (! $this->has()) {
            throw new \RuntimeException('TenantContext has not been set.');
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }
}
```

Central-domain requests never call `set()` — `has()` stays `false`, which is exactly what
`RequireTenant` middleware checks (see below).

## Resolution: `ResolveTenant` middleware

Registered first in the `web` middleware group (`bootstrap/app.php`
`->withMiddleware(fn ($m) => $m->web(prepend: [ResolveTenant::class]))`).

Logic:
1. Normalize `$request->getHost()`: lowercase, strip a single trailing `.`.
2. If normalized host is in `config('tenancy.central_domains')` → continue, no tenant
   bound.
3. Else look up `TenantDomain::where('domain', $host)->first()`.
   - Found → `TenantContext::set($tenantDomain->tenant)`, continue. (Loads regardless of
     the tenant's `status` — enforcing suspended/archived is `EnsureTenantActive` in Phase
     4, not here.)
   - Not found → `abort(404)`. No default tenant, no fallback — matches TENANCY.md's
     fail-closed rule exactly.

Exact match only (no wildcard/suffix matching), so `demo.coaching.test.evil.com` does not
resolve to the `demo.coaching.test` tenant — it's simply not a row in `tenant_domains`.

## `RequireTenant` middleware

A second, separate middleware: aborts 404 if `TenantContext::has()` is false. Used on route
groups that must never be reachable from a central domain (i.e., everything tenant-facing,
starting in Phase 2+). Phase 1 registers the alias but has no real tenant-facing routes yet,
so a temporary test-only route proves the behavior (see Tests).

Both middleware are registered as aliases in `bootstrap/app.php`: `'resolve.tenant'` and
`'require.tenant'`.

## Auth guard

`config/auth.php` additions:

```php
'guards' => [
    // ...existing...
    'platform_admin' => [
        'driver' => 'session',
        'provider' => 'platform_admins',
    ],
],

'providers' => [
    // ...existing...
    'platform_admins' => [
        'driver' => 'eloquent',
        'model' => App\Models\PlatformAdmin::class,
    ],
],
```

No routes/controllers use this guard yet — it exists so platform-admin auth is structurally
separate from day one, per ARCHITECTURE.md.

## Seeder

`DatabaseSeeder` (or a dedicated `TenantSeeder` called from it) creates one demo tenant:
- `tenants`: `name = 'Demo Institute'`, `status = active`.
- `tenant_domains`: `domain = 'demo.coaching.test'`, `type = subdomain`, `is_primary =
  true`, `verified_at = now()`.

Factories: `TenantFactory`, `TenantDomainFactory` (for tests), `PlatformAdminFactory`.

## Tests (`tests/Feature/Tenancy/`)

Must pass under both `composer test` (SQLite) and `composer test:mysql`. Temporary
test-only routes (defined inside the test file via `Route::get(...)` in a `beforeEach`, not
in `routes/web.php`) exercise the two middleware without needing real Phase 2+ routes:

1. A central domain (e.g. `coaching.test`) resolves with no tenant bound; `TenantContext::has()` is false.
2. A known tenant domain resolves to the correct `Tenant` (`TenantContext::get()->id` matches).
3. An unknown domain returns 404.
4. `demo.coaching.test.evil.com` returns 404 (does not fall through to the `demo.coaching.test` tenant).
5. Uppercase host (`DEMO.COACHING.TEST`) resolves the same as lowercase.
6. Trailing-dot host (`demo.coaching.test.`) resolves the same as without.
7. Two distinct tenant domains resolve to two distinct tenants independently in separate requests.
8. `TenantContext::set()` called twice in one request throws `RuntimeException`.
9. `TenantContext` is empty (`has()` false) on a fresh instance representing the next request/job — proven by resolving `TenantContext` fresh from the container after a request completes and asserting it's a new, unset instance (demonstrating the `scoped()` binding resets, since `singleton()` would carry state over).
10. `RequireTenant` on a central-domain request returns 404 (no tenant in context to require).

## Housekeeping (same commit, reported separately)

- `CLAUDE.md`: replace the Laravel Boost stub with an instruction that every session must
  read `AGENT_RULES.md`, `TENANCY.md`, and `SECURITY.md` before doing any work on this repo.
- `AGENT_RULES.md`: placeholder content replaced verbatim with the user-supplied official
  20 rules once provided in this same conversation.

## Out of scope / explicitly deferred

- `BelongsToTenant` trait and composite foreign keys — Phase 2 (no tenant-owned tables
  exist yet to apply them to).
- `EnsureTenantActive` (status enforcement) — Phase 4.
- Platform admin routes, controllers, views — later phase, guard only for now.
- Any caching on `SystemSetting` — add only if profiling shows it's needed.
