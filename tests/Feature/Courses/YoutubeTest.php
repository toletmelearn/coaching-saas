<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

test('YouTube URL on a non-free lesson is rejected', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $chapter] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return [$owner, $chapter];
    });

    // Positive control: a YouTube URL on a free-preview lesson is accepted
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/chapters/{$chapter->id}/lessons", [
            'title' => 'Free lesson',
            'is_free_preview' => true,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/chapters/{$chapter->id}/lessons", [
            'title' => 'Paid lesson',
            'is_free_preview' => false,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

    $response->assertSessionHasErrors('youtube_url');
    expect(inTenant($tenant, fn () => Lesson::where('title', 'Paid lesson')->first()))->toBeNull();

    $response->assertSessionHasErrors(['youtube_url' => __('courses.manage.video_free_preview_only')]);
});

test('turning off free preview while a video is set is rejected', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        return [$owner, $lesson];
    });

    // Positive control: updating the title while keeping free preview on succeeds
    $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/lessons/{$lesson->id}", [
            'title' => 'Updated title',
            'is_free_preview' => true,
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/lessons/{$lesson->id}", [
            'title' => 'Updated title',
            'is_free_preview' => false,
        ]);

    $response->assertSessionHasErrors('is_free_preview');

    inTenant($tenant, fn () => $lesson->refresh());
    expect($lesson->youtube_video_id)->toBe('dQw4w9WgXcQ');
});

test('the rendered lesson embed uses youtube-nocookie.com', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
});

test('the lesson page sets a safe Referrer-Policy and the iframe carries a matching referrerpolicy attribute', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $response = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertSee('referrerpolicy="strict-origin-when-cross-origin"', false);
});
