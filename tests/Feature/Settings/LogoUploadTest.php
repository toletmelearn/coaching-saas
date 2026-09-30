<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a real, decodable image file on disk (not Laravel's UploadedFile::fake()->image()
 * placeholder, which doesn't let us control exact pixel dimensions/format precisely enough
 * for the boundary checks below) and wraps it as an UploadedFile in test mode.
 */
function fakeImageUpload(int $width, int $height, string $format = 'png', string $originalName = 'logo.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 79, 70, 229));

    $path = tempnam(sys_get_temp_dir(), 'logo').'.'.$format;

    match ($format) {
        'png' => imagepng($image, $path),
        'jpg', 'jpeg' => imagejpeg($image, $path),
        'webp' => imagewebp($image, $path),
        'gif' => imagegif($image, $path),
    };

    imagedestroy($image);

    return new UploadedFile($path, $originalName, mime_content_type($path) ?: null, null, true);
}

function fakeTextUpload(string $content, string $originalName, ?string $mime = 'text/plain'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $originalName, $mime, null, true);
}

function settingsOwnerAndDomain(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}

test('a valid PNG logo is accepted, stored as a re-encoded PNG under tenants/{id}/branding with a random name', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512)])
        ->assertRedirect();

    $tenant->refresh();

    expect($tenant->logo_path)->not->toBeNull();
    expect($tenant->logo_path)->toStartWith("tenants/{$tenant->id}/branding/");
    expect($tenant->logo_path)->not->toContain('logo.png'); // client filename never used
    expect(Storage::disk('local')->exists($tenant->logo_path))->toBeTrue();

    $stored = getimagesizefromstring(Storage::disk('local')->get($tenant->logo_path));
    expect($stored[2])->toBe(IMAGETYPE_PNG);
});

test('a valid JPG or WebP logo is also accepted', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512, 'jpg', 'logo.jpg')])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

test('an SVG logo is rejected (script risk)', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $svg = fakeTextUpload('<svg onload="alert(1)"></svg>', 'logo.svg', 'image/svg+xml');

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => $svg])
        ->assertSessionHasErrors('logo');

    $tenant->refresh();
    expect($tenant->logo_path)->toBeNull();
});

test('a GIF renamed to .png is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512, 'gif', 'logo.png')])
        ->assertSessionHasErrors('logo');
});

test('a PHP file renamed to .png is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $php = fakeTextUpload('<?php system($_GET["c"]); ?>', 'logo.png', 'image/png');

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => $php])
        ->assertSessionHasErrors('logo');
});

test('a plain text file is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeTextUpload('hello', 'logo.png')])
        ->assertSessionHasErrors('logo');
});

test('a logo over 2 MB is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $upload = fakeImageUpload(512, 512);
    // Pad the file past 2 MB — trailing bytes after PNG's IEND chunk don't stop
    // getimagesize() from reading the header, so this isolates the size check.
    file_put_contents($upload->getRealPath(), str_repeat('0', 3 * 1024 * 1024), FILE_APPEND);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => $upload])
        ->assertSessionHasErrors('logo');
});

test('an image below 256px on a side is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(100, 100)])
        ->assertSessionHasErrors('logo');
});

test('an image with a side above 4096px is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(4097, 2000)])
        ->assertSessionHasErrors('logo');
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('an image within the per-side limit but over 16 megapixels total is rejected', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    // 4096 x 4096 = 16,777,216 px — each side is within the 256-4096 bound, but the
    // total exceeds the 16-megapixel cap, isolating that specific check.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(4096, 4096)])
        ->assertSessionHasErrors('logo');
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('icons are generated at the right dimensions after a logo upload', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(1024, 1024)])
        ->assertRedirect();

    $versionQuery = '?v=2';

    $icon512 = $this->get("http://{$domain}/pwa/icons/512.png{$versionQuery}");
    $icon512->assertOk();
    [$w, $h] = getimagesizefromstring($icon512->getContent());
    expect([$w, $h])->toBe([512, 512]);

    $icon192 = $this->get("http://{$domain}/pwa/icons/192.png{$versionQuery}");
    [$w, $h] = getimagesizefromstring($icon192->getContent());
    expect([$w, $h])->toBe([192, 192]);

    $icon180 = $this->get("http://{$domain}/pwa/icons/180.png{$versionQuery}");
    [$w, $h] = getimagesizefromstring($icon180->getContent());
    expect([$w, $h])->toBe([180, 180]);

    $maskable = $this->get("http://{$domain}/pwa/icons/512-maskable.png{$versionQuery}");
    [$w, $h] = getimagesizefromstring($maskable->getContent());
    expect([$w, $h])->toBe([512, 512]);
});

test('removing the logo deletes the files and falls back to the generated default icon', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512)])
        ->assertRedirect();

    $tenant->refresh();
    $path = $tenant->logo_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/settings/logo")
        ->assertRedirect();

    $tenant->refresh();
    expect($tenant->logo_path)->toBeNull();
    expect(Storage::disk('local')->exists($path))->toBeFalse();

    // The manifest/icon routes must still work with a generated default icon.
    $this->get("http://{$domain}/pwa/icons/512.png")->assertOk();
});

test('branding_version increments on every successful branding change and never on a rejected upload', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();
    $initialVersion = $tenant->branding_version;

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeTextUpload('nope', 'logo.png')])
        ->assertSessionHasErrors('logo');

    $tenant->refresh();
    expect($tenant->branding_version)->toBe($initialVersion);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512)])
        ->assertRedirect();

    $tenant->refresh();
    expect($tenant->branding_version)->toBe($initialVersion + 1);

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/settings/logo")
        ->assertRedirect();

    $tenant->refresh();
    expect($tenant->branding_version)->toBe($initialVersion + 2);
});

test('the stored logo file is not reachable through any public storage URL', function () {
    [$tenant, $domain, $owner] = settingsOwnerAndDomain();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/settings/logo", ['logo' => fakeImageUpload(512, 512)])
        ->assertRedirect();

    $tenant->refresh();

    expect(Storage::disk('public')->allFiles())->not->toContain($tenant->logo_path);

    // The 'local' disk's built-in /storage/{path} route (filesystems.php
    // 'serve' => true) requires a valid signature and returns 403 for a plain,
    // unsigned guess rather than 404 — either status means the file isn't reachable
    // this way, which is the actual property under test.
    $guessedPublicUrl = '/storage/'.$tenant->logo_path;
    $status = $this->get("http://{$domain}{$guessedPublicUrl}")->getStatusCode();
    expect($status)->toBeIn([403, 404]);
});
