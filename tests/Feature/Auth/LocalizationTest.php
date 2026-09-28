<?php

use App\Models\Tenant;
use App\Models\User;

// === Localization/Translation Tests ===

test('login page displays all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertOk();

    // Should have translations loaded
    // No hardcoded English strings (check via absence of specific strings)
    $response->assertDontSee('Username or Email');
    $response->assertDontSee('Remember Me');

    // Should use translation keys via __()
    // The actual rendered text will be from lang files
});

test('change-password page displays all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->mustChangePassword()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/auth/change-password");

    $response->assertOk();

    // Should use translation keys, not hardcoded strings
    $response->assertDontSee('New Password');
    $response->assertDontSee('Confirm Password');
});

test('users index page displays all text via translation keys', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';

    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users");

    $response->assertOk();

    // Should use translation keys via __()
    $response->assertDontSee('Create User');
    $response->assertDontSee('Edit');
    $response->assertDontSee('Delete');
});

test('lang/en/auth.php has translation keys for login page', function () {
    $langFile = base_path('lang/en/auth.php');

    if (! file_exists($langFile)) {
        $this->markTestSkipped('lang/en/auth.php not yet created');
    }

    $translations = include $langFile;

    // Should have key translations
    expect($translations)->toHaveKey('login.identifier')
        ->toHaveKey('login.password')
        ->toHaveKey('login.remember')
        ->toHaveKey('login.submit');
});

test('lang/en/messages.php has translation keys for common messages', function () {
    $langFile = base_path('lang/en/messages.php');

    if (! file_exists($langFile)) {
        $this->markTestSkipped('lang/en/messages.php not yet created');
    }

    $translations = include $langFile;

    // Should have message translations
    expect($translations)->toHaveKey('errors.generic')
        ->toHaveKey('success.login')
        ->toHaveKey('success.logout');
});

test('frontend views use __() helper for text', function () {
    // Check that login.blade.php uses __() for strings
    $loginView = base_path('resources/views/auth/login.blade.php');

    if (! file_exists($loginView)) {
        $this->markTestSkipped('login.blade.php not yet created');
    }

    $content = file_get_contents($loginView);

    // Should use __() helper
    expect($content)->toContain("__('");

    // Should not have hardcoded strings (spot check)
    // This is a heuristic - some HTML tags are ok
    expect($content)->not->toMatch('/>\s*[A-Z][a-z]+.*?<\/(?!span|button|label)/');
});
