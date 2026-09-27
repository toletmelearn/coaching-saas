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
