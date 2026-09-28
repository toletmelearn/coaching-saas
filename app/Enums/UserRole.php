<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
    case Student = 'student';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function canManageUsers(): bool
    {
        return in_array($this, [self::Owner, self::Staff], true);
    }

    public function canManageCourses(): bool
    {
        return in_array($this, [self::Owner, self::Staff], true);
    }

    public function canManageBilling(): bool
    {
        return $this === self::Owner;
    }
}
