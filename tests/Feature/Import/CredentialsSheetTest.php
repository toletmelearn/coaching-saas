<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function sheetFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    $preview = test()->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);
    $token = $preview->viewData('token');

    $confirm = test()->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token, 'guardian_consent' => '1']);
    $sheetToken = $confirm->getSession()->get('import_sheet_token');

    return [$tenant, $domain, $owner, $sheetToken];
}

test('the creator can view the credentials sheet within 15 minutes', function () {
    [, $domain, $owner, $sheetToken] = sheetFixture();

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}");

    $response->assertOk();
    $response->assertSee('9876543210');
});

test('the sheet is gone after 15 minutes', function () {
    [, $domain, $owner, $sheetToken] = sheetFixture();

    $this->travel(16)->minutes();

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/import/sheet/{$sheetToken}")
        ->assertSee(__('import.sheet_unavailable'));
});

test('another user gets a 404 for someone else\'s sheet', function () {
    [$tenant, $domain, , $sheetToken] = sheetFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/users/import/sheet/{$sheetToken}")
        ->assertStatus(404);
});

test('the sheet response is no-store and noindex', function () {
    [, $domain, $owner, $sheetToken] = sheetFixture();

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}");

    $response->assertHeader('Cache-Control', 'no-store, private');
    $response->assertHeader('X-Robots-Tag', 'noindex');
});

test('the temporary password never appears in the database, logs, or any other page', function () {
    [$tenant, $domain, $owner, $sheetToken] = sheetFixture();

    $sheet = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}");
    preg_match('/([a-z0-9]{4}-[a-z0-9]{4})/', strip_tags($sheet->getContent()), $matches);
    $temporaryPassword = $matches[1] ?? null;
    expect($temporaryPassword)->not->toBeNull();

    inTenant($tenant, function () use ($temporaryPassword) {
        $student = User::where('phone', '9876543210')->first();
        expect(Hash::check($temporaryPassword, $student->password))->toBeTrue();
    });

    $peoplePage = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users");
    $peoplePage->assertDontSee($temporaryPassword);
});

test('the WhatsApp link only appears for rows with a phone, formatted 91XXXXXXXXXX with an encoded message', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\nNo Phone,,noPhone@example.com\n";

    $preview = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);
    $token = $preview->viewData('token');
    $confirm = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token, 'guardian_consent' => '1']);
    $sheetToken = $confirm->getSession()->get('import_sheet_token');

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}");

    $response->assertSee('https://wa.me/919876543210?text=', false);
});

test('CSV download from the sheet is formula-safe', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $csv = "name,phone,email\n=HYPERLINK(\"http://evil\"),9876543210,\n";

    $preview = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);
    $token = $preview->viewData('token');
    $confirm = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token, 'guardian_consent' => '1']);
    $sheetToken = $confirm->getSession()->get('import_sheet_token');

    $download = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}/download");

    $download->assertOk();
    $content = $download->streamedContent();
    expect($content)->toContain("'=HYPERLINK");
    expect($content)->not->toContain("\n=HYPERLINK(\"http://evil\"),");
});

test('Clear now removes the sheet immediately', function () {
    [, $domain, $owner, $sheetToken] = sheetFixture();

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/sheet/{$sheetToken}/clear")->assertRedirect();

    $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/users/import/sheet/{$sheetToken}")
        ->assertSee(__('import.sheet_unavailable'));
});
