<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canManageUsers();
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->role->canManageUsers()) {
            return true;
        }

        return $actor->id === $target->id;
    }

    public function create(User $actor): bool
    {
        return $actor->role->canManageUsers();
    }

    /**
     * Whether the actor may create a user with the given target role.
     * Owners may create any role; staff may only create students.
     */
    public function createWithRole(User $actor, UserRole $role): bool
    {
        if ($actor->role === UserRole::Owner) {
            return true;
        }

        if ($actor->role === UserRole::Staff) {
            return $role === UserRole::Student;
        }

        return false;
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $actor->role === UserRole::Owner;
    }

    /**
     * Whether a target user's role may be changed. Blocks demoting the last active owner.
     */
    public function changeRole(User $actor, User $target): bool
    {
        if ($target->role === UserRole::Owner && $this->activeOwnerCount($target) <= 1) {
            return false;
        }

        return true;
    }

    /**
     * Owner may disable anyone (subject to the last-owner rule below). Staff may only
     * disable students — never other staff, and never an owner.
     */
    public function disable(User $actor, User $target): bool
    {
        if ($actor->role === UserRole::Owner) {
            if ($target->role === UserRole::Owner && $this->activeOwnerCount($target) <= 1) {
                return false;
            }

            return true;
        }

        if ($actor->role === UserRole::Staff) {
            return $target->role === UserRole::Student;
        }

        return false;
    }

    /**
     * Re-activating a disabled user only ever increases the active-owner count, so
     * (unlike disable()) the last-owner rule never applies here. Staff may only
     * re-enable students — never other staff, and never an owner.
     */
    public function enable(User $actor, User $target): bool
    {
        if ($target->isErased()) {
            return false;
        }

        if ($actor->role === UserRole::Owner) {
            return true;
        }

        if ($actor->role === UserRole::Staff) {
            return $target->role === UserRole::Student;
        }

        return false;
    }

    public function resetPassword(User $actor, User $target): bool
    {
        if ($target->isErased()) {
            return false;
        }

        if ($actor->role === UserRole::Owner) {
            return true;
        }

        if ($actor->role === UserRole::Staff) {
            return $target->role === UserRole::Student;
        }

        return false;
    }

    /**
     * Owner and staff may view/manage devices for students only — never for another
     * owner or staff member, and never their own (there is no self-service "my
     * devices" screen yet).
     */
    public function viewDevices(User $actor, User $target): bool
    {
        return $this->manageDevices($actor, $target);
    }

    public function manageDevices(User $actor, User $target): bool
    {
        if ($target->role !== UserRole::Student) {
            return false;
        }

        return in_array($actor->role, [UserRole::Owner, UserRole::Staff], true);
    }

    /**
     * Owner and staff may open a student's consent screens (list, record,
     * withdraw) — a student may never open them, not even their own, and
     * non-student targets have no consent screens at all (Phase 15).
     */
    public function manageConsents(User $actor, User $target): bool
    {
        if ($target->role !== UserRole::Student) {
            return false;
        }

        return in_array($actor->role, [UserRole::Owner, UserRole::Staff], true);
    }

    /**
     * Owner and staff may open a student's data screens (view, JSON export,
     * erasure). Same shape as manageConsents — the two abilities exist so each
     * screen authorizes under its own name when either grows independently.
     */
    public function manageStudentData(User $actor, User $target): bool
    {
        if ($target->role !== UserRole::Student) {
            return false;
        }

        return in_array($actor->role, [UserRole::Owner, UserRole::Staff], true);
    }

    private function activeOwnerCount(User $target): int
    {
        return User::query()
            ->where('role', UserRole::Owner)
            ->where('status', UserStatus::Active)
            ->count();
    }
}
