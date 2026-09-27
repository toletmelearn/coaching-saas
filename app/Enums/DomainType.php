<?php

namespace App\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';
}
