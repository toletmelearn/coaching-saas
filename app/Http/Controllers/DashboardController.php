<?php

namespace App\Http\Controllers;

use App\Enums\LiveClassStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveClass;
use App\Models\Payment;
use App\Support\GettingStartedChecklist;
use App\Support\LessonAccess;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(LessonAccess $access, TenantContext $tenantContext): View
    {
        $user = Auth::guard('tenant')->user();

        if ($user->role->canManageUsers()) {
            $checklist = null;
            $showGettingStarted = false;

            if ($user->role === UserRole::Owner) {
                $tenant = $tenantContext->get();
                $checklist = GettingStartedChecklist::forTenant($tenant);
                $showGettingStarted = $tenant->getting_started_dismissed_at === null
                    && ! GettingStartedChecklist::allDone($checklist);
            }

            // Live classes (Phase 12.1): the same two queries the student branch
            // runs below, but tenant-wide — an owner or staff member manages every
            // course in this tenant, so no course filter is needed.
            $live = $this->liveClassSummary();

            return view('dashboard.staff', [
                'user' => $user,
                'checklist' => $checklist,
                'showGettingStarted' => $showGettingStarted,
                // How many payments are waiting on a decision (Phase 10). Zero hides
                // the badge entirely rather than showing "0".
                'pendingPayments' => Payment::where('status', PaymentStatus::Pending->value)->count(),
                'liveNowClasses' => $live['live'],
                'startingSoonClasses' => $live['soon'],
            ]);
        }

        $enrolments = Enrolment::where('user_id', $user->id)->with('course')->get();
        $active = $enrolments->filter(fn (Enrolment $enrolment) => $enrolment->isValidNow());
        $ended = $enrolments->reject(fn (Enrolment $enrolment) => $enrolment->isValidNow());

        $completedLessonIds = LessonProgress::where('user_id', $user->id)
            ->whereIn('course_id', $active->pluck('course_id'))
            ->whereNotNull('completed_at')
            ->pluck('lesson_id')
            ->flip();

        // For each active enrolment: the first lesson the student can open that they
        // haven't completed yet (never a completed lesson — Continue always moves
        // forward), and a completed/total tally for the course's progress bar.
        $courseProgress = $active->mapWithKeys(function (Enrolment $enrolment) use ($user, $access, $completedLessonIds) {
            $orderedLessons = Lesson::orderedPublishedForCourse($enrolment->course);

            $nextLesson = $orderedLessons->first(
                fn (Lesson $lesson) => $access->lessonAccess($user, $lesson) === 'ok' && ! $completedLessonIds->has($lesson->id)
            );

            $total = $orderedLessons->count();
            $completed = $orderedLessons->filter(fn (Lesson $lesson) => $completedLessonIds->has($lesson->id))->count();

            return [$enrolment->course_id => [
                'nextLesson' => $nextLesson,
                'total' => $total,
                'completed' => $completed,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
                'allCompleted' => $total > 0 && $completed >= $total,
            ]];
        });

        // Live classes (Phase 12): what this student's enrolled courses are
        // running right now, and what starts inside the announcement window.
        // Scoped to their own enrolments — a course they are not enrolled in
        // never appears on their dashboard.
        $live = $this->liveClassSummary($active->pluck('course_id'));

        return view('dashboard.student', [
            'user' => $user,
            'active' => $active,
            'ended' => $ended,
            'courseProgress' => $courseProgress,
            'liveNowClasses' => $live['live'],
            'startingSoonClasses' => $live['soon'],
        ]);
    }

    /**
     * The two queries behind the dashboard's Live classes card (Phase 12.1):
     * what is running right now, and what starts inside
     * coaching.live_class_upcoming_window_hours (24h by default) — the same
     * pair for both roles, differing only in scope.
     *
     * null $courseIds = every course in the tenant (owner/staff manage them
     * all); otherwise only those courses (a student sees their enrolments).
     * A feature that is switched off, or a student with no enrolments, costs
     * zero queries — the card simply renders nothing.
     *
     * @param  Collection<int, int>|null  $courseIds
     * @return array{live: Collection<int, LiveClass>, soon: Collection<int, LiveClass>}
     */
    private function liveClassSummary(?Collection $courseIds = null): array
    {
        if (! (bool) config('coaching.live_classes_enabled')) {
            return ['live' => collect(), 'soon' => collect()];
        }

        if ($courseIds !== null && $courseIds->isEmpty()) {
            return ['live' => collect(), 'soon' => collect()];
        }

        $live = LiveClass::query()
            ->with('course')
            ->where('status', LiveClassStatus::Live->value)
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderBy('starts_at')
            ->get();

        $soon = LiveClass::query()
            ->with('course')
            ->where('status', LiveClassStatus::Scheduled->value)
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds))
            ->whereBetween('starts_at', [
                now(),
                now()->addHours((int) config('coaching.live_class_upcoming_window_hours', 24)),
            ])
            ->orderBy('starts_at')
            ->get();

        return ['live' => $live, 'soon' => $soon];
    }

    public function dismissGettingStarted(TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        $tenant->forceFill(['getting_started_dismissed_at' => now()])->save();

        return redirect('/dashboard');
    }
}
