# Phase 2: BelongsToTenant Isolation Framework

**Status:** In Progress  
**Phase Goal:** Establish automatic tenant scoping on all tenant-owned models via global scope + trait

---

## Overview

Phase 2 implements the `BelongsToTenant` isolation framework — the core mechanism that ensures:
- Every query on a tenant-owned model is automatically scoped to the current `TenantContext`
- Tenant ownership is immutable (cannot reassign a row to a different tenant)
- Explicit escape hatches exist for platform code and jobs (greppable, documented)
- Route model binding respects tenant scoping (returns 404 for other tenants' records)
- Composite foreign key convention enforces schema-level isolation

This phase does NOT introduce the `users` table (Phase 3). Test fixtures provide non-product models to prove the framework.

---

## Required Behavior

### 1. TenantScope Global Scope

**File:** `app/Scopes/TenantScope.php`

A `Illuminate\Database\Eloquent\Scope` that:
- Adds `where tenant_id = TenantContext::id()` to all queries on tenant-owned models
- **THROWS** `App\Exceptions\MissingTenantContextException` if `TenantContext::has()` is false
  - Never returns all rows, never silently returns empty
  - Fail loud: makes missing context visible in tests and production

**Usage:** Applied via `BelongsToTenant` trait (not used directly)

---

### 2. BelongsToTenant Trait

**File:** `app/Traits/BelongsToTenant.php`

Applied to every tenant-owned model. Provides:

#### Scope Application
- Adds `TenantScope` global scope to the model
- Defines `tenant()` relation: `belongsTo(Tenant::class, 'tenant_id')`

#### Creating
- Automatically sets `tenant_id` from `TenantContext::id()` on new instances
- If `tenant_id` is already set and differs from context → **throw** `InvalidTenantException`
- If no context exists → **throw** `MissingTenantContextException`

#### Updating
- `tenant_id` is **immutable** — cannot be changed
- Attempting to update `tenant_id` → **throw** `InvalidTenantException`

#### Saving/Deleting
- Before save/delete, verify instance's `tenant_id` matches `TenantContext::id()`
- Mismatch → **throw** `InvalidTenantException`

#### Mass Assignment
- `tenant_id` is **never mass-assignable** (add to `$guarded` or document in `$fillable`)
- This applies to ALL tenant-owned models going forward (document in TENANCY.md)

---

### 3. Escape Hatches (Greppable & Documented)

#### TenantContext::runAs(Tenant $t, Closure $fn)

**File:** `app/Support/TenantContext.php`

Allows temporary context switching for:
- Console commands (one-off backfills, repairs)
- Queued jobs (when job carries explicit tenant_id)

**Behavior:**
```php
public function runAs(Tenant $tenant, Closure $fn): mixed
{
    // Only allowed when context is currently empty
    if ($this->has()) {
        throw new RuntimeException('Cannot runAs() when a tenant is already set.');
    }

    // Set, run, always clear in finally
    $this->set($tenant);
    try {
        return $fn();
    } finally {
        $this->tenant = null;
        $this->set = false;
    }
}
```

**Rules:**
- Throws if context is already set (maintains immutability)
- Always clears context in `finally` block (even if closure throws)
- Every use must be documented in TENANCY.md with justification

#### Model::withoutTenancy() or withoutGlobalScope()

**Allowed only in:**
- Platform admin code
- System-level operations (migrations, seeders, admin commands)

**Rules:**
- Every use must include a code comment explaining why
- Every use must be reviewed and justified in TENANCY.md
- Grep-friendly: `withoutGlobalScope.*TenantScope` or `withoutTenancy()` 

---

### 4. Route Model Binding

**File:** `app/Models/Model.php` or `app/Traits/BelongsToTenant.php`

Ensure `resolveRouteBinding()` respects tenant scoping:
- When a route binds a tenant-owned model by ID, the model is retrieved via the tenant-scoped query
- Attempting to access another tenant's record → 404 (not exception, not data leak)
- Test: `/posts/{post}` on tenant A returns 404 if `$post` belongs to tenant B

**Mechanism:** Override `resolveRouteBinding()` to call parent (which respects global scope) or use implicit scoping in Laravel's route model binding.

---

### 5. Composite Foreign Key Convention

**File:** `app/Macros/BlueprintTenancyMacro.php` (or inline in migrations)

Provide helpers for migrations:

```php
// Parent table
Schema::create('parents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
    // ... other columns
    $table->unique(['tenant_id', 'id']);  // Composite unique on tenant + id
});

// Child table
Schema::create('children', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id');
    $table->foreignId('parent_id');
    // ... other columns
    
    // Composite FK: child's (tenant_id, parent_id) must match parent's (tenant_id, id)
    $table->foreign(['tenant_id', 'parent_id'])
        ->references(['tenant_id', 'id'])
        ->on('parents')
        ->restrictOnDelete();
});
```

**Documentation:**
- Add macros or helpers to simplify this pattern
- Document in TENANCY.md: every unique index on a tenant-owned table MUST include `tenant_id`
  - Example: `unique(['tenant_id', 'slug'])` (not just `unique(['slug'])`)

---

### 6. Database Query Safety

**Documentation update for TENANCY.md:**

- `DB::table()` and raw queries bypass global scopes
- **Forbidden** on tenant-owned tables unless tenant_id is explicitly filtered
- Any use must be reviewed and justified with a comment explaining the tenant_id filter
- Prefer Eloquent queries (which apply scopes) over raw SQL

---

## Test Fixtures

### Test-Only Migrations

**Location:** `tests/Fixtures/migrations/`

**Files:**
1. `0001_create_tenancy_test_parents.php` — Parent table with composite unique key
2. `0002_create_tenancy_test_children.php` — Child table with composite FK to parent

**Auto-loaded:** Register in `phpunit.xml` or test setup to run these migrations before tests.

### Test-Only Models

**Location:** `tests/Fixtures/Models/`

**Files:**
1. `TenancyTestParent.php` — Uses `BelongsToTenant`, has `children()` relation
2. `TenancyTestChild.php` — Uses `BelongsToTenant`, belongs to `parent`

---

## Pest Helper

**File:** `tests/Pest.php` or `tests/Helpers/TenancyTestingHelper.php`

```php
function inTenant(Tenant $tenant, Closure $fn): mixed
{
    return app(TenantContext::class)->runAs($tenant, $fn);
}
```

Usage in tests:
```php
test('tenant A cannot see tenant B rows', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    
    $rowA = inTenant($tenantA, fn () => TenancyTestParent::factory()->create());
    
    inTenant($tenantB, function () use ($rowA) {
        expect(TenancyTestParent::find($rowA->id))->toBeNull();
    });
});
```

---

## Tests

All tests must pass on **both** `composer test` (SQLite) and `composer test:mysql` (MySQL).

### Tenant Isolation Tests

1. ✅ Tenant A cannot see Tenant B's rows in queries (`get()`, `find()`, `first()`, `count()`, `paginate()`)
2. ✅ Querying without tenant context throws `MissingTenantContextException`
3. ✅ Implicit scope applies to all query methods (not just `all()`)

### Creation & Assignment Tests

4. ✅ Creating a model in tenant context auto-sets `tenant_id`
5. ✅ Creating with a mismatched `tenant_id` throws `InvalidTenantException`
6. ✅ Creating with no context throws `MissingTenantContextException`
7. ✅ `tenant_id` is not mass-assignable (attempting `fill(['tenant_id' => ...])` has no effect)

### Immutability Tests

8. ✅ Updating `tenant_id` throws `InvalidTenantException`
9. ✅ Saving a model instance with mismatched `tenant_id` throws `InvalidTenantException`
10. ✅ Deleting a model instance with mismatched `tenant_id` throws `InvalidTenantException`

### Route Model Binding Tests

11. ✅ Route binding returns the model for the correct tenant
12. ✅ Route binding returns 404 for another tenant's record

### Escape Hatch Tests

13. ✅ `runAs()` sets context and executes closure
14. ✅ `runAs()` clears context in finally block (even if closure throws)
15. ✅ `runAs()` throws if a tenant is already set
16. ✅ `runAs()` works in console (no prior context)

### Database-Level Tests

17. ✅ Inserting child row with mismatched `tenant_id` fails with FK constraint error (MySQL)
18. ✅ Composite unique constraint enforced: two rows with same slug but different tenant_id coexist

---

## Exceptions

### MissingTenantContextException

**File:** `app/Exceptions/MissingTenantContextException.php`

Thrown when:
- A query is attempted on a tenant-owned model with no `TenantContext` set
- A model is created/saved with no `TenantContext` set

**Should be caught/reported in:**
- Tests (fail loudly)
- Console (log and exit)
- HTTP requests (500 error — indicates a bug in middleware or routing)

### InvalidTenantException

**File:** `app/Exceptions/InvalidTenantException.php`

Thrown when:
- `tenant_id` mismatch on create, update, or save
- Attempting to change `tenant_id` on an existing model
- Attempting `runAs()` when a tenant is already set

---

## Files to Create/Modify

### New Files (10)
1. `app/Exceptions/MissingTenantContextException.php`
2. `app/Exceptions/InvalidTenantException.php`
3. `app/Scopes/TenantScope.php`
4. `app/Traits/BelongsToTenant.php`
5. `tests/Fixtures/migrations/0001_create_tenancy_test_parents.php`
6. `tests/Fixtures/migrations/0002_create_tenancy_test_children.php`
7. `tests/Fixtures/Models/TenancyTestParent.php`
8. `tests/Fixtures/Models/TenancyTestChild.php`
9. `tests/Feature/Tenancy/BelongsToTenantTest.php`
10. `tests/Feature/Tenancy/TenantScopeTest.php`

### Modified Files (3)
1. `app/Support/TenantContext.php` — Add `runAs()` method
2. `TENANCY.md` — Add escape hatch docs, DB::table() warning, composite FK pattern
3. `tests/Pest.php` — Add `inTenant()` helper

### Optional Files (1)
- `app/Macros/BlueprintTenancyMacro.php` — Helper macros for migrations (or document pattern inline)

---

## Success Criteria

✅ All 18+ tests pass on SQLite and MySQL  
✅ No tenant-owned rows visible across tenant boundaries  
✅ No way to create/update/delete rows with mismatched tenant_id  
✅ `runAs()` escape hatch documented and greppable  
✅ Composite FK pattern documented  
✅ TENANCY.md updated with all new rules  
✅ No product tables introduced (Phase 3 only)  
✅ All quality checks pass: `composer test`, `composer test:mysql`, `composer lint`, `composer analyse`
