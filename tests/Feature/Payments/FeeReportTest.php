<?php

use App\Enums\PaymentStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PaymentFixtures;

// ────────────────────────────────────────────────────────────────────────────
// Shared fixture: one tenant with owner, staff, student, a published course,
// and the student's active enrolment.  No payment is created by default so
// each test can decide how many and what kind to add.
// ────────────────────────────────────────────────────────────────────────────
function feeReportFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = strtolower(Str::random(8)).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $staff, $student, $course, $enrolment] = inTenant($tenant, function () {
        $owner     = User::factory()->owner()->create();
        $staff     = User::factory()->staff()->create();
        $student   = User::factory()->student()->create();
        $course    = Course::factory()->published()->create(['title' => 'Math 101']);
        $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$owner, $staff, $student, $course, $enrolment];
    });

    return compact('tenant', 'domain', 'owner', 'staff', 'student', 'course', 'enrolment');
}

// ── Sub-task 4.1: Per-course paid/unpaid counts and total collected ────────────

test('4.1 owner sees correct paid_count and total_collected_inr per course', function () {
    $f = feeReportFixture();

    // First approved payment: 50 000 paise = ₹500.00
    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status'       => PaymentStatus::Approved,
        'amount_paise' => 50000,
        'reviewed_at'  => now(),
    ]);

    // Second student with an approved payment: 30 000 paise = ₹300.00
    $extra = inTenant($f['tenant'], function () use ($f) {
        $student2   = User::factory()->student()->create();
        $enrolment2 = Enrolment::factory()->for($f['course'])->for($student2, 'user')->active()->create();

        return compact('student2', 'enrolment2');
    });

    PaymentFixtures::payment($f['tenant'], $extra['enrolment2'], [
        'status'       => PaymentStatus::Approved,
        'amount_paise' => 30000,
        'reviewed_at'  => now(),
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report");

    $response->assertOk();
    $response->assertSeeText('Math 101');
    // total = (50 000 + 30 000) / 100 = ₹800.00
    $response->assertSeeText('800.00');
});

test('4.1 enrolments with no payment or non-approved payment count as unpaid', function () {
    $f = feeReportFixture();
    // $f['student'] already has an active enrolment with NO payment → unpaid

    // Second student: REJECTED payment → still unpaid
    $extra2 = inTenant($f['tenant'], function () use ($f) {
        $student2   = User::factory()->student()->create();
        $enrolment2 = Enrolment::factory()->for($f['course'])->for($student2, 'user')->active()->create();

        return compact('student2', 'enrolment2');
    });
    PaymentFixtures::payment($f['tenant'], $extra2['enrolment2'], [
        'status'       => PaymentStatus::Rejected,
        'amount_paise' => 50000,
        'reviewed_at'  => now(),
    ]);

    // Third student: PENDING payment → still unpaid
    $extra3 = inTenant($f['tenant'], function () use ($f) {
        $student3   = User::factory()->student()->create();
        $enrolment3 = Enrolment::factory()->for($f['course'])->for($student3, 'user')->active()->create();

        return compact('student3', 'enrolment3');
    });
    PaymentFixtures::payment($f['tenant'], $extra3['enrolment3'], [
        'status'       => PaymentStatus::Pending,
        'amount_paise' => 50000,
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report");

    $response->assertOk();
    $response->assertSeeText('Math 101');
    // No approved payments → paid total must be ₹0.00
    $response->assertSeeText('0.00');
    // 3 enrolments none of which have an approved payment → unpaid_count = 3
    $response->assertSeeText('3');
});

// ── Sub-task 4.2: Monthly collections grouping ───────────────────────────────

test('4.2 IST-crossing payment (Dec 31 23:30 UTC) appears under January not December', function () {
    $f = feeReportFixture();

    // Dec 31 23:30 UTC = Jan 1 05:00 IST (UTC+5:30) → must appear under "January 2026"
    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status'       => PaymentStatus::Approved,
        'amount_paise' => 50000,
        'reviewed_at'  => Carbon::create(2025, 12, 31, 23, 30, 0, 'UTC'),
    ]);

    // Second student: approved payment clearly in December IST
    $extra = inTenant($f['tenant'], function () use ($f) {
        $student2   = User::factory()->student()->create();
        $enrolment2 = Enrolment::factory()->for($f['course'])->for($student2, 'user')->active()->create();

        return compact('student2', 'enrolment2');
    });
    // Dec 1 04:30 UTC = Dec 1 10:00 IST → December 2025
    PaymentFixtures::payment($f['tenant'], $extra['enrolment2'], [
        'status'       => PaymentStatus::Approved,
        'amount_paise' => 30000,
        'reviewed_at'  => Carbon::create(2025, 12, 1, 4, 30, 0, 'UTC'),
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report");

    $response->assertOk();
    // The Dec-31-23:30-UTC payment must be grouped under January 2026
    $response->assertSeeText('January 2026');
    // The Dec-1-10:00-IST payment must be grouped under December 2025
    $response->assertSeeText('December 2025');
});

// ── Sub-task 4.3: Authorization ───────────────────────────────────────────────

test('4.3 owner can access the fee report index', function () {
    $f = feeReportFixture();

    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report")
        ->assertOk();
});

test('4.3 staff can access the fee report index', function () {
    $f = feeReportFixture();

    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report")
        ->assertOk();
});

test('4.3 student is forbidden from fee report index', function () {
    $f = feeReportFixture();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report")
        ->assertForbidden();
});

test('4.3 guest is redirected to login from fee report index', function () {
    $f = feeReportFixture();

    $this->get("http://{$f['domain']}/manage/fee-report")
        ->assertRedirect("http://{$f['domain']}/login");
});

// ── Sub-task 4.4: CSV export ──────────────────────────────────────────────────

test('4.4 CSV export has correct headers and one data row per course', function () {
    $f = feeReportFixture();

    // 49 900 paise = ₹499.00
    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status'       => PaymentStatus::Approved,
        'amount_paise' => 49900,
        'reviewed_at'  => now(),
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report/export");

    $response->assertOk();

    $content = $response->getContent();
    // Strip UTF-8 BOM if present
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    $lines = array_values(array_filter(
        explode("\n", str_replace("\r\n", "\n", $content)),
        fn ($l) => trim($l) !== ''
    ));

    expect($lines)->toHaveCount(2); // header row + one data row

    $headers = str_getcsv($lines[0]);
    expect($headers)->toBe(['course', 'paid_count', 'total_collected_inr', 'unpaid_count']);

    $row = str_getcsv($lines[1]);
    expect($row[0])->toBe('Math 101');
    expect($row[1])->toBe('1');      // paid_count
    expect($row[2])->toBe('499.00'); // 49 900 paise / 100
    expect($row[3])->toBe('0');      // unpaid_count
});

test('4.4 student is forbidden from the fee report CSV export', function () {
    $f = feeReportFixture();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/manage/fee-report/export")
        ->assertForbidden();
});

test('4.4 formula injection in a course name is escaped in the CSV export', function () {
    $tenant = Tenant::factory()->create();
    $domain = strtolower(Str::random(8)).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        // Title starting with "=" — CsvFormulaGuard must prefix it with a leading quote
        Course::factory()->published()->create(['title' => '=HYPERLINK("http://evil.test")']);

        return $owner;
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/fee-report/export");

    $response->assertOk();

    $content = $response->getContent();
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    $lines = array_values(array_filter(
        explode("\n", str_replace("\r\n", "\n", $content)),
        fn ($l) => trim($l) !== ''
    ));

    // Must have at least one data row after the header
    expect($lines)->toHaveAtLeast(2);

    $row = str_getcsv($lines[1]);
    // CsvFormulaGuard prepends a single quote to values starting with "="
    expect($row[0])->toStartWith("'");
});

// ── Sub-task 4.5: Tenant isolation ───────────────────────────────────────────

test('4.5 tenant A fee-report shows only tenant A courses, not tenant B courses', function () {
    $tenantA = Tenant::factory()->create();
    $domainA = strtolower(Str::random(8)).'.coaching.test';
    $tenantA->domains()->create(['domain' => $domainA, 'type' => 'subdomain']);

    $tenantB = Tenant::factory()->create();
    $domainB = strtolower(Str::random(8)).'.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);

    $ownerA = inTenant($tenantA, function () {
        $owner = User::factory()->owner()->create();
        Course::factory()->published()->create(['title' => 'Tenant A Course']);

        return $owner;
    });

    inTenant($tenantB, function () {
        User::factory()->owner()->create();
        Course::factory()->published()->create(['title' => 'Tenant B Course']);
    });

    $response = $this->actingAs($ownerA, 'tenant')
        ->get("http://{$domainA}/manage/fee-report");

    $response->assertOk();
    $response->assertSeeText('Tenant A Course');
    $response->assertDontSee('Tenant B Course');
});
