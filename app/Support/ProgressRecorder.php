<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Pure lesson-progress accounting — plain arrays in, plain arrays out, no Eloquent and
 * no clock access beyond the $now passed in, so it stays fully unit-testable without a
 * database. See docs/specs/phase-7-progress.md "Accounting rules".
 *
 * State array shape (matches the lesson_progress columns 1:1, so the controller can
 * forceFill() the result straight onto the model):
 * [
 *     'watched_seconds' => int,
 *     'duration_seconds' => ?int,
 *     'last_position_seconds' => int,
 *     'completed_at' => ?DateTimeInterface,
 *     'completed_manually' => bool,
 *     'last_heartbeat_at' => ?DateTimeInterface,
 *     'last_activity_at' => ?DateTimeInterface,
 * ]
 *
 * Input array shape: ['position' => float, 'duration' => float, 'played' => float]
 */
class ProgressRecorder
{
    private const DURATION_TOLERANCE = 0.10;

    private const FIRST_HEARTBEAT_CAP_SECONDS = 20;

    private const MAX_DELTA_SECONDS = 60;

    private const WALL_CLOCK_GRACE_SECONDS = 5;

    private const COMPLETION_THRESHOLD = 0.85;

    private const RESUME_MIN_SECONDS = 10;

    private const RESUME_MAX_FRACTION = 0.95;

    public function applyHeartbeat(array $current, array $input, DateTimeInterface $now): array
    {
        $state = $current;

        $duration = $this->resolveDuration($state['duration_seconds'] ?? null, (float) $input['duration']);
        $state['duration_seconds'] = $duration;

        $delta = $this->acceptedDelta($state['last_heartbeat_at'] ?? null, (float) $input['played'], $now);

        $watched = min(($state['watched_seconds'] ?? 0) + $delta, $duration);
        $state['watched_seconds'] = (int) round($watched);

        $position = max(0.0, min((float) $input['position'], $duration));
        $state['last_position_seconds'] = (int) round($position);

        $now = CarbonImmutable::instance($now);
        $state['last_heartbeat_at'] = $now;

        if ($delta > 0) {
            $state['last_activity_at'] = $now;
        }

        if (
            ($state['completed_at'] ?? null) === null
            && $duration > 0
            && $state['watched_seconds'] >= $duration * self::COMPLETION_THRESHOLD
        ) {
            $state['completed_at'] = $now;
            $state['completed_manually'] = false;
        }

        return $state;
    }

    public function resumePosition(int $lastPositionSeconds, ?int $durationSeconds): int
    {
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return 0;
        }

        if ($lastPositionSeconds < self::RESUME_MIN_SECONDS) {
            return 0;
        }

        if ($lastPositionSeconds >= $durationSeconds * self::RESUME_MAX_FRACTION) {
            return 0;
        }

        return $lastPositionSeconds;
    }

    private function resolveDuration(?int $stored, float $incoming): int
    {
        $incomingRounded = (int) round($incoming);

        if ($stored === null) {
            return $incomingRounded;
        }

        if ($stored === 0) {
            return $stored;
        }

        $diff = abs($incomingRounded - $stored) / $stored;

        return $diff <= self::DURATION_TOLERANCE ? $incomingRounded : $stored;
    }

    private function acceptedDelta(?DateTimeInterface $lastHeartbeatAt, float $played, DateTimeInterface $now): float
    {
        if ($lastHeartbeatAt === null) {
            return max(0.0, min($played, self::FIRST_HEARTBEAT_CAP_SECONDS));
        }

        $secondsSince = $now->getTimestamp() - $lastHeartbeatAt->getTimestamp();
        $cap = max(0, min($secondsSince + self::WALL_CLOCK_GRACE_SECONDS, self::MAX_DELTA_SECONDS));

        return max(0.0, min($played, (float) $cap));
    }
}
