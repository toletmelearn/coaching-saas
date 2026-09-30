<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function manageSettings(User $actor, Tenant $tenant): bool
    {
        return $actor->role->isOwner();
    }
}
