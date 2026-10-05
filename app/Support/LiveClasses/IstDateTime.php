<?php

namespace App\Support\LiveClasses;

use Carbon\Carbon;

/**
 * Shared IST ↔ UTC helpers for live-class datetime handling.
 *
 * The web UI uses datetime-local inputs, which send bare wall-clock strings
 * with no timezone (e.g. "2025-01-15T18:00"). The institute's home timezone
 * is Asia/Kolkata (IST, UTC+5:30), so every such string is interpreted as IST
 * and converted to UTC for storage. Display converts the stored UTC back to IST.
 */
class IstDateTime
{
    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * Parse a datetime-local input string (format: Y-m-d\TH:i) as IST and
     * return a UTC datetime string for Eloquent storage.
     * Returns null when the string does not match the expected format.
     */
    public static function fromInput(string $value): ?string
    {
        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value, self::TIMEZONE)
                ->utc()
                ->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert a stored UTC value to an IST datetime-local string for HTML
     * datetime-local input fields.
     */
    public static function toFormValue(mixed $value): string
    {
        return Carbon::parse($value)->timezone(self::TIMEZONE)->format('Y-m-d\TH:i');
    }

    /**
     * Format a stored UTC value for display in IST, e.g. "Mon, 15 Jan 2025, 18:00 IST".
     */
    public static function display(mixed $value): string
    {
        return Carbon::parse($value)->timezone(self::TIMEZONE)->format('D, d M Y, H:i').' IST';
    }

    /**
     * Whether the stored UTC value falls on today's date in IST.
     */
    public static function isToday(mixed $value): bool
    {
        return Carbon::parse($value)->timezone(self::TIMEZONE)->isToday();
    }

    /**
     * Format a stored UTC time as a clock string in IST, e.g. "6:00 PM IST".
     */
    public static function clock(mixed $value): string
    {
        return Carbon::parse($value)->timezone(self::TIMEZONE)->format('g:i A').' IST';
    }

    /**
     * Format a stored UTC time as a date+clock string in IST, e.g. "Mon 15 Jan, 6:00 PM IST".
     */
    public static function dateAndClock(mixed $value): string
    {
        return Carbon::parse($value)->timezone(self::TIMEZONE)->format('D d M, g:i A').' IST';
    }
}
