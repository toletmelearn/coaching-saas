<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

test('draft lessons do not render at all on the course page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $course = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'title' => 'Published Lesson Alpha',
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
        Lesson::factory()->for($chapter)->draft()->create([
            'course_id' => $course->id,
            'title' => 'Secret Draft Lesson',
        ]);

        return $course;
    });

    $response = $this->get("http://{$domain}/courses/{$course->slug}");

    $response->assertOk();
    $response->assertSee('Published Lesson Alpha');
    $response->assertDontSee('Secret Draft Lesson');

    // No stray empty <li></li> for the hidden draft lesson.
    $liCount = substr_count($response->getContent(), '<li class="py-2">');
    expect($liCount)->toBe(1);
});

test('the catalogue lesson count only counts published lessons', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, function () {
        $course = Course::factory()->published()->create(['title' => 'Count Test Course']);
        $chapter = Chapter::factory()->for($course)->create();

        Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        Lesson::factory()->for($chapter)->draft()->create(['course_id' => $course->id]);
    });

    $response = $this->get("http://{$domain}/courses");

    $response->assertOk();
    $response->assertSee(__('courses.index.lessons_count', ['count' => 2]));
    $response->assertDontSee(__('courses.index.lessons_count', ['count' => 3]));
});

test('the login page shows the tenant name at the top', function () {
    $tenant = Tenant::factory()->create(['name' => 'Bright Future Academy']);
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $response = $this->get("http://{$domain}/login");

    $response->assertOk();
    $response->assertSee('Bright Future Academy');
});

test('tenant home redirects guests to /courses and logged-in users to /dashboard', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $this->get("http://{$domain}/")->assertRedirect("http://{$domain}/courses");

    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/")
        ->assertRedirect("http://{$domain}/dashboard");
});

test('the central domain home page shows a simple translated platform page, not the Laravel welcome page', function () {
    $centralDomain = config('tenancy.central_domains')[0] ?? 'coaching.test';

    $response = $this->get("http://{$centralDomain}/");

    $response->assertOk();
    $response->assertSee(__('platform.home.heading'));
    $response->assertSee(__('platform.home.message'));
});

test('owner/staff dashboard links to Courses and People; student dashboard shows My courses', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $student, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $student = User::factory()->student()->create();
        $course = Course::factory()->published()->create(['title' => 'My Enrolled Course']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$owner, $student, $course];
    });

    $ownerResponse = $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard");
    $ownerResponse->assertOk();
    $ownerResponse->assertSee('/manage/courses', false);
    $ownerResponse->assertSee('/users', false);

    freshRequestCycle();

    $studentResponse = $this->actingAs($student, 'tenant')->get("http://{$domain}/dashboard");
    $studentResponse->assertOk();
    $studentResponse->assertSee(__('nav.my_courses'));
    $studentResponse->assertSee('My Enrolled Course');
});
