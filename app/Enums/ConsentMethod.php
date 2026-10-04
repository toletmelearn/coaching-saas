<?php

namespace App\Enums;

/**
 * How a consent was physically collected from the guardian — the field the
 * notice form labels "Collection method". Bulk import records every consent
 * with GuardianInPerson as the file-level method (Decision 3, Phase 15).
 */
enum ConsentMethod: string
{
    case GuardianWhatsapp = 'guardian_whatsapp';
    case GuardianInPerson = 'guardian_in_person';
    case GuardianSignedForm = 'guardian_signed_form';

    /**
     * Every value as a plain list, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
