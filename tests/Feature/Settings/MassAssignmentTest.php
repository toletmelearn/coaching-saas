<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;

test('posting status, timezone, currency, id, logo_path or branding_version through settings changes nothing', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Active,
        'timezone' => 'Asia/Kolkata',
        'currency' => 'INR',
    ]);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $originalId = $tenant->id;

    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => 'Legit Name Change',
            'status' => 'suspended',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'id' => $originalId + 999,
            'logo_path' => 'tenants/999/branding/evil.png',
            'branding_version' => 999,
        ])
        ->assertRedirect();

    $tenant->refresh();

    expect($tenant->id)->toBe($originalId);
    expect($tenant->status)->toBe(TenantStatus::Active);
    expect($tenant->timezone)->toBe('Asia/Kolkata');
    expect($tenant->currency)->toBe('INR');
    expect($tenant->name)->toBe('Legit Name Change');
    expect($tenant->logo_path)->toBeNull();
    expect($tenant->branding_version)->toBe(1);
});
