<?php

use App\Models\Tenant;
use App\Models\User;

test('tenant pages include the manifest link, theme-color meta, apple-touch-icon and bundled pwa script; central-domain pages do not', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the tenant page carries every piece of PWA markup.
    $tenantResponse = $this->get("http://{$domain}/login");
    $tenantResponse->assertOk();
    $tenantResponse->assertSee('rel="manifest"', false);
    $tenantResponse->assertSee('href="/manifest.webmanifest"', false);
    $tenantResponse->assertSee('name="theme-color"', false);
    $tenantResponse->assertSee('rel="apple-touch-icon"', false);
    $tenantResponse->assertSee('href="/pwa/icons/180.png"', false);
    $tenantResponse->assertSee('resources/js/pwa.js', false);

    // The same shared layout, on a central domain, must carry none of it.
    $centralResponse = $this->get('http://coaching.test/');
    $centralResponse->assertOk();
    $centralResponse->assertDontSee('rel="manifest"', false);
    $centralResponse->assertDontSee('name="theme-color"', false);
});

test('the tenant header shows the logo when set, and the institute name always', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Future Academy']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // No logo yet: name still shown, no <img> pointing at the branding route.
    $noLogo = $this->get("http://{$domain}/login");
    $noLogo->assertSee('Bright Future Academy');
    $noLogo->assertDontSee('src="/branding/logo"', false);

    inTenant($tenant, fn () => $tenant->forceFill(['logo_path' => "tenants/{$tenant->id}/branding/fake.png"])->save());

    $withLogo = $this->get("http://{$domain}/login");
    $withLogo->assertSee('Bright Future Academy');
    $withLogo->assertSee('src="/branding/logo"', false);
});

test('the login page shows the logo above the heading when set', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    inTenant($tenant, fn () => $tenant->forceFill(['logo_path' => "tenants/{$tenant->id}/branding/fake.png"])->save());

    $this->get("http://{$domain}/login")->assertSee('src="/branding/logo"', false);
});

test('accent colour and institute name are only ever rendered from validated/escaped values, never raw request input', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    // An attempt to inject CSS/HTML/script through the colour field must be rejected by
    // validation before it ever reaches the page.
    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => 'Bright Future Academy',
            'theme_color' => '"></style><script>alert(1)</script>',
        ])
        ->assertSessionHasErrors('theme_color');

    freshRequestCycle();

    // Positive control: the settings endpoint actually works and saves a legitimate
    // name/colour pair — proves the escaping assertions below are exercising the real
    // save-and-render path, not a request that never got anywhere.
    $preset = config('coaching.theme_presets.0', '#4f46e5');
    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/settings", [
            'name' => '<script>alert(1)</script>',
            'theme_color' => $preset,
        ])
        ->assertRedirect();

    freshRequestCycle();

    $response = $this->get("http://{$domain}/login");
    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    $response->assertSee($preset, false);
});

test('only the owner sees a Settings link in the tenant header', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $staff] = inTenant($tenant, fn () => [
        User::factory()->owner()->create(),
        User::factory()->staff()->create(),
    ]);

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertSee('/manage/settings', false);

    freshRequestCycle();

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee('/manage/settings', false);
});

test('the install button and iOS hint markup exist but are hidden by default', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertSee('id="pwa-install-button"', false);
    $response->assertSee('id="pwa-ios-hint"', false);

    // Hidden by default (feature-detected/JS-revealed), not visible on first paint.
    preg_match('/<[^>]*id="pwa-install-button"[^>]*>/', $response->getContent(), $buttonTag);
    preg_match('/<[^>]*id="pwa-ios-hint"[^>]*>/', $response->getContent(), $hintTag);

    expect($buttonTag[0] ?? '')->toContain('hidden');
    expect($hintTag[0] ?? '')->toContain('hidden');
});
