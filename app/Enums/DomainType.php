<?php

namespace App\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom'; // Reserved for Stage C — no creation code path exists yet; see docs/specs (none yet) and ROADMAP.md Stage C.
}
