<?php

namespace App\Http\Controllers;

use App\Enums\LiveClassStatus;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\User;
use App\Support\LiveClasses\JitsiJoinUrl;
use App\Support\LiveClasses\JitsiJwt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The student half of Phase 12: the class page, the join redirect and the
 * attendance heartbeat.
 *
 * Two invariants, both pinned by tests/Feature/LiveClasses:
 *  - the room name and the JaaS URL leave this controller only inside a 302
 *    Location header, never in a rendered page;
 *  - the heartbeat reads NOTHING from the request body — every timestamp and
 *    the credited duration are server-derived, capped at 60s per beat, and the
 *    third beat in a 60-second window is a flat 429.
 */
class LiveClassController extends Controller
{
    public function show(Request $request, LiveClass $liveClass): View
    {
        $this->ensureEnabled();
        Gate::authorize('view', $liveClass);

        /** @var User $user */
        $user = $request->user('tenant');

        $status = $liveClass->status;
        $minutesUntilStart = (int) ceil(
            ($liveClass->starts_at->getTimestamp() - now()->getTimestamp()) / 60
        );

        // Joinable states mirror LiveClassPolicy::join() — the button and the
        // gate can never disagree, because both read the same window config.
        $canJoin = $status === LiveClassStatus::Live
            || ($status === LiveClassStatus::Scheduled
                && $minutesUntilStart <= (int) config('coaching.live_class_join_window_minutes', 15));

        return view('live-classes.show', [
            'liveClass' => $liveClass,
            'status' => $status,
            'minutesUntilStart' => max(0, $minutesUntilStart),
            'canJoin' => $canJoin,
            'hasAttended' => LiveClassAttendance::where('live_class_id', $liveClass->id)
                ->where('user_id', $user->id)
                ->exists(),
            'recordingEnabled' => (bool) config('coaching.live_classes_recording_enabled'),
        ]);
    }

    public function join(Request $request, LiveClass $liveClass): RedirectResponse
    {
        $this->ensureEnabled();

        // Authorisation first: a stranger gets the same 403 whether or not the
        // class happens to be joinable, and never a flash explaining the schedule.
        Gate::authorize('view', $liveClass);

        $refusal = $this->refusalReason($liveClass);

        if ($refusal !== null) {
            return redirect("/live-classes/{$liveClass->id}")
                ->withErrors(['live_class' => __('live_classes.'.$refusal)]);
        }

        /** @var User $user */
        $user = $request->user('tenant');

        $this->openAttendanceSession($liveClass, $user);

        $appId = (string) config('services.jitsi.app_id');
        $secret = (string) config('services.jitsi.app_secret');

        $jwt = JitsiJwt::make($appId, $secret, $user->name, $user->email);
        $url = JitsiJoinUrl::build($appId, $liveClass->jitsi_room_name, $jwt);

        return redirect()->away($url);
    }

    public function heartbeat(Request $request, LiveClass $liveClass): Response
    {
        $this->ensureEnabled();

        // Flat 403 for every refusal: not enrolled, cancelled, ended, or still
        // outside the pre-open window. Policy first, throttle second, so an
        // unauthorised caller never consumes the enrolled student's budget.
        Gate::authorize('join', $liveClass);

        /** @var User $user */
        $user = $request->user('tenant');

        $key = sprintf(
            'live-class-heartbeat:%d:%d:%d',
            $liveClass->tenant_id,
            $user->id,
            $liveClass->id
        );

        if (RateLimiter::tooManyAttempts($key, (int) config('coaching.live_class_heartbeat_max_attempts', 2))) {
            abort(Response::HTTP_TOO_MANY_REQUESTS);
        }

        RateLimiter::hit($key, (int) config('coaching.live_class_heartbeat_decay_seconds', 60));

        // The request body is intentionally ignored — forged joined_at /
        // last_seen_at / duration_seconds values never reach this code path.
        $this->creditHeartbeat($liveClass, $user);

        return response()->noContent();
    }

    /**
     * Why this class cannot be joined right now, as a lang key suffix — null
     * when it can. Status-driven: live-classes:update-status runs every minute,
     * so status is the single source of truth for "ended".
     */
    private function refusalReason(LiveClass $liveClass): ?string
    {
        return match ($liveClass->status) {
            LiveClassStatus::Cancelled => 'cancelled',
            LiveClassStatus::Ended => 'already_ended',
            LiveClassStatus::Live => null,
            LiveClassStatus::Scheduled => $liveClass->starts_at->lte(
                now()->addMinutes((int) config('coaching.live_class_join_window_minutes', 15))
            ) ? null : 'not_yet_open',
        };
    }

    /**
     * Opens a stay, or resumes the open one when the gap since its last
     * heartbeat is still one heartbeat period (a refresh/double-click is the
     * same session); a longer gap starts a second row so the report can tell
     * one long stay from two short ones.
     */
    private function openAttendanceSession(LiveClass $liveClass, User $user): void
    {
        $open = $this->openRow($liveClass, $user);
        $now = now();

        if ($open !== null && $now->getTimestamp() - $open->last_seen_at->getTimestamp() <= 60) {
            $open->forceFill(['last_seen_at' => $now])->save();

            return;
        }

        $row = new LiveClassAttendance;
        $row->forceFill([
            'live_class_id' => $liveClass->id,
            'user_id' => $user->id,
            'joined_at' => $now,
            'last_seen_at' => $now,
            'duration_seconds' => 0,
        ])->save();
    }

    /**
     * Credits the elapsed wall-clock seconds since the row's own server-set
     * last_seen_at, capped at 60 per beat — an absent or silent tab can never
     * be billed as attended time it did not survive.
     */
    private function creditHeartbeat(LiveClass $liveClass, User $user): void
    {
        $open = $this->openRow($liveClass, $user);
        $now = now();

        if ($open === null) {
            // The row was closed (stale sweep, or this is a fresh join-free
            // beacon): start a new session rather than resurrect a closed one.
            $row = new LiveClassAttendance;
            $row->forceFill([
                'live_class_id' => $liveClass->id,
                'user_id' => $user->id,
                'joined_at' => $now,
                'last_seen_at' => $now,
                'duration_seconds' => 0,
            ])->save();

            return;
        }

        $elapsed = max(0, $now->getTimestamp() - $open->last_seen_at->getTimestamp());

        $open->forceFill([
            'last_seen_at' => $now,
            'duration_seconds' => (int) $open->duration_seconds + min($elapsed, 60),
        ])->save();
    }

    private function openRow(LiveClass $liveClass, User $user): ?LiveClassAttendance
    {
        return LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->orderByDesc('joined_at')
            ->first();
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('coaching.live_classes_enabled'), Response::HTTP_NOT_FOUND);
    }
}
