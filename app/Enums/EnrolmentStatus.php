<?php

namespace App\Enums;

enum EnrolmentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
