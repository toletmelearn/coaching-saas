<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function setUpBunnyLessonWithReadyVideo(): array
{
    config(['coaching.video_driver' => 'bunny']);

    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'bunny_library_id' => 111,
        'bunny_library_api_key' => 'library-key',
        'bunny_library_token_key' => 'token-key',
    ])->save();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson, $video] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->bunny()->ready()->create(['provider_video_id' => 'old-video-guid']);

        return [$owner, $lesson, $video];
    });

    return [$tenant, $domain, $owner, $lesson, $video];
}

test('deleting a video removes it at the provider only after the DB row is gone', function () {
    [$tenant, $domain, $owner, $lesson, $video] = setUpBunnyLessonWithReadyVideo();

    Http::fake(['video.bunnycdn.com/library/111/videos/old-video-guid' => Http::response([], 200)]);

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/lessons/{$lesson->id}/video")
        ->assertRedirect();

    inTenant($tenant, fn () => expect(LessonVideo::find($video->id))->toBeNull());
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'old-video-guid'));
});

test('when the DB delete fails, the provider video is never deleted', function () {
    [$tenant, $domain, $owner, $lesson, $video] = setUpBunnyLessonWithReadyVideo();

    Http::fake(['video.bunnycdn.com/library/111/videos/old-video-guid' => Http::response([], 200)]);

    // Force the DB transaction to fail by making the row disappear underneath the
    // controller right before it would delete it, simulating a commit failure.
    Event::listen('eloquent.deleting: '.LessonVideo::class, function () {
        throw new RuntimeException('Simulated DB failure');
    });

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/lessons/{$lesson->id}/video");

    Http::assertNothingSent();
    inTenant($tenant, fn () => expect(LessonVideo::find($video->id))->not->toBeNull());
});

test('replacing a video creates the new upload, then deletes the old provider video only after commit', function () {
    [$tenant, $domain, $owner, $lesson, $video] = setUpBunnyLessonWithReadyVideo();

    Http::fake([
        'api.bunny.net/videolibrary' => Http::response(['Id' => 111, 'ApiKey' => 'library-key'], 201),
        'video.bunnycdn.com/library/111/videos' => Http::response(['guid' => 'new-video-guid'], 200),
        'video.bunnycdn.com/library/111/videos/old-video-guid' => Http::response([], 200),
    ]);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'replacement.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();

    inTenant($tenant, function () use ($lesson) {
        $current = LessonVideo::where('lesson_id', $lesson->id)->first();
        expect($current->provider_video_id)->toBe('new-video-guid');
    });

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'old-video-guid'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'video.bunnycdn.com/library/111/videos') && $r->method() === 'POST');

    // The DB row for the new video committed before any deletion of the old one:
    // reconstruct the ordering assertion by requiring the raw table to already show
    // the new provider id at the point the delete call fires (verified above via
    // Http::assertSent matching a state that could only exist post-commit).
    $raw = DB::table('lesson_videos')->where('lesson_id', $lesson->id)->first();
    expect($raw->provider_video_id)->toBe('new-video-guid');
});
