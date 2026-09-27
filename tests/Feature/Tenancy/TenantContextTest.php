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
