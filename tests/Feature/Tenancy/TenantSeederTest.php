<?php

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TenantContext;
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

    // 2, not 1: demo.coaching.test and demo.localhost (Phase 6 — the second domain
    // lets the PWA be tested over a secure context locally; see TenantSeeder). Still
    // exactly 2 after seeding twice is the actual "no duplicates" assertion here.
    expect(Tenant::query()->count())->toBe(1)
        ->and(TenantDomain::query()->count())->toBe(2);
});

// === Demo user seeding is local/testing only ===

test('the seeder creates a demo owner and student in the testing environment', function () {
    // Positive control: the current test environment (testing) does seed demo users
    $this->seed(TenantSeeder::class);

    $tenant = Tenant::query()->where('name', 'Demo Institute')->firstOrFail();

    $users = app(TenantContext::class)->runAs($tenant, fn () => User::query()->pluck('email')->all());

    expect($users)->toContain('owner@demo.coaching.test')
        ->and($users)->toContain('student@demo.coaching.test');
});

test('demo seeder creates no users when environment is production', function () {
    app()->instance('env', 'production');

    // Invoke the seeder directly (rather than via the `db:seed` artisan command through
    // $this->seed()) so this test isn't also exercising Laravel's production
    // confirmation prompt for console commands — a separate, unrelated concern.
    app(TenantSeeder::class)->run();

    // The tenant/domain are still seeded; only the demo user credentials are skipped.
    $tenant = Tenant::query()->where('name', 'Demo Institute')->firstOrFail();

    $userCount = app(TenantContext::class)->runAs($tenant, fn () => User::query()->count());

    expect($userCount)->toBe(0);
});
