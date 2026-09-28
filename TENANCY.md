# Tenancy

## Resolution

- Tenant is resolved from the request hostname (subdomain or custom domain), looked up in
  `tenant_domains`. There is no header-based or session-based tenant selection.
- **Fail closed.** If the hostname does not match a row in `tenant_domains`, the request is
  rejected (404/403) before any controller runs. There is no default or fallback tenant —
  an unresolved hostname must never silently fall through to tenant #1 or to a "demo"
  tenant.
- Resolution happens in middleware, early in the pipeline, before session/auth middleware
  runs, so that everything downstream can assume a tenant is already known.

## TenantContext

- A `TenantContext` (or equivalent singleton bound in the container) holds the resolved
  tenant for the current request.
- **Immutable once set.** Nothing in application code re-resolves or overwrites the tenant
  mid-request. If code needs to act "as" a different tenant (e.g. a queued job, a platform
  admin support action), it does so through an explicit, narrow, logged escape hatch — never
  by mutating the ambient context.

## BelongsToTenant trait

- Tenant-owned Eloquent models use a `BelongsToTenant` trait that:
  - Adds a global scope (TenantScope) filtering all queries to the current `TenantContext` tenant.
    **If no tenant is in context, the scope throws `MissingTenantContextException`** — never returns
    all rows or an empty set.
  - Auto-fills `tenant_id` on creation from the current context.
  - Makes `tenant_id` immutable: attempting to change it throws `InvalidTenantException`.
  - Refuses to save or delete a model whose `tenant_id` doesn't match the current context.
  - **Never mass-assigns `tenant_id`** — add to `$guarded` on all tenant-owned models (see below).

### tenant_id is not mass-assignable

Every tenant-owned model must guard `tenant_id` from mass assignment. Use either:

```php
protected $guarded = ['tenant_id'];
protected $fillable = ['name', 'slug', /* ... other fields ... */];
```

Or:

```php
protected $fillable = ['name', 'slug', /* ... other fields ... */];
// tenant_id is guarded by default if not in $fillable
```

This prevents accidental or malicious `fill(['tenant_id' => ...])` attacks.

## Tenant-aware route model binding

- Route model binding resolves models through the tenant-scoped query (i.e. respects the
  `BelongsToTenant` global scope), so `/courses/{course}` for tenant A can never resolve a
  course belonging to tenant B, even if the ID is guessed or enumerated.

## Jobs and queues

- Queued jobs never rely on an ambient "current tenant" — there isn't one once a job leaves
  the HTTP request. Every job constructor takes an explicit `tenant_id` (or the tenant-owned
  model, which carries its own `tenant_id`), and the job sets `TenantContext` from that value
  when it runs.
- **Scoped binding reset:** Laravel's queue service provider calls `forgetScopedInstances()` at
  the job execution boundary, resetting all scoped services. This prevents a request's
  `TenantContext` from leaking into a queued job. However, this is a framework safety mechanism,
  not a substitute for explicit job design: each job must still carry its own `tenant_id` and
  re-set `TenantContext` to it (Phase 13: queues at scale will add a job middleware to enforce
  this pattern).

## Escape hatches: runAs() and withoutGlobalScope

### TenantContext::runAs(Tenant $t, Closure $fn)

Used only in console commands, queued jobs, and platform-admin code to temporarily set context:

```php
// Console command: backfill data for a specific tenant
$tenantContext->runAs($tenant, function () {
    Course::factory()->count(100)->create();
});
```

**Rules:**
- Only allowed when the ambient context is empty (throws if a tenant is already set).
- Always clears context in a `finally` block, even if the closure throws.
- Every use must be justified in a code comment and reviewed in PR.

### Model::withoutGlobalScope() or withoutTenancy()

Used only in:
- System-level operations: migrations, seeders, admin console commands.
- Auditing or analytics requiring cross-tenant visibility.

**Rules:**
- Every use must include an inline comment explaining why.
- Every use must be reviewed and approved as an exception to tenant scoping.
- Pattern: search for `withoutGlobalScope.*TenantScope` or custom `withoutTenancy()` method.

Example (system maintenance):

```php
// Console command: audit tenant configuration across all tenants
$allTenants = Tenant::all();  // Tenant is not a tenant-owned model, no scope to remove
foreach ($allTenants as $tenant) {
    app(TenantContext::class)->runAs($tenant, fn () => $this->auditTenant());
}
```

---

## Query Builder Security

**Critical rule:** Eloquent's `update()` and `upsert()` methods call `toBase()`, which bypass model lifecycle hooks. Models using `BelongsToTenant` use `TenantBuilder` (custom Eloquent builder) that blocks bulk updates of `tenant_id`:

```php
// ❌ FORBIDDEN: throws InvalidTenantException
TenancyTestParent::query()->update(['tenant_id' => $other_tenant->id]);

// ❌ FORBIDDEN: throws InvalidTenantException
TenancyTestParent::query()->upsert(
    [['id' => 1, 'tenant_id' => $other_tenant->id, ...]],
    'id',
    ['name']
);

// ✅ CORRECT: model instance respects BelongsToTenant hooks
$model = TenancyTestParent::find(1);
$model->update(['name' => 'New name']); // Works (tenant_id not touched)
// Trying to set tenant_id throws InvalidTenantException via updating hook
```

Query builder updates without `tenant_id` still work and respect the global tenant scope (only affect current tenant's rows).

---

## Database queries and raw SQL

**Critical rule:** `DB::table()` and raw SQL queries bypass global scopes.

- **Forbidden** on tenant-owned tables unless `tenant_id` is explicitly filtered in the WHERE clause.
- Any raw query on a tenant-owned table must:
  1. Include a WHERE clause filtering by the current tenant's ID.
  2. Include an inline code comment documenting the filter.
  3. Be reviewed and approved as an exception (prefer Eloquent queries).

Example:

```php
// ❌ FORBIDDEN: bypasses tenant scope entirely
DB::table('courses')->where('status', 'active')->get();

// ✅ REQUIRED: explicit tenant_id filter
$courses = DB::table('courses')
    ->where('tenant_id', app(TenantContext::class)->id())
    ->where('status', 'active')
    ->get();
// Comment: raw query for performance-critical reporting; verified to filter by tenant_id
```

Prefer Eloquent:

```php
// ✅ PREFERRED: Eloquent applies TenantScope automatically
$courses = Course::where('status', 'active')->get();
```

---

## Composite foreign keys (schema-enforced isolation)

This is the core mechanical rule that makes cross-tenant links a database constraint
violation, not just an application bug:

### Pattern

- Every tenant-owned parent table has a **unique constraint on `(tenant_id, id)`**, in
  addition to its primary key on `id`.
- Every tenant-owned child table that references that parent:
  1. Stores **both** `tenant_id` and the parent's id.
  2. Has a foreign key on `(tenant_id, parent_id)` that references the parent's `(tenant_id, id)` unique constraint.
  3. **Never** has a foreign key on just the parent's `id` alone.

### Effect

A child row can only ever point at a parent row that shares its own `tenant_id`. Inserting
a child with a mismatched `tenant_id`/parent-id pair fails at the database level, regardless
of what application code did or didn't check.

### Migration example

```php
// Parent table
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
    $table->string('name');
    $table->timestamps();

    $table->unique(['tenant_id', 'id']);  // Composite unique
});

// Child table
Schema::create('lessons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id');
    $table->foreignId('course_id');
    $table->string('title');
    $table->timestamps();

    // Composite FK: (tenant_id, course_id) must match parent's (tenant_id, id)
    $table->foreign(['tenant_id', 'course_id'])
        ->references(['tenant_id', 'id'])
        ->on('courses')
        ->restrictOnDelete();
});
```

### Unique indexes must include tenant_id

Every unique index on a tenant-owned table **must include `tenant_id`**:

```php
// ❌ WRONG: allows same slug across different tenants in same table
$table->unique(['slug']);

// ✅ CORRECT: allows same slug only within different tenants
$table->unique(['tenant_id', 'slug']);
```

### Blueprint Macros

Two macros simplify the composite FK pattern:

#### `$table->tenantKeys()`

Adds a unique constraint on `(tenant_id, id)`. Use on all parent tables:

```php
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
    $table->string('name');
    $table->timestamps();

    $table->tenantKeys();  // Adds unique(['tenant_id', 'id'])
});
```

#### `$table->tenantForeign($column, $table)`

Adds a composite FK on `(tenant_id, $column)` referencing the parent's `(tenant_id, id)`. Use on all child tables:

```php
Schema::create('lessons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id');
    $table->foreignId('course_id');
    $table->string('title');
    $table->timestamps();

    $table->tenantForeign('course_id', 'courses');  // Adds FK on (tenant_id, course_id)
});
```

### Testing

This rule must be enforced identically on MySQL (production) and validated by
`tests/Feature/Tenancy` run under `composer test:mysql` — SQLite's foreign key handling is
not a reliable stand-in for this specific guarantee.

### Test Fixtures & RefreshDatabase

Test fixture migrations live **exclusively in `tests/Fixtures/migrations/`**, with timestamp prefix `2099_` to sort after main migrations. They are registered by `tests/TestCase.php` via `createApplication()` override.

**Forbidden patterns:**
1. **Never run fixtures in `beforeEach(Artisan::call('migrate'))`** – On MySQL, DDL inside RefreshDatabase's transaction causes implicit commits, breaking rollback isolation and test reliability. Each beforeEach call re-runs all migrations, slowing tests dramatically.
2. **Never place fixtures in `database/migrations/`** – They would deploy to production, creating unwanted test tables in production databases.

**Correct approach:**
- Fixture migrations: `tests/Fixtures/migrations/2099_01_01_000001_create_tenancy_test_*.php`
- Registration: `tests/TestCase.php` overrides `createApplication()` to call `$app->make('migrator')->path(base_path('tests/Fixtures/migrations'))`
- Behavior: Migrations run once when RefreshDatabase sets up the test database, before the test's transaction
- Cleanup: All fixture tables rollback with RefreshDatabase, no explicit cleanup needed

**Verification:**
```bash
# Test database: fixture tables exist (tests pass)
composer test

# Production database: NO fixture tables (php artisan migrate:status shows none)
php artisan migrate:status
```

## Tenant-aware user provider

- Authentication uses a custom user provider that scopes user lookup by the resolved
  tenant. A session's user id can only ever resolve to a user belonging to the tenant the
  session's hostname resolves to.
- This is what makes "session replay across tenants must fail" (see
  [SECURITY.md](SECURITY.md)) actually hold: even if a session cookie or id were replayed
  against a different tenant's subdomain, the user provider would fail to resolve a user,
  because the same numeric user id in a different tenant either doesn't exist or belongs to
  someone else and is excluded by the scope.
