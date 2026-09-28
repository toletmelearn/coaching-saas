<?php

namespace App\Policies;

use App\Models\Enrolment;
use App\Models\User;

class EnrolmentPolicy
{
    public function revoke(User $actor, Enrolment $enrolment): bool
    {
        return $actor->role->isOwner();
    }

    public function reenrol(User $actor, Enrolment $enrolment): bool
    {
        return $actor->role->isOwner();
    }
}
