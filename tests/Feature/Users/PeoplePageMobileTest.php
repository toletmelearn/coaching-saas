<?php

use App\Models\Tenant;
use App\Models\User;
use Tests\Support\MobileSafetyChecker;

test('the People page renders both a card list and a table, from one shared action partial', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$owner, $student] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $student = User::factory()->student()->create(['name' => 'Student Row']);

        return [$owner, $student];
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users");
    $html = $response->getContent();

    // Card list markup: hidden at sm and up.
    expect($html)->toMatch('/class="[^"]*\bsm:hidden\b[^"]*"/');
    // Table markup: hidden below sm, present at sm and up.
    expect($html)->toMatch('/class="[^"]*\bhidden\b[^"]*\bsm:(table|block)\b[^"]*"/');
});

test('card and table renderings expose the identical action set per viewer', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$staff, $student, $owner] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $student = User::factory()->student()->create(['name' => 'Managed Student']);

        return [$staff, $student, $owner];
    });

    $response = $this->actingAs($staff, 'tenant')->get("http://{$domain}/users");
    $html = $response->getContent();

    // Staff manages the student: reset/disable appear twice — once in the card, once
    // in the table row — both rendered from the same partial.
    expect(substr_count($html, __('users.actions.reset_password')))->toBe(2);
    expect(substr_count($html, __('users.actions.disable')))->toBe(2);

    // Staff cannot manage the owner row: zero occurrences in either rendering.
    expect(substr_count($html, __('users.actions.reset_password').'-owner-row'))->toBe(0);
});

test('the People page has both the card and table renderings present, and no fixed width above 340px', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $html = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users")->getContent();

    // Positive control: without this, a page with no `w-[Npx]` class ANYWHERE (which is
    // exactly what today's People page — with no mobile-card markup at all — looks like)
    // would trivially satisfy "no fixed width above 340px" by having no such class to
    // begin with, regardless of whether the mobile cards actually exist.
    expect($html)->toMatch('/class="[^"]*\bsm:hidden\b[^"]*"/');

    $violations = array_filter(
        MobileSafetyChecker::violations($html),
        fn ($v) => str_starts_with($v, 'fixed width above')
    );
    expect($violations)->toBe([]);
});
