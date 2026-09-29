<?php

use App\Enums\VideoStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

test('videos:sync moves a processing video to ready using a faked provider response', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'bunny_library_id' => 111,
        'bunny_library_api_key' => 'library-key',
        'bunny_library_token_key' => 'token-key',
    ])->save();

    $video = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->bunny()->create(['status' => VideoStatus::Processing]);
    });

    Http::fake([
        'video.bunnycdn.com/library/111/videos/*' => Http::response(['status' => 4, 'length' => 132], 200),
    ]);

    $this->artisan('videos:sync')->assertSuccessful();

    inTenant($tenant, function () use ($video) {
        $video->refresh();
        expect($video->status)->toBe(VideoStatus::Ready)
            ->and($video->duration_seconds)->toBe(132);
    });
});

test('videos:sync moves a processing video to failed and records the provider error', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'bunny_library_id' => 111,
        'bunny_library_api_key' => 'library-key',
        'bunny_library_token_key' => 'token-key',
    ])->save();

    $video = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->bunny()->create(['status' => VideoStatus::Uploading]);
    });

    Http::fake([
        'video.bunnycdn.com/library/111/videos/*' => Http::response(['status' => 5], 200),
    ]);

    $this->artisan('videos:sync')->assertSuccessful();

    inTenant($tenant, function () use ($video) {
        $video->refresh();
        expect($video->status)->toBe(VideoStatus::Failed)
            ->and($video->error_message)->not->toBeNull();
    });
});

test('videos:sync does not touch a video already ready (positive control: an untouched row stays untouched)', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'bunny_library_id' => 111,
        'bunny_library_api_key' => 'library-key',
        'bunny_library_token_key' => 'token-key',
    ])->save();

    $video = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->bunny()->ready()->create();
    });

    Http::fake(); // Any call at all would be a bug for an already-settled video.

    $this->artisan('videos:sync')->assertSuccessful();

    Http::assertNothingSent();
    inTenant($tenant, fn () => expect($video->refresh()->status)->toBe(VideoStatus::Ready));
});

test('videos:sync scopes each row to its own tenant, never using an ambient TenantContext', function () {
    config(['coaching.video_driver' => 'bunny']);

    $tenantA = Tenant::factory()->create();
    $tenantA->forceFill(['bunny_library_id' => 111, 'bunny_library_api_key' => 'key-a', 'bunny_library_token_key' => 'tk-a'])->save();
    $tenantB = Tenant::factory()->create();
    $tenantB->forceFill(['bunny_library_id' => 222, 'bunny_library_api_key' => 'key-b', 'bunny_library_token_key' => 'tk-b'])->save();

    $videoA = inTenant($tenantA, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->bunny()->create(['status' => VideoStatus::Processing]);
    });
    $videoB = inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->bunny()->create(['status' => VideoStatus::Processing]);
    });

    Http::fake([
        'video.bunnycdn.com/library/111/videos/*' => Http::response(['status' => 4, 'length' => 10], 200),
        'video.bunnycdn.com/library/222/videos/*' => Http::response(['status' => 4, 'length' => 20], 200),
    ]);

    $this->artisan('videos:sync')->assertSuccessful();

    Http::assertSent(fn ($r) => $r->hasHeader('AccessKey', 'key-a') && str_contains($r->url(), '/library/111/'));
    Http::assertSent(fn ($r) => $r->hasHeader('AccessKey', 'key-b') && str_contains($r->url(), '/library/222/'));
    Http::assertNotSent(fn ($r) => $r->hasHeader('AccessKey', 'key-a') && str_contains($r->url(), '/library/222/'));

    inTenant($tenantA, fn () => expect($videoA->refresh()->duration_seconds)->toBe(10));
    inTenant($tenantB, fn () => expect($videoB->refresh()->duration_seconds)->toBe(20));
});
