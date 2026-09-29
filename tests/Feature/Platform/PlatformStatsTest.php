<?php

use App\Models\Course;
use App\Models\DemoRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformStats;

test('countsByTenant reports isolated, correct student and course counts across two tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, function () {
        User::factory()->student()->count(3)->create();
        User::factory()->owner()->create(); // not counted as a student
        Course::factory()->count(2)->create();
    });

    inTenant($tenantB, function () {
        User::factory()->student()->count(1)->create();
        Course::factory()->count(5)->create();
    });

    $counts = (new PlatformStats)->countsByTenant();

    expect($counts[$tenantA->id])->toBe(['students' => 3, 'courses' => 2])
        ->and($counts[$tenantB->id])->toBe(['students' => 1, 'courses' => 5]);
});

test('dashboardCounts sums students and courses across every tenant, and counts institutes by status', function () {
    $tenantA = Tenant::factory()->create(); // default status: active
    $tenantB = Tenant::factory()->create(['status' => 'suspended']);

    inTenant($tenantA, function () {
        User::factory()->student()->count(2)->create();
        Course::factory()->count(1)->create();
    });

    inTenant($tenantB, function () {
        User::factory()->student()->count(4)->create();
        Course::factory()->count(3)->create();
    });

    DemoRequest::factory()->count(2)->create();
    DemoRequest::factory()->contacted()->create();

    $counts = (new PlatformStats)->dashboardCounts();

    expect($counts['active_institutes'])->toBe(1)
        ->and($counts['suspended_institutes'])->toBe(1)
        ->and($counts['total_students'])->toBe(6)
        ->and($counts['total_courses'])->toBe(4)
        ->and($counts['new_demo_requests'])->toBe(2);
});

test('ownerNamesByTenant returns the first owner per tenant, isolated from other tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    inTenant($tenantA, fn () => User::factory()->owner()->create(['name' => 'Owner A']));
    inTenant($tenantB, fn () => User::factory()->owner()->create(['name' => 'Owner B']));

    $owners = (new PlatformStats)->ownerNamesByTenant();

    expect($owners[$tenantA->id])->toBe('Owner A')
        ->and($owners[$tenantB->id])->toBe('Owner B');
});
