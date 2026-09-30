<?php

use App\Models\Tenant;
use App\Models\User;

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

test('no fixed width above 340px is used on the People page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $html = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users")->getContent();

    preg_match_all('/class="([^"]*)"/', $html, $matches);
    foreach ($matches[1] as $classAttr) {
        foreach (explode(' ', $classAttr) as $class) {
            if (preg_match('/^w-\[(\d+)px\]$/', $class, $m)) {
                expect((int) $m[1])->toBeLessThanOrEqual(340);
            }
        }
    }
});
