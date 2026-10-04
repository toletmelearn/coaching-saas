<?php

use App\Models\Tenant;
use App\Models\User;

function helpFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    return [$tenant, $domain];
}

test('an owner can view the Help page', function () {
    [$tenant, $domain] = helpFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/help")->assertOk();
});

test('staff can view the Help page (positive control)', function () {
    [$tenant, $domain] = helpFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')->get("http://{$domain}/manage/help")->assertOk();
});

test('a student is forbidden from the Help page', function () {
    [$tenant, $domain] = helpFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')->get("http://{$domain}/manage/help")->assertForbidden();
});

test('a guest is redirected to login for the Help page', function () {
    [, $domain] = helpFixture();

    $this->get("http://{$domain}/manage/help")->assertRedirect("http://{$domain}/login");
});

test('the Help link appears in the owner/staff header', function () {
    [$tenant, $domain] = helpFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertSee(__('help.nav_label'));
});

test('every Help section renders through translation keys, not hardcoded strings', function () {
    [$tenant, $domain] = helpFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/help");

    foreach ([
        'help.sections.add_one_student',
        'help.sections.bulk_import',
        'help.sections.whatsapp_sharing',
        'help.sections.enrolling',
        'help.sections.uploading_from_phone',
        'help.sections.preview_vs_paid',
        'help.sections.gone_quiet',
        'help.sections.reset_password',
        'help.sections.devices_per_student',
        'help.sections.cannot_login',
    ] as $key) {
        $response->assertSee(__($key));
    }
});

// === Phase 12.1 — live classes ===

test('the three live-class Help sections resolve from the live_classes group in lang/en/help.php', function () {
    foreach (['schedule', 'join', 'attendance'] as $key) {
        $section = __('help.live_classes.sections.'.$key);
        $body = __('help.live_classes.body.'.$key);

        // A missing key would return itself, which is exactly the failure this
        // guards against: a section heading rendering as "help.live_classes...".
        expect($section)->not->toBe('help.live_classes.sections.'.$key)
            ->and($body)->not->toBe('help.live_classes.body.'.$key);
    }
});

test('the Help page renders the three live-class sections with their translated copy', function () {
    [$tenant, $domain] = helpFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/help");
    $response->assertOk();

    foreach (['schedule', 'join', 'attendance'] as $key) {
        $response->assertSee(__('help.live_classes.sections.'.$key))
            ->assertSee(__('help.live_classes.body.'.$key));
    }
});
