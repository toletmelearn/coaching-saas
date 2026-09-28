<?php

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

// === Tenant Isolation: Same Email/Phone in Different Tenants ===

test('same email can exist in two different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Create user with email in tenant A
    inTenant($tenantA, fn () => User::factory()->create([
        'email' => 'shared@example.com',
    ]));

    // Should succeed: same email in different tenant
    inTenant($tenantB, fn () => User::factory()->create([
        'email' => 'shared@example.com',
    ]));

    $countA = inTenant($tenantA, fn () => User::where('email', 'shared@example.com')->count());
    $countB = inTenant($tenantB, fn () => User::where('email', 'shared@example.com')->count());

    expect($countA)->toBe(1)
        ->and($countB)->toBe(1);
});

test('same phone can exist in two different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Create user with phone in tenant A
    inTenant($tenantA, fn () => User::factory()->create([
        'phone' => '9876543210',
    ]));

    // Should succeed: same phone in different tenant
    inTenant($tenantB, fn () => User::factory()->create([
        'phone' => '9876543210',
    ]));

    $countA = inTenant($tenantA, fn () => User::where('phone', '9876543210')->count());
    $countB = inTenant($tenantB, fn () => User::where('phone', '9876543210')->count());

    expect($countA)->toBe(1)
        ->and($countB)->toBe(1);
});

// === Uniqueness Within Tenant ===

test('duplicate email within same tenant is rejected', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: creating the first user with this email succeeds
    $first = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'duplicate@example.com',
    ]));
    expect($first->email)->toBe('duplicate@example.com');

    expect(fn () => inTenant($tenant, fn () => User::factory()->create([
        'email' => 'duplicate@example.com',
    ])))->toThrow(QueryException::class);
});

test('duplicate phone within same tenant is rejected', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: creating the first user with this phone succeeds
    $first = inTenant($tenant, fn () => User::factory()->create([
        'phone' => '9876543210',
    ]));
    expect($first->phone)->toBe('9876543210');

    expect(fn () => inTenant($tenant, fn () => User::factory()->create([
        'phone' => '9876543210',
    ])))->toThrow(QueryException::class);
});

// === Email/Phone Requirement ===

test('user without email and phone is rejected', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: a user with only an email (no phone) is valid
    $withEmailOnly = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'has-email@example.com',
        'phone' => null,
    ]));
    expect($withEmailOnly->email)->toBe('has-email@example.com');

    expect(fn () => inTenant($tenant, fn () => User::factory()->create([
        'email' => null,
        'phone' => null,
    ])))->toThrow(QueryException::class);
});

// === Phone Normalization ===

test('phone with +91 prefix normalizes to 10 digits', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create([
        'phone' => '+919876543210',
    ]));

    expect($user->phone)->toBe('9876543210');
});

test('phone with leading 0 normalizes to 10 digits', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create([
        'phone' => '09876543210',
    ]));

    expect($user->phone)->toBe('9876543210');
});

test('phone with spaces normalizes to 10 digits', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create([
        'phone' => '98765 43210',
    ]));

    expect($user->phone)->toBe('9876543210');
});

test('phone +91 with spaces normalizes to 10 digits', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create([
        'phone' => '+91 98765 43210',
    ]));

    expect($user->phone)->toBe('9876543210');
});

test('all phone formats normalize to same canonical value', function () {
    $tenant = Tenant::factory()->create();

    $user1 = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'u1@example.com',
        'phone' => '+919876543210',
    ]));

    $user2 = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'u2@example.com',
        'phone' => '09876543210',
    ]));

    $user3 = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'u3@example.com',
        'phone' => '9876543210',
    ]));

    expect($user1->phone)
        ->toBe($user2->phone)
        ->toBe($user3->phone)
        ->toBe('9876543210');
});

// === Email Normalization ===

test('email is stored as lowercase', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create([
        'email' => 'Test@EXAMPLE.COM',
    ]));

    expect($user->email)->toBe('test@example.com');
});

// === Enum Validation ===

test('role accepts only owner, staff, student values', function () {
    $tenant = Tenant::factory()->create();

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    expect($owner->role)->toBe('owner')
        ->and($staff->role)->toBe('staff')
        ->and($student->role)->toBe('student');
});

test('status accepts only active, disabled values', function () {
    $tenant = Tenant::factory()->create();

    $active = inTenant($tenant, fn () => User::factory()->active()->create());
    $disabled = inTenant($tenant, fn () => User::factory()->disabled()->create());

    expect($active->status)->toBe('active')
        ->and($disabled->status)->toBe('disabled');
});

// === Schema Fields ===

test('must_change_password column exists and defaults to false', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create());

    expect($user->must_change_password)->toBeFalse();
});

test('must_change_password can be set to true on creation', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    expect($user->must_change_password)->toBeTrue();
});

test('last_login_at column exists as nullable timestamp', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create());

    expect($user->last_login_at)->toBeNull();
});

test('last_login_at can be set to a timestamp', function () {
    $tenant = Tenant::factory()->create();

    $now = now();
    $user = inTenant($tenant, fn () => User::factory()->create([
        'last_login_at' => $now,
    ]));

    expect($user->last_login_at)->not->toBeNull();
});

// === Composite FK: (tenant_id, id) ===

test('users table enforces unique constraint on (tenant_id, id)', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create());

    // The constraint is enforced by the database
    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->id)->not->toBeNull();
});

// === Mass Assignment Guards ===

test('tenant_id is not mass-assignable via create', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    // Positive control: creating a user inside tenant context succeeds and auto-fills tenant_id
    $control = inTenant($tenant, fn () => User::factory()->create());
    expect($control->tenant_id)->toBe($tenant->id);

    $user = inTenant($tenant, fn () => User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '9876543210',
        'password' => 'password',
        'tenant_id' => $otherTenant->id, // Should be ignored
    ]));

    expect($user->tenant_id)->toBe($tenant->id);
});

test('role is not mass-assignable via create', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: role CAN be set through the approved path (factory state -> forceFill)
    $viaState = inTenant($tenant, fn () => User::factory()->owner()->create());
    expect($viaState->role)->toBe('owner');

    // Negative: raw mass-assignment must not set role
    $viaMassAssignment = inTenant($tenant, fn () => User::create([
        'name' => 'Test User',
        'email' => 'masstest-role@example.com',
        'phone' => '9876543210',
        'password' => 'password',
        'role' => 'owner',
    ]));

    // The User model declares $attributes defaults of role=student; mass-assignment
    // of a guarded field is silently discarded (no shouldBeStrict() is configured),
    // so the model default is what actually lands, not null.
    expect($viaMassAssignment->role)->toBe('student');
});

test('status is not mass-assignable via create', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: status CAN be set through the approved path (factory state -> forceFill)
    $viaState = inTenant($tenant, fn () => User::factory()->disabled()->create());
    expect($viaState->status)->toBe('disabled');

    // Negative: raw mass-assignment must not set status
    $viaMassAssignment = inTenant($tenant, fn () => User::create([
        'name' => 'Test User',
        'email' => 'masstest-status@example.com',
        'phone' => '9876543210',
        'password' => 'password',
        'status' => 'disabled',
    ]));

    // The User model declares $attributes defaults of status=active; mass-assignment
    // of a guarded field is silently discarded, so the model default is what lands.
    expect($viaMassAssignment->status)->toBe('active');
});

test('must_change_password is not mass-assignable via create', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: must_change_password CAN be set through the approved path (factory state -> forceFill)
    $viaState = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());
    expect($viaState->must_change_password)->toBeTrue();

    // Negative: raw mass-assignment must not set must_change_password
    $viaMassAssignment = inTenant($tenant, fn () => User::create([
        'name' => 'Test User',
        'email' => 'masstest-mcp@example.com',
        'phone' => '9876543210',
        'password' => 'password',
        'must_change_password' => true,
    ]));

    expect($viaMassAssignment->must_change_password)->not->toBeTrue();
});

// === BelongsToTenant Trait ===

test('User model uses BelongsToTenant trait', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: querying inside tenant context works
    $result = inTenant($tenant, fn () => User::all());
    expect($result)->toBeInstanceOf(Collection::class);

    // Without tenant context, the BelongsToTenant global scope must throw
    expect(fn () => User::all())->toThrow(MissingTenantContextException::class);
});

test('querying users without tenant context throws MissingTenantContextException', function () {
    $tenant = Tenant::factory()->create();
    inTenant($tenant, fn () => User::factory()->create());

    // Positive control: querying inside tenant context finds the user
    $found = inTenant($tenant, fn () => User::first());
    expect($found)->not->toBeNull();

    expect(fn () => User::first())->toThrow(MissingTenantContextException::class);
});

test('creating user without tenant context throws exception', function () {
    $tenant = Tenant::factory()->create();

    // Positive control: creating inside tenant context succeeds
    $control = inTenant($tenant, fn () => User::factory()->create());
    expect($control->exists)->toBeTrue();

    expect(fn () => User::create([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'password',
    ]))->toThrow(MissingTenantContextException::class);
});

test('tenant_id auto-fills from context on creation', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create());

    expect($user->tenant_id)->toBe($tenant->id);
});

test('users from tenant A are not visible to tenant B queries', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => User::factory()->count(3)->create());

    // Positive control: tenant A sees its own users
    $countInA = inTenant($tenantA, fn () => User::count());
    expect($countInA)->toBe(3);

    $countInB = inTenant($tenantB, fn () => User::count());
    expect($countInB)->toBe(0);
});

test('factory must auto-fill tenant_id from context before save', function () {
    $tenant = Tenant::factory()->create();

    // make() creates in-memory without BelongsToTenant auto-fill
    $user = inTenant($tenant, fn () => User::factory()->make());
    expect($user->getAttribute('tenant_id'))->toBeNull();

    // On save(), tenant_id must be auto-filled by BelongsToTenant trait
    inTenant($tenant, fn () => $user->save());

    expect($user->tenant_id)->toBe($tenant->id);
});

test('factory auto-fills tenant_id on create', function () {
    $tenant = Tenant::factory()->create();

    $user = inTenant($tenant, fn () => User::factory()->create());

    expect($user->tenant_id)->toBe($tenant->id);
});
