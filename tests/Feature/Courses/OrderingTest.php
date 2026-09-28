<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

test('new lessons append at the end', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $chapter, $first, $second] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $first = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'position' => 1]);
        $second = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'position' => 2]);

        return [$owner, $chapter, $first, $second];
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/chapters/{$chapter->id}/lessons", [
            'title' => 'Third lesson',
        ])->assertRedirect();

    $third = inTenant($tenant, fn () => Lesson::where('title', 'Third lesson')->firstOrFail());

    expect($third->position)->toBe(3);
});

test('moving a lesson up or down swaps positions and never produces duplicates', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $chapter, $first, $second, $third] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $first = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'position' => 1]);
        $second = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'position' => 2]);
        $third = Lesson::factory()->for($chapter)->create(['course_id' => $course->id, 'position' => 3]);

        return [$owner, $chapter, $first, $second, $third];
    });

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$second->id}/move-up")
        ->assertRedirect();

    inTenant($tenant, function () use ($first, $second, $third) {
        $first->refresh();
        $second->refresh();
        $third->refresh();
    });

    expect($second->position)->toBe(1)
        ->and($first->position)->toBe(2)
        ->and($third->position)->toBe(3);

    $positions = inTenant($tenant, fn () => Lesson::where('chapter_id', $chapter->id)->pluck('position')->sort()->values()->all());
    expect($positions)->toBe([1, 2, 3]);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$second->id}/move-down")
        ->assertRedirect();

    inTenant($tenant, function () use ($first, $second, $third) {
        $first->refresh();
        $second->refresh();
        $third->refresh();
    });

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($third->position)->toBe(3);
});
