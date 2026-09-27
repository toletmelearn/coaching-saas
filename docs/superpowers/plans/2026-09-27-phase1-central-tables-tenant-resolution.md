# Phase 1: Central Tables and Tenant Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the four central tables (`tenants`, `tenant_domains`, `platform_admins`,
`system_settings`) and hostname-based tenant resolution middleware, so later phases have a
`TenantContext` to build `BelongsToTenant` and tenant-owned tables on top of.

**Architecture:** Four independent Eloquent models backed by four migrations, a
container-scoped `TenantContext` value holder, and two middleware (`ResolveTenant` looks up
the request host and binds the tenant or 404s; `RequireTenant` 404s if no tenant is bound).
No tenant-owned tables or `BelongsToTenant` trait yet — nothing exists to scope.

**Tech Stack:** Laravel 13 / PHP 8.3, Pest 4, PHP backed enums for status/type columns,
Laravel's built-in `scoped()` container binding for per-request/per-job reset.

**Spec:** [docs/superpowers/specs/2026-09-27-phase1-central-tables-tenant-resolution-design.md](../specs/2026-09-27-phase1-central-tables-tenant-resolution-design.md)

## Global Constraints

- No `bunny_library_id` or `payment_gateway_config` on `tenants` (deferred to Phase 6 / Stage C).
- `status` columns are plain `string` in the database, cast to PHP backed enums in the model — never a DB `ENUM` type.
- `tenant_domains.tenant_id` foreign key uses `restrictOnDelete()`, not cascade.
- Domains are normalized lowercase, no trailing dot, both at write time (model attribute mutator) and at lookup time (middleware) — defense in depth.
- `TenantContext` is bound with `scoped()`, not `singleton()`.
- `ResolveTenant` does not check tenant `status` — that's `EnsureTenantActive` in Phase 4. Do not add status enforcement now.
- Every test added under `tests/Feature/Tenancy/` must pass under both `composer test` and `composer test:mysql` (run both before every commit that touches this directory).
- No platform-admin routes/controllers/views in this phase — guard config only.

## Review Focus

- **Trailing-dot / uppercase host requests** (a real browser or curl artifact) — must resolve identically to the canonical lowercased, dot-stripped form, not 404. Covered in Task 7.
- **Subdomain-suffix spoofing** (`demo.coaching.test.evil.com`) — must not fall through to the `demo.coaching.test` tenant via a loose `LIKE`/suffix match. Covered in Task 7 (exact-match assertion).
- **Central domain accidentally matching a tenant domain row** — if `coaching.test` itself were ever inserted into `tenant_domains`, central-domain check must still win (checked first, before any DB lookup). Covered in Task 7.
- **Context bleed across requests/jobs** — because Laravel resolves `scoped()` bindings once per request *and* Pest/Testbench can reuse the container across test cases in the same process, a test must prove a fresh `TenantContext` instance has no tenant, not just assert on the same instance that was set. Covered in Task 6.
- **`RequireTenant` on a central-domain request** — the two middleware must compose correctly (i.e., `ResolveTenant` allows the request through with no tenant bound, then `RequireTenant` on that same request correctly rejects it) — not just each middleware tested in isolation. Covered in Task 8.

---

### Task 1: `tenants` table, model, enum, factory

**Files:**
- Create: `database/migrations/2026_09_27_000001_create_tenants_table.php`
- Create: `app/Enums/TenantStatus.php`
- Create: `app/Models/Tenant.php`
- Create: `database/factories/TenantFactory.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Tenancy/TenantTest.php`

**Interfaces:**
- Produces: `App\Enums\TenantStatus` (backed string enum: `Trial='trial'`, `Active='active'`, `Suspended='suspended'`, `Archived='archived'`).
- Produces: `App\Models\Tenant` with fillable `name`, `status`, `timezone`, `currency`; `status` cast to `TenantStatus`; `domains(): HasMany` (added in Task 2, declared here as a stub relation is NOT added yet — Task 2 adds the method since `TenantDomain` doesn't exist until then).
- Produces: `Tenant::factory()` — `name` (word), `status` defaults to `TenantStatus::Active`, `timezone` defaults `'Asia/Kolkata'`, `currency` defaults `'INR'`.

- [ ] **Step 1: Add `RefreshDatabase` to Pest's Feature tests**

Every test in this plan touches the database, so wire it once now.

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
```

Replace the full contents of `tests/Pest.php` with the above.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Tenancy/TenantTest.php`:

```php
<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

test('a tenant can be created with default factory attributes', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->timezone)->toBe('Asia/Kolkata')
        ->and($tenant->currency)->toBe('INR');
});

test('tenant status is cast to the TenantStatus enum', function () {
    $tenant = Tenant::factory()->create(['status' => 'suspended']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantTest.php`
Expected: FAIL — `Class "App\Models\Tenant" not found` (model, migration, enum, factory don't exist yet).

- [ ] **Step 4: Create the enum**

Create `app/Enums/TenantStatus.php`:

```php
<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
```

- [ ] **Step 5: Create the migration**

Create `database/migrations/2026_09_27_000001_create_tenants_table.php`:

```php
<?php

use App\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default(TenantStatus::Trial->value);
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('currency')->default('INR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
```

- [ ] **Step 6: Create the model**

Create `app/Models/Tenant.php`:

```php
<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'timezone', 'currency'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
```

- [ ] **Step 7: Create the factory**

Create `database/factories/TenantFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'status' => TenantStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
        ];
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantTest.php`
Expected: PASS (2 tests)

- [ ] **Step 9: Commit**

```bash
git add tests/Pest.php database/migrations/2026_09_27_000001_create_tenants_table.php app/Enums/TenantStatus.php app/Models/Tenant.php database/factories/TenantFactory.php tests/Feature/Tenancy/TenantTest.php
git commit -m "Add tenants table, model, and factory"
```

---

### Task 2: `tenant_domains` table, model, enum, factory

**Files:**
- Create: `database/migrations/2026_09_27_000002_create_tenant_domains_table.php`
- Create: `app/Enums/DomainType.php`
- Create: `app/Models/TenantDomain.php`
- Modify: `app/Models/Tenant.php` (add `domains()` relation)
- Create: `database/factories/TenantDomainFactory.php`
- Test: `tests/Feature/Tenancy/TenantDomainTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant` (Task 1).
- Produces: `App\Enums\DomainType` (`Subdomain='subdomain'`, `Custom='custom'`).
- Produces: `App\Models\TenantDomain` with fillable `tenant_id`, `domain`, `type`,
  `is_primary`, `verified_at`; `type` cast to `DomainType`; `is_primary` cast `boolean`;
  `verified_at` cast `datetime`; a `domain` attribute mutator lowercasing and stripping a
  trailing dot; `tenant(): BelongsTo`.
- Produces: `Tenant::domains(): HasMany`.
- Produces: `TenantDomain::factory()` — `tenant_id` via `Tenant::factory()`, `domain`
  unique fake subdomain, `type` defaults `DomainType::Subdomain`, `is_primary` defaults
  `true`, `verified_at` defaults `now()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/TenantDomainTest.php`:

```php
<?php

use App\Enums\DomainType;
use App\Models\Tenant;
use App\Models\TenantDomain;

test('a tenant domain belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant)->create();

    expect($domain->tenant->id)->toBe($tenant->id)
        ->and($tenant->domains()->first()->id)->toBe($domain->id);
});

test('domain is normalized to lowercase without a trailing dot', function () {
    $domain = TenantDomain::factory()->create(['domain' => 'DEMO.Coaching.Test.']);

    expect($domain->domain)->toBe('demo.coaching.test');
});

test('domain type casts to the DomainType enum', function () {
    $domain = TenantDomain::factory()->create(['type' => 'custom']);

    expect($domain->fresh()->type)->toBe(DomainType::Custom);
});

test('a tenant cannot be deleted while a domain still references it', function () {
    $domain = TenantDomain::factory()->create();

    expect(fn () => $domain->tenant->delete())->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantDomainTest.php`
Expected: FAIL — `Class "App\Models\TenantDomain" not found`.

- [ ] **Step 3: Create the enum**

Create `app/Enums/DomainType.php`:

```php
<?php

namespace App\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';
}
```

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_09_27_000002_create_tenant_domains_table.php`:

```php
<?php

use App\Enums\DomainType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('domain')->unique();
            $table->string('type')->default(DomainType::Subdomain->value);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
```

- [ ] **Step 5: Create the model**

Create `app/Models/TenantDomain.php`:

```php
<?php

namespace App\Models;

use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'domain', 'type', 'is_primary', 'verified_at'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected function domain(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => rtrim(strtolower($value), '.'),
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 6: Add the inverse relation to `Tenant`**

In `app/Models/Tenant.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and this method inside the class:

```php
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }
```

- [ ] **Step 7: Create the factory**

Create `database/factories/TenantDomainFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\DomainType;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => $this->faker->unique()->domainWord().'.coaching.test',
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'verified_at' => now(),
        ];
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantDomainTest.php`
Expected: PASS (4 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_27_000002_create_tenant_domains_table.php app/Enums/DomainType.php app/Models/TenantDomain.php app/Models/Tenant.php database/factories/TenantDomainFactory.php tests/Feature/Tenancy/TenantDomainTest.php
git commit -m "Add tenant_domains table, model, and factory"
```

---

### Task 3: `platform_admins` table, model, enum, factory, auth guard

**Files:**
- Create: `database/migrations/2026_09_27_000003_create_platform_admins_table.php`
- Create: `app/Enums/PlatformAdminStatus.php`
- Create: `app/Models/PlatformAdmin.php`
- Create: `database/factories/PlatformAdminFactory.php`
- Modify: `config/auth.php`
- Test: `tests/Feature/Tenancy/PlatformAdminTest.php`

**Interfaces:**
- Produces: `App\Enums\PlatformAdminStatus` (`Active='active'`, `Suspended='suspended'`).
- Produces: `App\Models\PlatformAdmin extends Illuminate\Foundation\Auth\User` (so it's a
  valid `Authenticatable` for the new guard) with fillable `name`, `email`, `password`,
  `status`, `last_login_at`; `password` cast `hashed`; `status` cast to
  `PlatformAdminStatus`; `last_login_at` cast `datetime`.
- Produces: `PlatformAdmin::factory()`.
- Produces: `auth.guards.platform_admin` (session driver, `platform_admins` provider) and
  `auth.providers.platform_admins` (eloquent, `App\Models\PlatformAdmin`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/PlatformAdminTest.php`:

```php
<?php

use App\Enums\PlatformAdminStatus;
use App\Models\PlatformAdmin;

test('a platform admin can be created and authenticated on its own guard', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin');

    expect(auth('platform_admin')->id())->toBe($admin->id)
        ->and($admin->status)->toBe(PlatformAdminStatus::Active);
});

test('platform admin password is hashed', function () {
    $admin = PlatformAdmin::factory()->create(['password' => 'plain-text-password']);

    expect($admin->password)->not->toBe('plain-text-password')
        ->and(\Illuminate\Support\Facades\Hash::check('plain-text-password', $admin->password))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/PlatformAdminTest.php`
Expected: FAIL — `Class "App\Models\PlatformAdmin" not found`.

- [ ] **Step 3: Create the enum**

Create `app/Enums/PlatformAdminStatus.php`:

```php
<?php

namespace App\Enums;

enum PlatformAdminStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
```

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_09_27_000003_create_platform_admins_table.php`:

```php
<?php

use App\Enums\PlatformAdminStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status')->default(PlatformAdminStatus::Active->value);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
```

- [ ] **Step 5: Create the model**

Create `app/Models/PlatformAdmin.php`:

```php
<?php

namespace App\Models;

use App\Enums\PlatformAdminStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PlatformAdmin extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password', 'status', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => PlatformAdminStatus::class,
            'last_login_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 6: Create the factory**

Create `database/factories/PlatformAdminFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\PlatformAdminStatus;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class PlatformAdminFactory extends Factory
{
    protected $model = PlatformAdmin::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => PlatformAdminStatus::Active,
            'last_login_at' => null,
        ];
    }
}
```

- [ ] **Step 7: Register the auth guard and provider**

In `config/auth.php`, change the `'guards' => [...]` array to:

```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'platform_admin' => [
            'driver' => 'session',
            'provider' => 'platform_admins',
        ],
    ],
```

And change the `'providers' => [...]` array to:

```php
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'platform_admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\PlatformAdmin::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/PlatformAdminTest.php`
Expected: PASS (2 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_27_000003_create_platform_admins_table.php app/Enums/PlatformAdminStatus.php app/Models/PlatformAdmin.php database/factories/PlatformAdminFactory.php config/auth.php tests/Feature/Tenancy/PlatformAdminTest.php
git commit -m "Add platform_admins table, model, and auth guard"
```

---

### Task 4: `system_settings` table and model

**Files:**
- Create: `database/migrations/2026_09_27_000004_create_system_settings_table.php`
- Create: `app/Models/SystemSetting.php`
- Test: `tests/Feature/Tenancy/SystemSettingTest.php`

**Interfaces:**
- Produces: `App\Models\SystemSetting::get(string $key, mixed $default = null): mixed`
- Produces: `App\Models\SystemSetting::set(string $key, mixed $value): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/SystemSettingTest.php`:

```php
<?php

use App\Models\SystemSetting;

test('get returns the default when the key does not exist', function () {
    expect(SystemSetting::get('missing_key', 'fallback'))->toBe('fallback');
});

test('set stores a value and get retrieves it', function () {
    SystemSetting::set('max_tenants', 500);

    expect(SystemSetting::get('max_tenants'))->toBe(500);
});

test('set overwrites an existing value for the same key', function () {
    SystemSetting::set('feature_flag', ['enabled' => true]);
    SystemSetting::set('feature_flag', ['enabled' => false]);

    expect(SystemSetting::get('feature_flag'))->toBe(['enabled' => false])
        ->and(SystemSetting::query()->where('key', 'feature_flag')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/SystemSettingTest.php`
Expected: FAIL — `Class "App\Models\SystemSetting" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_09_27_000004_create_system_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/SystemSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/SystemSettingTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_27_000004_create_system_settings_table.php app/Models/SystemSetting.php tests/Feature/Tenancy/SystemSettingTest.php
git commit -m "Add system_settings table and model"
```

---

### Task 5: `config/tenancy.php` and central domain env vars

**Files:**
- Create: `config/tenancy.php`
- Modify: `.env`
- Modify: `.env.example`
- Test: `tests/Feature/Tenancy/TenancyConfigTest.php`

**Interfaces:**
- Produces: `config('tenancy.central_domains')` — array of lowercase hostnames.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/TenancyConfigTest.php`:

```php
<?php

test('central domains config contains the default local hostnames', function () {
    expect(config('tenancy.central_domains'))
        ->toBeArray()
        ->toContain('coaching.test')
        ->toContain('platform.coaching.test')
        ->toContain('localhost')
        ->toContain('127.0.0.1');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenancyConfigTest.php`
Expected: FAIL — `Undefined array key "central_domains"` (config file doesn't exist yet).

- [ ] **Step 3: Create the config file**

Create `config/tenancy.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Central Domains
    |--------------------------------------------------------------------------
    |
    | Hostnames in this list are never looked up in tenant_domains — they are
    | where the platform marketing site / admin panel lives, plus the bare
    | local development hosts. See TENANCY.md for the fail-closed resolution
    | rule this list supports.
    |
    */

    'central_domains' => array_map(
        static fn (string $domain): string => trim(strtolower($domain)),
        explode(',', env('CENTRAL_DOMAINS', 'localhost,127.0.0.1,coaching.test,platform.coaching.test')),
    ),
];
```

- [ ] **Step 4: Add the env var**

In `.env`, add after the `APP_URL` line:

```
CENTRAL_DOMAINS=localhost,127.0.0.1,coaching.test,platform.coaching.test
```

In `.env.example`, add the same line in the same place.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenancyConfigTest.php`
Expected: PASS (1 test)

- [ ] **Step 6: Commit**

```bash
git add config/tenancy.php .env.example tests/Feature/Tenancy/TenancyConfigTest.php
git commit -m "Add tenancy config with central domains"
```

Note: `.env` is gitignored — it won't be part of this commit, but must be edited locally
for the app to have the setting (the config default covers it even without the env var, so
this is a documentation/consistency step, not a functional requirement).

---

### Task 6: `TenantContext`

**Files:**
- Create: `app/Support/TenantContext.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Tenancy/TenantContextTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant` (Task 1).
- Produces: `App\Support\TenantContext` with `set(Tenant $tenant): void` (throws
  `RuntimeException` if already set), `has(): bool`, `get(): Tenant` (throws
  `RuntimeException` if not set), `id(): int`.
- Produces: `App\Support\TenantContext` bound `scoped()` in the container — later tasks
  resolve it via `app(TenantContext::class)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/TenantContextTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Support\TenantContext;

test('has and get reflect the empty state before set is called', function () {
    $context = app(TenantContext::class);

    expect($context->has())->toBeFalse();
    expect(fn () => $context->get())->toThrow(RuntimeException::class);
});

test('set stores the tenant and get/has/id reflect it', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->set($tenant);

    expect($context->has())->toBeTrue()
        ->and($context->get()->id)->toBe($tenant->id)
        ->and($context->id())->toBe($tenant->id);
});

test('set throws if called twice', function () {
    $context = app(TenantContext::class);
    $context->set(Tenant::factory()->create());

    expect(fn () => $context->set(Tenant::factory()->create()))->toThrow(RuntimeException::class);
});

test('a fresh instance from the container has no tenant, proving the binding is scoped not shared', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    expect($context->has())->toBeTrue();

    // Forget the scoped instance the way Laravel does between requests/jobs.
    app()->forgetScopedInstances();

    $fresh = app(TenantContext::class);

    expect($fresh)->not->toBe($context)
        ->and($fresh->has())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantContextTest.php`
Expected: FAIL — `Class "App\Support\TenantContext" not found`.

- [ ] **Step 3: Create `TenantContext`**

Create `app/Support/TenantContext.php`:

```php
<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $set = false;

    public function set(Tenant $tenant): void
    {
        if ($this->set) {
            throw new RuntimeException('TenantContext is already set for this request.');
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
            throw new RuntimeException('TenantContext has not been set.');
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }
}
```

- [ ] **Step 4: Bind it scoped in the container**

In `app/Providers/AppServiceProvider.php`, add the import
`use App\Support\TenantContext;` and this line inside `register()`:

```php
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantContextTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Support/TenantContext.php app/Providers/AppServiceProvider.php tests/Feature/Tenancy/TenantContextTest.php
git commit -m "Add scoped TenantContext container binding"
```

---

### Task 7: `ResolveTenant` middleware

**Files:**
- Create: `app/Http/Middleware/ResolveTenant.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Tenancy/ResolveTenantTest.php`

**Interfaces:**
- Consumes: `App\Models\TenantDomain` (Task 2), `App\Support\TenantContext` (Task 6),
  `config('tenancy.central_domains')` (Task 5).
- Produces: `App\Http\Middleware\ResolveTenant`, registered first in the `web` middleware
  group and aliased as `'resolve.tenant'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/ResolveTenantTest.php`. It registers a temporary route inline
so the test doesn't depend on any real application route existing yet.

```php
<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/tenant-context-probe', function (TenantContext $context) {
        return response()->json([
            'has' => $context->has(),
            'tenant_id' => $context->has() ? $context->id() : null,
        ]);
    })->middleware('web');
});

test('a central domain resolves with no tenant bound', function () {
    $response = $this->get('http://coaching.test/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => false, 'tenant_id' => null]);
});

test('a known tenant domain resolves to the correct tenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('an unknown domain returns 404', function () {
    $response = $this->get('http://nobody-here.coaching.test/tenant-context-probe');

    $response->assertNotFound();
});

test('a subdomain-suffix spoof does not resolve to the real tenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test.evil.com/tenant-context-probe');

    $response->assertNotFound();
});

test('uppercase host resolves the same as lowercase', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://DEMO.COACHING.TEST/tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('trailing-dot host resolves the same as without', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $response = $this->get('http://demo.coaching.test./tenant-context-probe');

    $response->assertOk()->assertJson(['has' => true, 'tenant_id' => $tenant->id]);
});

test('two tenant domains resolve independently', function () {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();
    TenantDomain::factory()->for($first)->create(['domain' => 'first.coaching.test']);
    TenantDomain::factory()->for($second)->create(['domain' => 'second.coaching.test']);

    $this->get('http://first.coaching.test/tenant-context-probe')
        ->assertJson(['has' => true, 'tenant_id' => $first->id]);

    $this->get('http://second.coaching.test/tenant-context-probe')
        ->assertJson(['has' => true, 'tenant_id' => $second->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/ResolveTenantTest.php`
Expected: FAIL — every request currently hits the route with no tenant middleware, so the
central-domain test passes accidentally but the tenant-resolution tests fail (`has` is
always `false`).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/ResolveTenant.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\TenantDomain;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = rtrim(strtolower($request->getHost()), '.');

        if (in_array($host, config('tenancy.central_domains', []), true)) {
            return $next($request);
        }

        $tenantDomain = TenantDomain::query()->where('domain', $host)->first();

        if ($tenantDomain === null) {
            abort(404);
        }

        app(TenantContext::class)->set($tenantDomain->tenant);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware**

In `bootstrap/app.php`, add the import
`use App\Http\Middleware\ResolveTenant;` and change the `withMiddleware` call to:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            ResolveTenant::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
        ]);
    })
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/ResolveTenantTest.php`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/ResolveTenant.php bootstrap/app.php tests/Feature/Tenancy/ResolveTenantTest.php
git commit -m "Add ResolveTenant middleware for hostname-based tenant resolution"
```

---

### Task 8: `RequireTenant` middleware

**Files:**
- Create: `app/Http/Middleware/RequireTenant.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Tenancy/RequireTenantTest.php`

**Interfaces:**
- Consumes: `App\Support\TenantContext` (Task 6).
- Produces: `App\Http\Middleware\RequireTenant`, aliased as `'require.tenant'` (not
  prepended globally — applied per-route-group by later phases).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/RequireTenantTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/tenant-required-probe', fn () => response()->json(['ok' => true]))
        ->middleware(['web', 'require.tenant']);
});

test('a request on a known tenant domain passes RequireTenant', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['domain' => 'demo.coaching.test']);

    $this->get('http://demo.coaching.test/tenant-required-probe')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('a request on a central domain is rejected by RequireTenant', function () {
    $this->get('http://coaching.test/tenant-required-probe')
        ->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/RequireTenantTest.php`
Expected: FAIL — `require.tenant` alias is undefined.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/RequireTenant.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TenantContext::class)->has()) {
            abort(404);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, add the import `use App\Http\Middleware\RequireTenant;` and update
the alias array added in Task 7 to:

```php
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'require.tenant' => RequireTenant::class,
        ]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/RequireTenantTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/RequireTenant.php bootstrap/app.php tests/Feature/Tenancy/RequireTenantTest.php
git commit -m "Add RequireTenant middleware"
```

---

### Task 9: Demo tenant seeder

**Files:**
- Create: `database/seeders/TenantSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Tenancy/TenantSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant`, `App\Models\TenantDomain`, `App\Enums\DomainType`.
- Produces: `Database\Seeders\TenantSeeder` — idempotent (safe to run more than once
  without creating duplicate rows), called from `DatabaseSeeder::run()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenancy/TenantSeederTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use Database\Seeders\TenantSeeder;

test('the seeder creates one demo tenant with a verified subdomain', function () {
    $this->seed(TenantSeeder::class);

    $domain = TenantDomain::query()->where('domain', 'demo.coaching.test')->first();

    expect($domain)->not->toBeNull()
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull()
        ->and(Tenant::query()->count())->toBe(1);
});

test('running the seeder twice does not create duplicate tenants', function () {
    $this->seed(TenantSeeder::class);
    $this->seed(TenantSeeder::class);

    expect(Tenant::query()->count())->toBe(1)
        ->and(TenantDomain::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantSeederTest.php`
Expected: FAIL — `Class "Database\Seeders\TenantSeeder" not found`.

- [ ] **Step 3: Create the seeder**

Create `database/seeders/TenantSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Demo Institute'],
            ['status' => TenantStatus::Active],
        );

        TenantDomain::query()->firstOrCreate(
            ['domain' => 'demo.coaching.test'],
            [
                'tenant_id' => $tenant->id,
                'type' => DomainType::Subdomain,
                'is_primary' => true,
                'verified_at' => now(),
            ],
        );
    }
}
```

- [ ] **Step 4: Wire it into `DatabaseSeeder`**

In `database/seeders/DatabaseSeeder.php`, add `$this->call(TenantSeeder::class);` as the
first line of `run()`, before the existing `User::factory()->create(...)` call.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Tenancy/TenantSeederTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/seeders/TenantSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Tenancy/TenantSeederTest.php
git commit -m "Add demo tenant seeder"
```

---

### Task 10: Full-suite verification and CLAUDE.md housekeeping

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:** none (documentation + verification only).

- [ ] **Step 1: Replace `CLAUDE.md`'s placeholder content**

Replace the entire contents of `CLAUDE.md` with:

```markdown
# Coaching-SaaS — Instructions for Claude

Before doing any work in this repository, read:

1. [AGENT_RULES.md](AGENT_RULES.md) — the 20 global rules for working on this codebase.
2. [TENANCY.md](TENANCY.md) — how tenant isolation is resolved and enforced.
3. [SECURITY.md](SECURITY.md) — session, storage, and credential handling rules.

These are not optional background reading — they encode invariants (fail-closed tenant
resolution, composite foreign keys, session-per-subdomain, signed URLs for private storage)
that later phases depend on holding. A change that violates one of them is a bug even if it
passes tests that don't happen to check for it.

See also [ARCHITECTURE.md](ARCHITECTURE.md), [VIDEO.md](VIDEO.md),
[PAYMENTS.md](PAYMENTS.md), [PRIVACY.md](PRIVACY.md), and [ROADMAP.md](ROADMAP.md) for the
rest of the system design.
```

- [ ] **Step 2: Run the full suite against SQLite**

Run: `composer test`
Expected: all tests pass, including every test added in Tasks 1–9.

- [ ] **Step 3: Run the full suite against MySQL**

Run: `composer test:mysql`
Expected: all tests under `tests/Feature/Tenancy` pass (same tests as Step 2, against
`coaching_saas_test`).

- [ ] **Step 4: Run lint and static analysis**

Run: `composer lint` then `composer analyse`
Expected: both pass with no errors.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "Point CLAUDE.md at AGENT_RULES, TENANCY, and SECURITY docs"
```

Note: replacing `AGENT_RULES.md`'s content with the user-supplied official 20 rules is a
separate, explicit step outside this plan — do it only once the user has actually pasted
that content in the conversation, then commit it on its own.
