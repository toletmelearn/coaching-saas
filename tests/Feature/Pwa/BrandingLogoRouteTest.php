<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('the header logo route serves the stored logo at 256px max side with caching headers', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $image = imagecreatetruecolor(800, 800);
    imagefill($image, 0, 0, imagecolorallocate($image, 79, 70, 229));
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", [
            'logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
        ])->assertRedirect();

    $response = $this->get("http://{$domain}/branding/logo");
    $response->assertOk();
    // Symfony's ResponseHeaderBag reserializes Cache-Control directives in its own
    // order ("max-age=86400, public"), so assert the directives rather than the
    // exact string.
    $cacheControl = $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('public')->toContain('max-age=86400');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    [$w, $h] = getimagesizefromstring($response->getContent());
    expect(max($w, $h))->toBeLessThanOrEqual(256);
});

test('the header logo route works on a tenant domain and 404s on a central domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the route works on the tenant's own domain (proves the 404 below
    // is actually central-domain routing, not the route being unregistered everywhere).
    $this->get("http://{$domain}/branding/logo")->assertOk();

    $this->get('http://coaching.test/branding/logo')->assertNotFound();
});
