<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the optional meeting_url field on live classes (Batch 2.1).
 *
 * Allowed: null / empty string (field is optional), or an https:// URL whose
 * host is on the explicit allow-list. Rejects javascript:, data:, http://,
 * and any host not in the list — an operator who accidentally pastes a plain
 * http link gets a clear error rather than silently storing an insecure URL.
 */
class MeetingUrl implements ValidationRule
{
    private const ALLOWED_HOSTS = [
        'meet.google.com',
        'zoom.us',
        'teams.microsoft.com',
        'us02web.zoom.us',
        'us04web.zoom.us',
        'us05web.zoom.us',
    ];

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

            return;
        }

        $host = $parsed['host'] ?? '';

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            $fail(__('live_classes.manage.meeting_url_host_not_allowed'));
        }
    }
}
