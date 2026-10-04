<?php

namespace App\Enums;

/**
 * The four purposes a DPDP consent notice covers (Phase 15). The order here is
 * the order the creation form renders and the order the Step 1 contract pins:
 * course_delivery, progress_tracking, communication, media_processing.
 */
enum ConsentPurpose: string
{
    case CourseDelivery = 'course_delivery';
    case ProgressTracking = 'progress_tracking';
    case Communication = 'communication';
    case MediaProcessing = 'media_processing';

    /**
     * Every value as a plain list — used to check a submitted `consents[]`
     * array covers all four purposes (order-insensitive).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
