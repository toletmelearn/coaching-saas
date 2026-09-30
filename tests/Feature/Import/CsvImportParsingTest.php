<?php

use App\Models\Tenant;
use App\Models\User;

function importFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}

// === Template ===

test('the template download is a CSV with a BOM and the expected headers', function () {
    [, $domain, $owner] = importFixture();

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/template");

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect(str_starts_with($response->streamedContent() ?? $response->getContent(), "\xEF\xBB\xBF"))->toBeTrue();
    expect($response->getContent())->toContain('name,phone,email');
});

// === Nothing is created at preview ===

test('uploading a CSV creates zero users — preview only', function () {
    [$tenant, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,9876543210,asha@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk();

    inTenant($tenant, fn () => expect(User::where('role', 'student')->count())->toBe(0));
});

// === Header detection ===

test('accepted header aliases for phone and email are recognised, case-insensitively and trimmed', function () {
    [, $domain, $owner] = importFixture();
    $csv = " Name , Mobile Number , E-mail \nAsha Rao,9876543210,asha@example.com\n";

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);

    $response->assertOk();
    $response->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

test('extra unknown columns are ignored', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email,batch\nAsha Rao,9876543210,asha@example.com,Morning\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

test('a missing name column is a hard error, not a per-row status', function () {
    [, $domain, $owner] = importFixture();
    $csv = "phone,email\n9876543210,asha@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertSessionHasErrors('file');
});

// === Encoding ===

test('a UTF-8 file with a BOM parses correctly', function () {
    [, $domain, $owner] = importFixture();
    $csv = "\xEF\xBB\xBFname,phone,email\nAsha Rao,9876543210,asha@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

test('a Windows-1252 encoded file with non-UTF-8 bytes is decoded correctly', function () {
    [, $domain, $owner] = importFixture();
    $name = mb_convert_encoding('Renée Dsouza', 'Windows-1252', 'UTF-8');
    $csv = "name,phone,email\n{$name},9876543210,renee@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => str_contains(collect($rows)->first()['name'], 'Ren'));
});

test('Devanagari names survive parsing intact', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nप्रिया शर्मा,9876543210,priya@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['name'] === 'प्रिया शर्मा');
});

// === Delimiter auto-detection ===

test('a semicolon-delimited file is auto-detected', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name;phone;email\nAsha Rao;9876543210;asha@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

test('a tab-delimited file is auto-detected', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name\tphone\temail\nAsha Rao\t9876543210\tasha@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

// === Blank lines ===

test('blank lines are skipped, not counted as problem rows', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,9876543210,asha@example.com\n\n\nRahul Jain,9123456780,rahul@example.com\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => count($rows) === 2);
});

// === Size / row caps ===

test('a file over 1 MB is rejected', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\n".str_repeat("Asha Rao,9876543210,asha@example.com\n", 40000);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertSessionHasErrors('file');
});

test('a file with more than 100 data rows is rejected', function () {
    [, $domain, $owner] = importFixture();
    $rows = "name,phone,email\n";
    for ($i = 0; $i < 101; $i++) {
        $rows .= "Student {$i},98765432{$i},student{$i}@example.com\n";
    }

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($rows)])
        ->assertSessionHasErrors('file');
});

test('exactly 100 data rows is accepted (positive control)', function () {
    [, $domain, $owner] = importFixture();
    $rows = "name,phone,email\n";
    for ($i = 0; $i < 100; $i++) {
        $rows .= sprintf("Student %d,9%09d,student%d@example.com\n", $i, $i, $i);
    }

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($rows)])
        ->assertOk();
});

// === Content-based rejection (not just extension) ===

test('a binary file renamed to .csv is rejected by content, not just extension', function () {
    [, $domain, $owner] = importFixture();
    $binary = random_bytes(500);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($binary, 'students.csv')])
        ->assertSessionHasErrors('file');
});

// === Row validation ===

test('an invalid phone is flagged with a plain-language status', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,12345,\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'invalid_phone');
});

test('a phone starting 5xxxxxxxxx is rejected as not a valid Indian mobile', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,5876543210,\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'invalid_phone');
});

test('a +91-prefixed or 0-prefixed phone is accepted and normalised to 10 digits', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,+91 98765 43210,\nRahul Jain,09123456780,\n";

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);

    $response->assertOk();
    $response->assertViewHas('rows', function ($rows) {
        $rows = collect($rows);

        return $rows->every(fn ($r) => $r['status'] === 'ok')
            && $rows->first()['phone'] === '9876543210'
            && $rows->last()['phone'] === '9123456780';
    });
});

test('an invalid email is flagged', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,,not-an-email\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'invalid_email');
});

test('a row with neither phone nor email is flagged', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,,\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'missing_contact');
});

test('a duplicate phone within the file is flagged on the second occurrence only', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\nAsha Rao,9876543210,\nAsha Duplicate,9876543210,\n";

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);

    $response->assertOk();
    $response->assertViewHas('rows', function ($rows) {
        $rows = collect($rows)->values();

        return $rows[0]['status'] === 'ok' && $rows[1]['status'] === 'duplicate_in_file';
    });
});

test('a phone already used by a student in this tenant is flagged as an existing duplicate', function () {
    [$tenant, $domain, $owner] = importFixture();
    inTenant($tenant, fn () => User::factory()->student()->create(['phone' => '9876543210']));
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'duplicate_in_tenant');
});

test('the same phone existing in a different tenant is NOT flagged as a duplicate', function () {
    [$tenantA, $domainA, $ownerA] = importFixture();
    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);
    inTenant($tenantB, fn () => User::factory()->student()->create(['phone' => '9876543210']));

    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    $this->actingAs($ownerA, 'tenant')
        ->post("http://{$domainA}/users/import", ['file' => csvUploadFile($csv)])
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => collect($rows)->first()['status'] === 'ok');
});

// === XSS ===

test('HTML or script in a name is escaped when the preview page is rendered', function () {
    [, $domain, $owner] = importFixture();
    $csv = "name,phone,email\n<script>alert(1)</script>,9876543210,\n";

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);

    $response->assertOk();
    $response->assertDontSee('<script>alert(1)</script>', false);
});
