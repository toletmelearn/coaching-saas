<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Support\GettingStartedChecklist;
use App\Support\LessonAccess;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
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

            return view('dashboard.staff', [
                'user' => $user,
                'checklist' => $checklist,
                'showGettingStarted' => $showGettingStarted,
                // How many payments are waiting on a decision (Phase 10). Zero hides
                // the badge entirely rather than showing "0".
                'pendingPayments' => Payment::where('status', PaymentStatus::Pending->value)->count(),
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

        return view('dashboard.student', [
            'user' => $user,
            'active' => $active,
            'ended' => $ended,
            'courseProgress' => $courseProgress,
        ]);
    }

    public function dismissGettingStarted(TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        $tenant->forceFill(['getting_started_dismissed_at' => now()])->save();

        return redirect('/dashboard');
    }
}
