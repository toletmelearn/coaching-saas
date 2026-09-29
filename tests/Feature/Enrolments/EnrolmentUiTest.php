<?php

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;

test('the enrolment form defaults starts to today and leaves ends empty', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();

        return [$owner, $course];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");

    $response->assertOk();
    $response->assertSeeInOrder(['name="starts_at"', 'value="'.now()->toDateString().'"'], false);
    $response->assertSeeInOrder(['name="ends_at"', 'value=""'], false);
});

test('enrolment form validation shows plain-language messages', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student1, $student2] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student1 = User::factory()->student()->create();
        $student2 = User::factory()->student()->create();

        return [$owner, $course, $student1, $student2];
    });

    // No student selected.
    $noStudentResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'starts_at' => now()->toDateString(),
        ]);
    $noStudentResponse->assertSessionHasErrors(['user_ids' => __('courses.manage.validation.select_student')]);

    // Positive control: selecting a student and a valid date range succeeds.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student1->id],
            'starts_at' => now()->toDateString(),
        ])->assertRedirect();

    $badDateResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student2->id],
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);
    $badDateResponse->assertSessionHasErrors(['ends_at' => __('courses.manage.validation.end_after_start')]);
});

test('the enrolled list shows name, translated status, dates (or "No expiry"), and payment note', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student = User::factory()->student()->create(['name' => 'Enrolled Kid']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'ends_at' => null,
            'payment_note' => 'UPI 12 Sep',
        ]);

        return [$owner, $course];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");

    $response->assertOk();
    $response->assertSeeInOrder(['Enrolled Kid', __('courses.manage.enrolment_statuses.active')]);
    $response->assertSee(__('courses.manage.no_expiry'));
    $response->assertSee('UPI 12 Sep');
    $response->assertSee(__('courses.manage.revoke'));
});

test('student checkboxes can be filtered by a text search on name, phone or email', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        User::factory()->student()->create(['name' => 'Aarav Sharma', 'phone' => '9876500001']);
        User::factory()->student()->create(['name' => 'Zoya Khan', 'phone' => '9876500002']);

        return [$owner, $course];
    });

    // Positive control: no filter shows both students.
    $unfiltered = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");
    $unfiltered->assertSee('Aarav Sharma');
    $unfiltered->assertSee('Zoya Khan');

    freshRequestCycle();

    $filtered = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments?q=Aarav");
    $filtered->assertOk();
    $filtered->assertSee('Aarav Sharma');
    $filtered->assertDontSee('Zoya Khan');
});

test('checkbox ids on the enrolment page are unique and each label points at its own input', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $owner = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        Course::factory()->published()->create();
        User::factory()->student()->create(['name' => 'Student One']);
        User::factory()->student()->create(['name' => 'Student Two']);

        return $owner;
    });
    $course = inTenant($tenant, fn () => Course::first());

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/enrolments");

    $response->assertOk();
    $html = $response->getContent();

    preg_match_all('/<label for="([^"]+)"[^>]*>\s*<input\s+type="checkbox"\s+name="user_ids\[\]"\s+id="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(2);

    $ids = array_map(fn ($m) => $m[2], $matches);
    expect($ids)->toBe(array_unique($ids));

    foreach ($matches as $match) {
        [, $labelFor, $inputId] = $match;
        expect($labelFor)->toBe($inputId);
    }
});

test('after a validation error, previously ticked student checkboxes stay ticked', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $student1, $student2] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $student1 = User::factory()->student()->create(['name' => 'Ticked Student']);
        $student2 = User::factory()->student()->create(['name' => 'Untouched Student']);

        return [$owner, $course, $student1, $student2];
    });

    // Trip validation (ends_at before starts_at) while only student1 is selected.
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/courses/{$course->id}/enrolments", [
            'user_ids' => [$student1->id],
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors();

    $response = $this->get("http://{$domain}/manage/courses/{$course->id}/enrolments");
    $response->assertOk();
    $html = $response->getContent();

    preg_match('/<input\s+type="checkbox"\s+name="user_ids\[\]"\s+id="[^"]+"\s+value="'.$student1->id.'"[^>]*>/', $html, $student1Tag);
    preg_match('/<input\s+type="checkbox"\s+name="user_ids\[\]"\s+id="[^"]+"\s+value="'.$student2->id.'"[^>]*>/', $html, $student2Tag);

    expect($student1Tag)->not->toBeEmpty();
    expect($student1Tag[0])->toContain('checked');
    expect($student2Tag)->not->toBeEmpty();
    expect($student2Tag[0])->not->toContain('checked');
});
