<?php

namespace App\Enums;

enum DeviceRevocationReason: string
{
    case Replaced = 'replaced';
    case Owner = 'owner';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case Disabled = 'disabled';
    case Logout = 'logout';
}
