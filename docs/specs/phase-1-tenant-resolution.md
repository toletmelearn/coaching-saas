# Phase 1: Central Tables and Hostname-Based Tenant Resolution

**Status:** ✅ Complete  
**Implemented:** 2026-09-28  
**Test Coverage:** 27 tests (SQLite + MySQL)

---

## Overview

Phase 1 establishes the foundational multi-tenant architecture:
1. **Central tables** — `tenants`, `tenant_domains`, `platform_admins`, `system_settings`
2. **Hostname-based resolution** — resolve tenant from request hostname
3. **Fail-closed isolation** — unknown hostnames return 404 (not default tenant)
4. **Scoped service binding** — TenantContext resets between requests and jobs

This phase does not implement composite foreign keys (that begins with Phase 2 when the first tenant-owned child tables are introduced) or tenant-owned models with the BelongsToTenant trait (Phase 2).

---

## Schema

### Tenants Table

Central table: one row per coaching institute tenant.

**Columns:**
- `id` — auto-increment primary key
- `name` — institute name (e.g., "Demo Institute")
- `status` — PHP enum `TenantStatus {trial, active, suspended, archived}` (string column, not DB ENUM)
- `timezone` — default `'Asia/Kolkata'`
- `currency` — default `'INR'`
- `created_at`, `updated_at` — timestamps

**Not included yet (later phases):**
- `payment_gateway_config` — Stage C
- `bunny_library_id` — Phase 6 (Stage B)

### Tenant Domains Table

Central table: hostnames that resolve to tenants (subdomain or custom domain).

**Columns:**
- `id` — auto-increment primary key
- `tenant_id` — foreign key to `tenants` with `restrictOnDelete` (tenants are archived, never deleted)
- `domain` — hostname (lowercase, no port, no trailing dot)
- `type` — PHP enum `DomainType {subdomain, custom}` (string column)
- `is_primary` — boolean (primary domain for the tenant)
- `verified_at` — timestamp (nullable; for custom domains, proof of ownership)
- `created_at`, `updated_at` — timestamps

**Constraints:**
- `domain` unique
- `domain` mutator normalizes to lowercase, no trailing dot

### Platform Admins Table

Central table: platform-level staff (distinct from tenant roles).

**Columns:**
- `id` — auto-increment primary key
- `name` — admin name
- `email` — unique email
- `password` — hashed password
- `status` — PHP enum `PlatformAdminStatus {active, suspended}` (string column)
- `last_login_at` — timestamp (nullable)
- `remember_token` — session token
- `created_at`, `updated_at` — timestamps

### System Settings Table

Central table: platform-wide configuration (feature flags, limits).

**Columns:**
- `id` — auto-increment primary key
- `key` — setting name (unique)
- `value` — JSON-encoded value
- `created_at`, `updated_at` — timestamps

---

## Implementation

### TenantContext Service

Holds the resolved tenant for the current request.

**File:** `app/Support/TenantContext.php`

**Binding:** `AppServiceProvider` uses `$this->app->scoped(TenantContext::class)` (resets between requests/jobs, not a singleton).

**Interface:**
```php
public function set(Tenant $tenant): void  // throws if called twice
public function has(): bool               // true if tenant is set
public function get(): Tenant             // returns tenant or throws
public function id(): int                 // returns tenant ID
```

**Immutability:** Once `set()` is called, calling it again throws `RuntimeException`. This enforces one tenant per request.

### ResolveTenant Middleware

Resolves tenant from the request hostname.

**File:** `app/Http/Middleware/ResolveTenant.php`

**Behavior:**
1. Normalize hostname: lowercase, strip trailing dot (`rtrim(strtolower($host), '.')`)
2. Check if hostname is in `config/tenancy.php` central domains (e.g., `coaching.test`, `platform.coaching.test`)
   - If yes: return next middleware without setting tenant (central domain, no tenant context)
   - If no: continue to step 3
3. Look up hostname in `tenant_domains` table (exact match only)
   - If found: set `TenantContext` to the resolved tenant and continue
   - If not found: abort 404
4. Resolves **all tenant statuses** (trial, active, suspended, archived) — status enforcement is Phase 3

**Registered as:** Prepended to web middleware stack in `bootstrap/app.php`

### RequireTenant Middleware

Enforces that a tenant is present in the current request (rejects central-domain requests).

**File:** `app/Http/Middleware/RequireTenant.php`

**Behavior:**
- If `TenantContext::has()` is true: allow request
- If `TenantContext::has()` is false (central domain, no tenant): abort 404

**Registered as:** Middleware alias `require.tenant` in `bootstrap/app.php`

**Usage:** Apply to routes that require a tenant:
```php
Route::get('/dashboard', fn () => ...)->middleware('require.tenant');
```

### Demo Tenant Seeder

Creates one demo institute for local development.

**File:** `database/seeders/TenantSeeder.php`

**Creates:**
- Tenant: name = "Demo Institute", status = active
- Domain: "demo.coaching.test", type = subdomain, is_primary = true, verified_at = now()

**Idempotent:** Uses `firstOrCreate()`, safe to run multiple times.

---

## Enums

### TenantStatus

**File:** `app/Enums/TenantStatus.php`

```php
enum TenantStatus: string {
    case Trial = 'trial';       // Free trial period
    case Active = 'active';     // Paid or active trial
    case Suspended = 'suspended'; // Temporarily paused (Phase 3)
    case Archived = 'archived'; // Permanently closed (no data deletion)
}
```

### DomainType

**File:** `app/Enums/DomainType.php`

```php
enum DomainType: string {
    case Subdomain = 'subdomain'; // e.g., demo.coaching.test
    case Custom = 'custom';       // e.g., training.example.com (Phase 2)
}
```

### PlatformAdminStatus

**File:** `app/Enums/PlatformAdminStatus.php`

```php
enum PlatformAdminStatus: string {
    case Active = 'active';
    case Suspended = 'suspended';
}
```

---

## Configuration

**File:** `config/tenancy.php`

**Central Domains:**
```php
'central_domains' => ['localhost', '127.0.0.1', 'coaching.test', 'platform.coaching.test']
```

Hostnames in this list bypass tenant resolution. They are where the platform marketing site and admin panel live, plus local development hosts. A request to `coaching.test` or `platform.coaching.test` will have no tenant in context.

---

## Tests

**Test Suite Location:** `tests/Feature/Tenancy/`

**Test Count:**
- SQLite (composer test): 30 tests total (28 Tenancy + 2 Example)
- MySQL (composer test:mysql): 28 Tenancy tests (runs all tenancy tests)

### ResolveTenant Tests

1. ✅ Central domain (no tenant) resolves without setting context
2. ✅ Known tenant domain resolves to the correct tenant
3. ✅ Unknown domain returns 404
4. ✅ Subdomain-suffix spoof (`demo.coaching.test.evil.com`) does NOT resolve to real tenant (404)
5. ✅ Uppercase hostname resolves same as lowercase (`DEMO.COACHING.TEST` → `demo.coaching.test`)
6. ✅ Trailing-dot hostname resolves same as without (`demo.coaching.test.` → `demo.coaching.test`)
7. ✅ Two tenants resolve independently (tenant A's domain does not resolve tenant B)

### RequireTenant Tests

8. ✅ Known tenant domain passes middleware
9. ✅ Central domain is rejected by middleware (404)

### TenantContext Tests

10. ✅ `has()` returns false before `set()` is called
11. ✅ `get()` throws if not set
12. ✅ `set()` stores tenant and `has()`/`get()`/`id()` reflect it
13. ✅ `set()` throws if called twice (immutability enforced)
14. ✅ Scoped binding: fresh instance from container has no tenant (after `forgetScopedInstances()`)
15. ✅ **Container scoped reset:** Confirms `forgetScopedInstances()` resets TenantContext (simulates job boundary)

### Tenant Seeder Tests

16. ✅ Seeder creates one demo tenant with verified subdomain
17. ✅ Running seeder twice does not create duplicates (idempotent)

### Model Tests

18–27. Tests for Tenant, TenantDomain, PlatformAdmin, and SystemSetting models (factory, relationships, casts)

---

## Test Verification

### SQLite (composer test)

```
{"tool":"pest","result":"passed","tests":29,"passed":29,"assertions":54,"duration_ms":15616}
```

**Note:** Includes Example tests outside Tenancy. All Tenancy tests pass.

### MySQL (composer test:mysql)

```
{"tool":"pest","result":"passed","tests":27,"passed":27,"assertions":52,"duration_ms":1990}
```

**Verified Constraints:**
- Composite foreign key pattern **not yet implemented** (begins in Phase 2 with first child table)
- `restrictOnDelete` FK constraint enforced by MySQL schema
- Foreign key violations properly rejected

---

## Fail-Closed Tenant Resolution (Agent Rule #1)

This phase implements the core fail-closed guarantee:

- Unknown hostname → 404 (never default or fallback tenant)
- Central domains → no tenant context (but not rejected)
- Spoofing (`demo.coaching.test.evil.com`) → 404 (exact match only, no substring)
- Suspended/archived tenants → resolved successfully (status enforcement is Phase 3)

---

## Assumptions & Constraints

1. **Scoped Binding:** `TenantContext` is bound with `scoped()`, not `singleton()`. This ensures:
   - Reset between HTTP requests (per-request lifecycle)
   - Reset between queue jobs (job execution boundary)
   - Reset between Octane worker requests (worker process boundary)

2. **Hostname Normalization:** Both middleware and model use identical logic:
   - Lowercase: `strtolower()`
   - Strip trailing dot: `rtrim(..., '.')`
   - This ensures consistent lookup regardless of client input

3. **Exact-Match Lookup:** No fuzzy or substring matching. Query: `where('domain', $host)->first()`

4. **Central Domains from Config:** Defined in `config/tenancy.php` and normalized identically to hostname lookup.

5. **Composite FK Pattern:** Not yet implemented. Phase 1 establishes the schema for central tables; Phase 2 introduces the first tenant-owned child tables and establishes the composite FK pattern (`tenant_id, parent_id`) with proper constraints.

---

## Files Modified/Created

### Schema & Migrations
- `database/migrations/2026_09_27_000001_create_tenants_table.php`
- `database/migrations/2026_09_27_000002_create_tenant_domains_table.php`
- `database/migrations/2026_09_27_000003_create_platform_admins_table.php`
- `database/migrations/2026_09_27_000004_create_system_settings_table.php`

### Models
- `app/Models/Tenant.php`
- `app/Models/TenantDomain.php` (with domain mutator)
- `app/Models/PlatformAdmin.php`
- `app/Models/SystemSetting.php`

### Enums
- `app/Enums/TenantStatus.php`
- `app/Enums/DomainType.php`
- `app/Enums/PlatformAdminStatus.php`

### Application
- `app/Support/TenantContext.php` (scoped service)
- `app/Http/Middleware/ResolveTenant.php`
- `app/Http/Middleware/RequireTenant.php`
- `app/Providers/AppServiceProvider.php` (binding)
- `bootstrap/app.php` (middleware registration)
- `config/tenancy.php` (central domains)

### Seeders
- `database/seeders/TenantSeeder.php` (demo tenant)

### Tests
- `tests/Feature/Tenancy/ResolveTenantTest.php` (8 tests)
- `tests/Feature/Tenancy/RequireTenantTest.php` (2 tests)
- `tests/Feature/Tenancy/TenantContextTest.php` (5 tests + 1 queue test)
- `tests/Feature/Tenancy/TenantSeederTest.php` (2 tests)
- `tests/Feature/Tenancy/TenantTest.php`
- `tests/Feature/Tenancy/TenantDomainTest.php`
- `tests/Feature/Tenancy/PlatformAdminTest.php`
- `tests/Feature/Tenancy/SystemSettingTest.php`
- `tests/Feature/Tenancy/TenancyConfigTest.php`

### Documentation
- `CLAUDE.md` (points to AGENT_RULES.md, TENANCY.md, SECURITY.md)
- `docs/specs/phase-1-tenant-resolution.md` (this file)

---

## Next: Phase 2

Phase 2 introduces the **BelongsToTenant isolation framework**:
- `TenantContext` service (already in Phase 1, integrated here)
- `BelongsToTenant` trait (automatic tenant scoping on models)
- Composite foreign keys (`tenant_id, parent_id` pattern) established on first child tables
- Tenant-aware route model binding
- First tenant-owned tables for infrastructure (may not be user-facing yet)

Phase 3 follows with tenant users and authentication — the first concrete use of `BelongsToTenant` on user-owned models.

See [ROADMAP.md](../../ROADMAP.md) and [TENANCY.md](../../TENANCY.md) for details.
