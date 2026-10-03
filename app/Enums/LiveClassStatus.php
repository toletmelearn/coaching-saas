<?php

namespace App\Enums;

/**
 * Phase 12 — the lifecycle of a scheduled Jitsi session.
 *
 * Transitions are driven exclusively by live-classes:update-status (every
 * minute): scheduled -> live at starts_at, live -> ended at ends_at (or
 * starts_at + 90 minutes when no end was given). Cancelled is set by a
 * teacher and is never transitioned back.
 */
enum LiveClassStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
