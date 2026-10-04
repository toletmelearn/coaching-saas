<?php

use App\Models\Lesson;
use App\Models\LiveClass;
use Tests\Support\LiveClassFixtures;

/**
 * Phase 12 FK-refusal guard in Manage\LessonController::destroy.
 *
 * live_classes.lesson_id is a restrict-on-delete composite FK, so deleting a
 * lesson a live class hangs off raises SQLSTATE 23000 inside the controller's
 * transaction. The guard catches exactly that SQLSTATE and sends the owner
 * back to the lesson's edit page with a friendly `lesson` error (rendered at
 * the top of the edit view) instead of a 500; every other QueryException is
 * rethrown as the real failure it is.
 *
 * The DB-level restrict is pinned by LiveClassSchemaTest — this file pins the
 * HTTP behaviour layered on top of it, which was untested.
 */
test('deleting a lesson with a linked live class is refused with a friendly redirect and nothing vanishes', function () {
    $f = LiveClassFixtures::setup();

    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Lesson-tied session',
        'lesson_id' => $f['lesson']->id,
    ]);

    $this->actingAs($f['owner'], 'tenant')
        ->delete("http://{$f['domain']}/manage/lessons/{$f['lesson']->id}")
        ->assertRedirect("http://{$f['domain']}/manage/lessons/{$f['lesson']->id}/edit")
        ->assertSessionHasErrors(['lesson' => __('live_classes.lesson_in_use')]);

    // A redirect + flashed message, not a dead end: the lesson and its class
    // must both still exist — a session record can never vanish silently.
    expect(inTenant($f['tenant'], fn () => Lesson::find($f['lesson']->id)))->not->toBeNull()
        ->and(inTenant($f['tenant'], fn () => LiveClass::find($class->id)))->not->toBeNull();
});
