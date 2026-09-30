<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function patchSettings(string $domain, User $owner, array $overrides = []): TestResponse
{
    $defaults = [
        'name' => 'A Valid Institute Name',
        'contact_phone' => '',
        'contact_email' => '',
        'theme_color' => config('coaching.theme_presets.0', '#4f46e5'),
        'academic_year_end' => '',
    ];

    return test()->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", array_merge($defaults, $overrides));
}

function settingsTenantAndOwner(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}

test('name is required and capped at 100 characters', function () {
    [$tenant, $domain, $owner] = settingsTenantAndOwner();

    patchSettings($domain, $owner, ['name' => ''])->assertSessionHasErrors('name');

    freshRequestCycle();

    patchSettings($domain, $owner, ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');

    freshRequestCycle();

    // Positive control: exactly 100 characters is accepted.
    patchSettings($domain, $owner, ['name' => str_repeat('a', 100)])->assertSessionDoesntHaveErrors('name');
});

test('phone is normalised to its 10-digit canonical form when given', function () {
    [$tenant, $domain, $owner] = settingsTenantAndOwner();

    patchSettings($domain, $owner, ['contact_phone' => '+91 98765 43210'])->assertRedirect();

    $tenant->refresh();
    expect($tenant->contact_phone)->toBe('9876543210');
});

test('email must be a valid address when given', function () {
    [$tenant, $domain, $owner] = settingsTenantAndOwner();

    patchSettings($domain, $owner, ['contact_email' => 'not-an-email'])->assertSessionHasErrors('contact_email');

    freshRequestCycle();

    patchSettings($domain, $owner, ['contact_email' => 'owner@example.com'])->assertSessionDoesntHaveErrors('contact_email');
});

test('theme colour must be one of the preset values', function () {
    [$tenant, $domain, $owner] = settingsTenantAndOwner();

    $presets = config('coaching.theme_presets', []);
    expect($presets)->not->toBeEmpty();

    patchSettings($domain, $owner, ['theme_color' => '#123abc'])->assertSessionHasErrors('theme_color');

    freshRequestCycle();

    patchSettings($domain, $owner, ['theme_color' => $presets[0]])->assertSessionDoesntHaveErrors('theme_color');

    $tenant->refresh();
    expect($tenant->theme_color)->toBe($presets[0]);
});

test('academic year end must be a valid date and not more than 3 years ahead', function () {
    [$tenant, $domain, $owner] = settingsTenantAndOwner();

    patchSettings($domain, $owner, ['academic_year_end' => 'not-a-date'])->assertSessionHasErrors('academic_year_end');

    freshRequestCycle();

    patchSettings($domain, $owner, [
        'academic_year_end' => now()->addYears(4)->toDateString(),
    ])->assertSessionHasErrors('academic_year_end');

    freshRequestCycle();

    $validDate = now()->addYear()->toDateString();
    patchSettings($domain, $owner, ['academic_year_end' => $validDate])->assertSessionDoesntHaveErrors('academic_year_end');

    $tenant->refresh();
    expect($tenant->academic_year_end?->toDateString())->toBe($validDate);
});
