<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the optional meeting_url field on live classes.
 *
 * Allowed: null / empty string (field is optional), or any https:// URL up to
 * 2048 characters. Rejects javascript:, data:, and http:// — an operator who
 * accidentally pastes a plain http link gets a clear error. Any https provider
 * is accepted (Google Meet, Zoom, Jitsi, Whereby, Teams, etc.).
 */
class MeetingUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || strlen($value) > 2048) {
            $fail(__('live_classes.manage.meeting_url_invalid'));

            return;
        }

        $parsed = parse_url($value);

        if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https') {
            $fail(__('live_classes.manage.meeting_url_https_required'));
        }
    }
}
