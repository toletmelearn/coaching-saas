<?php

use App\Enums\CourseStatus;
use App\Enums\EnrolmentStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function previewToken(string $domain, User $owner, string $csv, array $extra = []): string
{
    $response = test()->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", array_merge(['file' => csvUploadFile($csv)], $extra));

    $response->assertOk();

    return $response->viewData('token');
}

test('confirm creates only the OK rows: role student, active, must_change_password, hashed password', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $csv = "name,phone,email\nAsha Rao,9876543210,\nBad Row,12345,\n";
    $token = previewToken($domain, $owner, $csv);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $token])
        ->assertRedirect();

    inTenant($tenant, function () {
        expect(User::where('role', 'student')->count())->toBe(1);
        $student = User::where('phone', '9876543210')->first();
        expect($student)->not->toBeNull();
        expect($student->status->value)->toBe('active');
        expect($student->must_change_password)->toBeTrue();
        expect(Hash::check('password', $student->password))->toBeFalse();
    });
});

test('guarded fields cannot be set from the confirm request', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv);

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", [
        'token' => $token,
        'role' => 'owner',
        'status' => 'disabled',
        'must_change_password' => false,
        'tenant_id' => $tenant->id + 999,
    ]);

    inTenant($tenant, function () {
        $student = User::where('phone', '9876543210')->first();
        expect($student->role->value)->toBe('student');
        expect($student->status->value)->toBe('active');
        expect($student->must_change_password)->toBeTrue();
    });
});

test('the token is consumed atomically — a second confirm creates nothing and shows the already-processed message', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv);

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token])->assertRedirect();
    $second = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token]);

    $second->assertSessionHas('error', __('import.already_processed'));
    inTenant($tenant, fn () => expect(User::where('role', 'student')->count())->toBe(1));
});

test('another user cannot confirm someone else\'s import token', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv);

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $token])
        ->assertStatus(404);

    inTenant($tenant, fn () => expect(User::where('role', 'student')->count())->toBe(0));
});

test('an expired token is refused', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv);

    $this->travel(20)->minutes();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $token])
        ->assertStatus(404);

    inTenant($tenant, fn () => expect(User::where('role', 'student')->count())->toBe(0));
});

test('another tenant\'s session cannot see or confirm this token', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv);

    $tenantB = Tenant::factory()->create();
    $domainB = 'tenant-b.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);
    $ownerB = inTenant($tenantB, fn () => User::factory()->owner()->create());

    $this->actingAs($ownerB, 'tenant')
        ->post("http://{$domainB}/users/import/confirm", ['token' => $token])
        ->assertStatus(404);
});

test('the whole batch rolls back if creation fails partway through', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    // Pre-existing user collides on phone once normalized/hashed at creation time via a
    // unique DB constraint race is hard to force generically, so this test forces the
    // failure via a duplicate email that only collides at DB level (case difference is
    // normalized away before insert, so an identical phone inserted twice within the
    // same batch — bypassing the in-file duplicate check by constructing the token
    // directly — exercises the transaction rollback path).
    $csv = "name,phone,email\nAsha Rao,9876543210,\nRahul Jain,9123456780,\n";
    $token = previewToken($domain, $owner, $csv);

    // Simulate a mid-batch failure by having another request create a colliding phone
    // number for this tenant between preview and confirm.
    inTenant($tenant, fn () => User::factory()->student()->create(['phone' => '9123456780']));

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token]);

    inTenant($tenant, function () {
        // Only the pre-existing collision remains — the first row must NOT have been
        // committed on its own if the batch is transactional.
        expect(User::where('phone', '9876543210')->exists())->toBeFalse();
    });
});

// === Optional enrolment ===

test('confirm enrols created students in the chosen published course', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $course = inTenant($tenant, fn () => Course::factory()->published()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv, ['course_id' => $course->id]);

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", [
        'token' => $token,
        'course_id' => $course->id,
    ]);

    inTenant($tenant, function () use ($course) {
        $student = User::where('phone', '9876543210')->first();
        $enrolment = Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first();
        expect($enrolment)->not->toBeNull();
        expect($enrolment->status)->toBe(EnrolmentStatus::Active);
    });
});

test('a draft course cannot be selected for import enrolment', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $course = inTenant($tenant, fn () => Course::factory()->create(['status' => CourseStatus::Draft]));
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv), 'course_id' => $course->id])
        ->assertSessionHasErrors('course_id');
});

test('ends_at from import enrolment is end-of-day Asia/Kolkata, defaulting from the academic year end', function () {
    [$tenant, $domain, $owner] = importFixtureLocal();
    $tenant->forceFill(['academic_year_end' => '2027-03-31'])->save();
    $course = inTenant($tenant, fn () => Course::factory()->published()->create());
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";
    $token = previewToken($domain, $owner, $csv, ['course_id' => $course->id]);

    $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", [
        'token' => $token,
        'course_id' => $course->id,
    ]);

    inTenant($tenant, function () use ($course) {
        $student = User::where('phone', '9876543210')->first();
        $enrolment = Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->first();
        expect($enrolment->ends_at->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'))
            ->toBe('2027-03-31 23:59:59');
    });
});

test('existing enrolment screen behaviour and messages are unchanged by the extraction', function () {
    // Guard against a regression in the shared action: the existing enrolment test
    // suite (tests/Feature/Enrolments) is run untouched as part of composer test — this
    // is a targeted smoke test that the manual enrolment screen still enrolls exactly
    // as before after the action-class extraction.
    [$tenant, $domain, $owner] = importFixtureLocal();
    $course = inTenant($tenant, fn () => Course::factory()->published()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student->id],
            'starts_at' => now()->toDateString(),
        ])
        ->assertRedirect("http://{$domain}/manage/courses/{$course->id}/enrolments");

    inTenant($tenant, fn () => expect(Enrolment::where('course_id', $course->id)->where('user_id', $student->id)->exists())->toBeTrue());
});

function importFixtureLocal(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}
