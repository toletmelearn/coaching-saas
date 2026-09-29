<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TenantContext;

test('creates a tenant, its domain, and an owner with a temporary password', function () {
    $this->artisan('tenant:create', [
        'name' => 'Bright Future Academy',
        'subdomain' => 'brightfuture',
        '--owner-name' => 'Priya Sharma',
        '--owner-email' => 'priya@example.com',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('name', 'Bright Future Academy')->firstOrFail();
    $domain = TenantDomain::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($domain->domain)->toBe('brightfuture.'.config('tenancy.tenant_base_domain'))
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull();

    $owner = app(TenantContext::class)->runAs($tenant, fn () => User::where('email', 'priya@example.com')->firstOrFail());

    expect($owner->name)->toBe('Priya Sharma')
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->must_change_password)->toBeTrue();
});

test('accepts an owner phone instead of an email', function () {
    $this->artisan('tenant:create', [
        'name' => 'Phone Owner Academy',
        'subdomain' => 'phoneowner',
        '--owner-name' => 'Rahul Verma',
        '--owner-phone' => '9876543210',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('name', 'Phone Owner Academy')->firstOrFail();
    $owner = app(TenantContext::class)->runAs($tenant, fn () => User::where('phone', '9876543210')->first());

    expect($owner)->not->toBeNull();
});

test('requires at least one of --owner-email or --owner-phone', function () {
    $this->artisan('tenant:create', [
        'name' => 'No Contact Academy',
        'subdomain' => 'nocontact',
        '--owner-name' => 'Someone',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'No Contact Academy')->exists())->toBeFalse();
});

test('rejects a subdomain shorter than 3 characters', function () {
    $this->artisan('tenant:create', [
        'name' => 'Short',
        'subdomain' => 'ab',
        '--owner-name' => 'Owner',
        '--owner-email' => 'short@example.com',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'Short')->exists())->toBeFalse();
});

test('rejects a subdomain longer than 30 characters', function () {
    $this->artisan('tenant:create', [
        'name' => 'Long',
        'subdomain' => str_repeat('a', 31),
        '--owner-name' => 'Owner',
        '--owner-email' => 'long@example.com',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'Long')->exists())->toBeFalse();
});

test('rejects a subdomain with uppercase or invalid characters', function () {
    // Positive control: a valid subdomain of the same length succeeds.
    $this->artisan('tenant:create', [
        'name' => 'Valid Chars',
        'subdomain' => 'valid-chars',
        '--owner-name' => 'Owner',
        '--owner-email' => 'validchars@example.com',
    ])->assertSuccessful();

    $this->artisan('tenant:create', [
        'name' => 'Invalid Chars',
        'subdomain' => 'Invalid_Chars!',
        '--owner-name' => 'Owner',
        '--owner-email' => 'invalidchars@example.com',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'Invalid Chars')->exists())->toBeFalse();
});

test('rejects a subdomain starting or ending with a hyphen', function () {
    $this->artisan('tenant:create', [
        'name' => 'Leading Hyphen',
        'subdomain' => '-leadinghyphen',
        '--owner-name' => 'Owner',
        '--owner-email' => 'leading@example.com',
    ])->assertFailed();

    $this->artisan('tenant:create', [
        'name' => 'Trailing Hyphen',
        'subdomain' => 'trailinghyphen-',
        '--owner-name' => 'Owner',
        '--owner-email' => 'trailing@example.com',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'Leading Hyphen')->exists())->toBeFalse()
        ->and(Tenant::query()->where('name', 'Trailing Hyphen')->exists())->toBeFalse();
});

test('rejects reserved subdomains', function () {
    foreach (['www', 'admin', 'api', 'demo'] as $reserved) {
        $this->artisan('tenant:create', [
            'name' => 'Reserved '.$reserved,
            'subdomain' => $reserved,
            '--owner-name' => 'Owner',
            '--owner-email' => $reserved.'@example.com',
        ])->assertFailed();
    }

    expect(Tenant::query()->where('name', 'like', 'Reserved %')->exists())->toBeFalse();
});

test('refuses to create a duplicate subdomain', function () {
    $this->artisan('tenant:create', [
        'name' => 'First Academy',
        'subdomain' => 'duplicatetest',
        '--owner-name' => 'Owner One',
        '--owner-email' => 'first@example.com',
    ])->assertSuccessful();

    $this->artisan('tenant:create', [
        'name' => 'Second Academy',
        'subdomain' => 'duplicatetest',
        '--owner-name' => 'Owner Two',
        '--owner-email' => 'second@example.com',
    ])->assertFailed();

    expect(Tenant::query()->where('name', 'Second Academy')->exists())->toBeFalse();
});

test('works in the production environment, unlike the demo seeder', function () {
    app()->instance('env', 'production');

    $this->artisan('tenant:create', [
        'name' => 'Production Academy',
        'subdomain' => 'prodacademy',
        '--owner-name' => 'Prod Owner',
        '--owner-email' => 'prod@example.com',
    ])->assertSuccessful();

    expect(Tenant::query()->where('name', 'Production Academy')->exists())->toBeTrue();
});
