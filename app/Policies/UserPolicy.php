<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return in_array($actor->role, ['owner', 'staff'], true);
    }

    public function view(User $actor, User $target): bool
    {
        if (in_array($actor->role, ['owner', 'staff'], true)) {
            return true;
        }

        return $actor->id === $target->id;
    }

    public function create(User $actor): bool
    {
        return in_array($actor->role, ['owner', 'staff'], true);
    }

    /**
     * Whether the actor may create a user with the given target role.
     * Owners may create any role; staff may only create students.
     */
    public function createWithRole(User $actor, string $role): bool
    {
        if ($actor->role === 'owner') {
            return true;
        }

        if ($actor->role === 'staff') {
            return $role === 'student';
        }

        return false;
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $actor->role === 'owner';
    }

    /**
     * Whether a target user's role may be changed. Blocks demoting the last active owner.
     */
    public function changeRole(User $actor, User $target): bool
    {
        if ($target->role === 'owner' && $this->activeOwnerCount($target) <= 1) {
            return false;
        }

        return true;
    }

    public function disable(User $actor, User $target): bool
    {
        if ($actor->role !== 'owner') {
            return false;
        }

        if ($target->role === 'owner' && $this->activeOwnerCount($target) <= 1) {
            return false;
        }

        return true;
    }

    /**
     * Re-activating a disabled user only ever increases the active-owner count, so
     * (unlike disable()) the last-owner rule never applies here.
     */
    public function enable(User $actor, User $target): bool
    {
        return $actor->role === 'owner';
    }

    public function resetPassword(User $actor, User $target): bool
    {
        if ($actor->role === 'owner') {
            return true;
        }

        if ($actor->role === 'staff') {
            return $target->role === 'student';
        }

        return false;
    }

    private function activeOwnerCount(User $target): int
    {
        return User::query()
            ->where('role', 'owner')
            ->where('status', 'active')
            ->count();
    }
}
