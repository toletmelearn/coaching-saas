<?php

namespace App\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom'; // Reserved for Stage C — no creation code path exists yet; see docs/specs/phase-14-custom-domains.md and ROADMAP.md Stage C.
}
