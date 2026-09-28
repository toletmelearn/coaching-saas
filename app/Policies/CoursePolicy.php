<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canManageCourses();
    }

    public function view(User $actor, Course $course): bool
    {
        return $actor->role->canManageCourses();
    }

    public function create(User $actor): bool
    {
        return $actor->role->isOwner();
    }

    public function update(User $actor, Course $course): bool
    {
        return $actor->role->isOwner();
    }

    public function publish(User $actor, Course $course): bool
    {
        return $actor->role->isOwner();
    }

    public function archive(User $actor, Course $course): bool
    {
        return $actor->role->isOwner();
    }

    public function manageContent(User $actor, Course $course): bool
    {
        return $actor->role->canManageCourses();
    }

    public function manageEnrolments(User $actor, Course $course): bool
    {
        return $actor->role->isOwner();
    }
}
