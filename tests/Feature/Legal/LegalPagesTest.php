<?php

use App\Models\Consent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Guard tests — config/privacy.php must stay in sync with the codebase
// ---------------------------------------------------------------------------

test('config/privacy.php data_categories cover every consent purpose in lang/en/consents.php', function () {
    $consentPurposes = array_keys(trans('consents.purposes'));
    sort($consentPurposes);

    $configPurposes = collect(config('privacy.data_categories'))
        ->flatMap(fn ($cat) => $cat['purposes'])
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($configPurposes)->toBe($consentPurposes);
});

test('config/privacy.php column_inventory and excluded_columns cover every column of every listed table', function () {
    $inventory = config('privacy.column_inventory', []);
    $excluded = config('privacy.excluded_columns', []);
    $tables = array_unique(array_merge(array_keys($inventory), array_keys($excluded)));

    $failures = [];

    foreach ($tables as $table) {
        $columns = Schema::getColumnListing($table);
        $covered = array_keys($inventory[$table] ?? []);
        $excl = array_keys($excluded[$table] ?? []);
        $uncovered = array_diff($columns, array_merge($covered, $excl));

        if ($uncovered !== []) {
            $failures[] = "Table '{$table}' has columns not in config/privacy.php: "
                .implode(', ', $uncovered)
                .' — add to column_inventory with a category slug, or to excluded_columns with a reason.';
        }
    }

    expect($failures)->toBeEmpty(implode("\n", $failures));
});

// ---------------------------------------------------------------------------
// Manifest — published notice files must not change after pinning
// ---------------------------------------------------------------------------

test('published notice files match their SHA-256 hashes in manifest.json', function () {
    $manifestPath = resource_path('legal/notices/manifest.json');
    expect(file_exists($manifestPath))->toBeTrue('manifest.json is missing');

    $manifest = json_decode(file_get_contents($manifestPath), true);
    expect($manifest)->toBeArray()->toHaveKey('files');

    foreach ($manifest['files'] as $filename => $entry) {
        $path = resource_path('legal/notices/'.$filename);
        expect(file_exists($path))->toBeTrue("Notice file listed in manifest not found: {$filename}");

        $actual = hash('sha256', file_get_contents($path));
        expect($actual)->toBe(
            $entry['sha256'],
            "Hash mismatch for {$filename} — the file was edited after pinning. ".
            'If the change is intentional, update the hash in manifest.json and document the change.'
        );
    }
});

// ---------------------------------------------------------------------------
// Notice files
// ---------------------------------------------------------------------------

test('the notice file for the current Consent::NOTICE_VERSION exists on disk', function () {
    $path = resource_path('legal/notices/'.Consent::NOTICE_VERSION.'.md');
    expect(file_exists($path))->toBeTrue(
        'Missing versioned notice file: '.$path.' — create it or update Consent::NOTICE_VERSION'
    );
});

test('the Hindi notice file for the current Consent::NOTICE_VERSION exists on disk', function () {
    $path = resource_path('legal/notices/'.Consent::NOTICE_VERSION.'.hi.md');
    expect(file_exists($path))->toBeTrue(
        'Missing Hindi notice file: '.$path.' — create it or update Consent::NOTICE_VERSION'
    );
});

// ---------------------------------------------------------------------------
// Page responses — central domain
// ---------------------------------------------------------------------------

test('privacy page returns 200 on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/privacy');

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Privacy notice');
    $response->assertSee('Digital Personal Data Protection Act');
});

test('platform terms page returns 200 on the central domain without authentication', function () {
    $response = $this->get('http://coaching.test/terms');

    $response->assertOk();
    $response->assertSee('Platform Terms of Service');
});

// ---------------------------------------------------------------------------
// Page responses — tenant subdomain
// ---------------------------------------------------------------------------

test('privacy page on a tenant subdomain shows the tenant name and notice version', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Academy']);
    $domain = 'bright.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/privacy");

    $response->assertOk();
    $response->assertSee(Consent::NOTICE_VERSION);
    $response->assertSee('Bright Academy');
});

test('student terms page returns 200 on a tenant subdomain without authentication', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'terms-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/terms");

    $response->assertOk();
    $response->assertSee('Student Terms of Use');
});

test('platform terms page is NOT served on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'pf-check.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/terms");

    $response->assertOk();
    $response->assertDontSee('Platform Terms of Service');
    $response->assertSee('Student Terms of Use');
});

test('student terms page is NOT served on the central domain', function () {
    $response = $this->get('http://coaching.test/terms');

    $response->assertOk();
    $response->assertDontSee('Student Terms of Use');
    $response->assertSee('Platform Terms of Service');
});

// ---------------------------------------------------------------------------
// Archived version routes
// ---------------------------------------------------------------------------

test('archived notice version renders at stable URL on the central domain', function () {
    $response = $this->get('http://coaching.test/privacy/v1.0-2026-10-04');

    $response->assertOk();
    $response->assertSee('v1.0-2026-10-04');
});

test('archived notice version renders at stable URL on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'archive-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/privacy/v1.0-2026-10-04");

    $response->assertOk();
    $response->assertSee('v1.0-2026-10-04');
});

test('nonexistent archive version returns 404', function () {
    $response = $this->get('http://coaching.test/privacy/v0.0-1900-01-01');

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// Consent withdrawal
// ---------------------------------------------------------------------------

test('consents withdraw page returns 200 on a tenant subdomain', function () {
    $tenant = Tenant::factory()->create(['name' => 'River Academy']);
    $domain = 'river.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/consents/withdraw");

    $response->assertOk();
    $response->assertSee('River Academy');
});

// ---------------------------------------------------------------------------
// Footer links
// ---------------------------------------------------------------------------

test('privacy and terms links appear in the app chrome footer', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'footer-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertSee('/privacy');
    $response->assertSee('/terms');
});
