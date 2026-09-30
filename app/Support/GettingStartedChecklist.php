<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;

/**
 * The owner dashboard's "Getting started" checklist: six steps, each a cheap tenant-scoped
 * existence check (no N+1 — every check is its own single bounded query).
 */
class GettingStartedChecklist
{
    /**
     * @return list<array{key: string, label: string, done: bool, link: string}>
     */
    public static function forTenant(Tenant $tenant): array
    {
        return [
            [
                'key' => 'create_course',
                'label' => __('dashboard.getting_started.steps.create_course'),
                'done' => Course::query()->exists(),
                'link' => '/manage/courses/create',
            ],
            [
                'key' => 'add_lesson',
                'label' => __('dashboard.getting_started.steps.add_lesson'),
                'done' => Lesson::query()->exists(),
                'link' => '/manage/courses',
            ],
            [
                'key' => 'add_note_or_video',
                'label' => __('dashboard.getting_started.steps.add_note_or_video'),
                'done' => LessonAttachment::query()->exists() || LessonVideo::query()->exists(),
                'link' => '/manage/courses',
            ],
            [
                'key' => 'add_students',
                'label' => __('dashboard.getting_started.steps.add_students'),
                'done' => User::where('role', UserRole::Student)->exists(),
                'link' => '/users/create',
            ],
            [
                'key' => 'enrol_student',
                'label' => __('dashboard.getting_started.steps.enrol_student'),
                'done' => Enrolment::query()->exists(),
                'link' => '/manage/courses',
            ],
            [
                'key' => 'set_branding',
                'label' => __('dashboard.getting_started.steps.set_branding'),
                'done' => $tenant->logo_path !== null && $tenant->contact_phone !== null,
                'link' => '/manage/settings',
            ],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, done: bool, link: string}>  $checklist
     */
    public static function allDone(array $checklist): bool
    {
        return collect($checklist)->every(fn ($step) => $step['done'] === true);
    }
}
