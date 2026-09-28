<?php

namespace App\Support;

use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\User;

class LessonAccess
{
    public function isStaffOrOwner(?User $user): bool
    {
        return $user !== null && in_array($user->role, [UserRole::Owner, UserRole::Staff], true);
    }

    public function courseIsVisible(?User $user, Course $course): bool
    {
        if ($this->isStaffOrOwner($user)) {
            return true;
        }

        return match ($course->status) {
            CourseStatus::Published => true,
            CourseStatus::Draft => false,
            CourseStatus::Archived => $user !== null && $this->hasValidEnrolment($user, $course),
        };
    }

    /**
     * @return 'ok'|'redirect_login'|'forbidden'|'not_found'
     */
    public function lessonAccess(?User $user, Lesson $lesson): string
    {
        if ($this->isStaffOrOwner($user)) {
            return 'ok';
        }

        $course = $lesson->course;

        if ($lesson->status !== LessonStatus::Published || $course->status === CourseStatus::Draft) {
            return 'not_found';
        }

        if ($course->status === CourseStatus::Archived) {
            return ($user !== null && $this->hasValidEnrolment($user, $course)) ? 'ok' : 'not_found';
        }

        if ($lesson->is_free_preview) {
            return 'ok';
        }

        if ($user === null) {
            return 'redirect_login';
        }

        return $this->hasValidEnrolment($user, $course) ? 'ok' : 'forbidden';
    }

    public function findEnrolment(User $user, Course $course): ?Enrolment
    {
        return Enrolment::where('course_id', $course->id)->where('user_id', $user->id)->first();
    }

    public function hasValidEnrolment(User $user, Course $course): bool
    {
        $enrolment = $this->findEnrolment($user, $course);

        return $enrolment !== null && $enrolment->isValidNow();
    }
}
