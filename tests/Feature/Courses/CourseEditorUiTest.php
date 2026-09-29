<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('lesson edit page labels the free-preview checkbox correctly, not "Video coming soon"', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/lessons/{$lesson->id}/edit");

    $response->assertOk();
    $response->assertSee(__('courses.manage.free_preview_label'));
    $response->assertDontSee(__('lessons.video_coming_soon'));
});

test('an existing lesson can be edited: title, description, board tag, free preview and YouTube URL', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create([
            'course_id' => $course->id,
            'title' => 'Old title',
        ]);

        return [$owner, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/lessons/{$lesson->id}", [
            'title' => 'New title',
            'description' => 'New description',
            'board_tag' => 'CBSE',
            'is_free_preview' => true,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

    $response->assertRedirect();

    inTenant($tenant, fn () => $lesson->refresh());

    expect($lesson->title)->toBe('New title')
        ->and($lesson->description)->toBe('New description')
        ->and($lesson->board_tag)->toBe('CBSE')
        ->and($lesson->is_free_preview)->toBeTrue()
        ->and($lesson->youtube_video_id)->toBe('dQw4w9WgXcQ');
});

test('the course editor shows compact lesson rows (title, status, and Edit/move/publish actions), delete only on the edit page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'title' => 'Compact Row Lesson']);

        return [$owner, $course, $lesson];
    });

    $editorResponse = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}");

    $editorResponse->assertOk();
    $editorResponse->assertSee('Compact Row Lesson');
    $editorResponse->assertSee(__('courses.manage.edit'));
    // No delete form for the lesson on the course editor page itself.
    $editorResponse->assertDontSee('/manage/lessons/'.$lesson->id.'"', false);

    freshRequestCycle();

    // Positive control: delete IS available (and works) from the lesson's own edit page.
    $editResponse = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/lessons/{$lesson->id}/edit");
    $editResponse->assertOk();
    $editResponse->assertSee(__('courses.manage.delete'));

    freshRequestCycle();

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/lessons/{$lesson->id}")
        ->assertRedirect();

    expect(inTenant($tenant, fn () => Lesson::find($lesson->id)))->toBeNull();
});

test('uploading a PDF shows a success message and the file name in the notes list', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    $uploadResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('Chapter Notes.pdf', 100, 'application/pdf'),
        ]);
    $uploadResponse->assertRedirect("http://{$domain}/manage/lessons/{$lesson->id}/edit");

    $editResponse = $this->get("http://{$domain}/manage/lessons/{$lesson->id}/edit");
    $editResponse->assertOk();
    $editResponse->assertSee(__('courses.manage.attachment_uploaded', ['name' => 'Chapter Notes.pdf']));
    $editResponse->assertSee('Chapter Notes.pdf');
});

test('PDF upload errors are shown in plain teacher language', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    // Positive control: a real PDF upload succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('good.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

    $notPdfResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);
    $notPdfResponse->assertRedirect("http://{$domain}/manage/lessons/{$lesson->id}/edit");
    $notPdfResponse->assertSessionHasErrors(['file' => __('courses.manage.attachment_errors.not_pdf')]);

    $maxMb = config('coaching.max_attachment_mb', 20);
    $tooLargeResponse = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', ($maxMb * 1024) + 100, 'application/pdf'),
        ]);
    $tooLargeResponse->assertSessionHasErrors(['file' => __('courses.manage.attachment_errors.too_large', ['max' => $maxMb])]);
});

test('the course editor links to its enrolments screen and the public course page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();

        return [$owner, $course];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}");

    $response->assertOk();
    $response->assertSee('/manage/courses/'.$course->id.'/enrolments', false);
    $response->assertSee('/courses/'.$course->slug, false);
});

test('confirm dialogs are JSON-encoded so an apostrophe/quote in the translation cannot break the onsubmit attribute', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    // Override the real (normal, teacher-facing) translation for this test only, with
    // one deliberately containing an apostrophe and a double quote -- exactly the input
    // that breaks a naive onsubmit="return confirm('{{ __(...) }}')".
    app('translator')->addLines(['courses.manage.confirm_delete' => 'It\'s "tricky"'], 'en');

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/lessons/{$lesson->id}/edit");

    $response->assertOk();

    preg_match('/onsubmit="return confirm\((.*?)\)"/', $response->getContent(), $match);
    expect($match)->not->toBeEmpty();

    $jsArgument = $match[1];

    // The raw apostrophe/quote must never appear unescaped in the attribute -- only
    // their hex-escaped form (Illuminate\Support\Js::from).
    $escapedApostrophe = sprintf('%s%04x', '\\u', ord("'"));
    $escapedQuote = sprintf('%s%04x', '\\u', ord('"'));

    expect($jsArgument)->not->toContain("It's")
        ->toContain('It'.$escapedApostrophe.'s')
        ->toContain($escapedQuote.'tricky'.$escapedQuote);
});

test('course and lesson statuses are translated on the course editor page, not printed as raw enum values', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->draft()->create();
        $chapter = Chapter::factory()->for($course)->create();
        Lesson::factory()->for($chapter)->draft()->create(['course_id' => $course->id, 'title' => 'Status Label Lesson']);

        return [$owner, $course];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}");

    $response->assertOk();
    $response->assertSee(__('courses.manage.course_statuses.draft'));
    $response->assertSee(__('courses.manage.lesson_statuses.draft'));
    $response->assertDontSee('>draft<', false);
});
