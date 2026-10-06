<?php

namespace App\Http\Controllers\Manage;

use App\Actions\EnrolStudentAction;
use App\Enums\CourseStatus;
use App\Enums\EnrolmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class EnrolmentController extends Controller
{
    public function __construct(private readonly EnrolStudentAction $enrolStudent) {}

    public function index(Course $course, Request $request): View
    {
        Gate::authorize('manageEnrolments', $course);

        $enrolments = $course->enrolments()->with('user')->orderByDesc('created_at')->paginate(20);

        $search = trim((string) $request->query('q', ''));
        $studentsQuery = User::where('role', UserRole::Student)->where('status', UserStatus::Active)->orderBy('name');

        if ($search !== '') {
            $normalizedPhone = User::normalizePhone($search);

            $studentsQuery->where(function ($query) use ($search, $normalizedPhone) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");

                if ($normalizedPhone !== '') {
                    $query->orWhere('phone', 'like', "%{$normalizedPhone}%");
                }
            });
        }

        $students = $studentsQuery->get();

        return view('manage.enrolments.index', [
            'course' => $course,
            'enrolments' => $enrolments,
            'students' => $students,
            'search' => $search,
        ]);
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('manageEnrolments', $course);

        abort_if($course->status !== CourseStatus::Published, 403);

        $validator = Validator::make($request->all(), [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'payment_note' => ['nullable', 'string', 'max:255'],
        ], [
            'user_ids.required' => __('courses.manage.validation.select_student'),
            'user_ids.min' => __('courses.manage.validation.select_student'),
            'ends_at.after' => __('courses.manage.validation.end_after_start'),
        ]);

        $validator->after(function ($validator) use ($request, $course) {
            foreach ((array) $request->input('user_ids', []) as $userId) {
                $user = User::where('id', $userId)->first();

                if ($user === null || $user->role !== UserRole::Student || $user->status !== UserStatus::Active) {
                    $validator->errors()->add('user_ids', __('courses.manage.enrolment_ineligible'));
                }
            }

            // ends_at is required when the course has no default duration.
            if (empty($request->input('ends_at')) && $course->enrolment_duration === null) {
                $validator->errors()->add('ends_at', __('courses.manage.validation.ends_at_required'));
            }
        });

        $data = $validator->validate();

        $data['ends_at'] = $this->resolveEndsAt(
            $data['starts_at'],
            $data['ends_at'] ?? null,
            $course,
        );

        $actor = $request->user('tenant');
        $enrolled = 0;
        $alreadyEnrolled = 0;

        $startsAt = Carbon::parse($data['starts_at']);

        foreach ($data['user_ids'] as $userId) {
            $existing = Enrolment::where('course_id', $course->id)->where('user_id', $userId)->first();

            if ($existing !== null && $existing->status === EnrolmentStatus::Active) {
                $alreadyEnrolled++;

                continue;
            }

            $student = User::find($userId);
            ($this->enrolStudent)($course, $student, $startsAt, $data['ends_at'] ?? null, $data['payment_note'] ?? null, $actor->id);

            $enrolled++;
        }

        return redirect("/manage/courses/{$course->id}/enrolments")
            ->with('enrolment_summary', __('courses.manage.enrolment_summary', [
                'enrolled' => $enrolled,
                'already' => $alreadyEnrolled,
            ]));
    }

    public function revoke(Request $request, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('revoke', $enrolment);

        $enrolment->forceFill([
            'status' => EnrolmentStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $request->user('tenant')->id,
        ])->save();

        return redirect("/manage/courses/{$enrolment->course_id}/enrolments");
    }

    public function reenrol(Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('reenrol', $enrolment);

        $enrolment->forceFill([
            'status' => EnrolmentStatus::Active,
            'revoked_at' => null,
            'revoked_by' => null,
        ])->save();

        return redirect("/manage/courses/{$enrolment->course_id}/enrolments");
    }

    public function extend(Request $request, Course $course, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('extend', $enrolment);

        abort_if($enrolment->status === EnrolmentStatus::Revoked, 403);

        $request->validate([
            'duration' => ['nullable', 'in:' . implode(',', Course::DURATIONS)],
            'new_ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        if ($request->filled('new_ends_at')) {
            $newEndsAt = Carbon::parse($request->input('new_ends_at'), 'Asia/Kolkata')->endOfDay()->utc();
        } else {
            $duration = $request->input('duration');
            $base = $enrolment->ends_at?->copy() ?? now();
            if ($base->lt(now())) {
                $base = now();
            }
            $newEndsAt = match ($duration) {
                '1_day' => $base->addDay(),
                '1_week' => $base->addWeek(),
                '1_month' => $base->addMonth(),
                '3_months' => $base->addMonths(3),
                '6_months', 'session' => $base->addMonths(6),
                'lifetime' => null,
                default => null,
            };
        }

        $enrolment->ends_at = $newEndsAt;
        $enrolment->save();

        AdminAuditLog::record(
            action: 'enrolment_extended',
            targetType: 'Enrolment',
            targetId: $enrolment->id,
        );

        return redirect("/manage/courses/{$course->id}/enrolments");
    }

    private function resolveEndsAt(string $startsAt, ?string $rawEndsAt, Course $course): ?Carbon
    {
        if (!empty($rawEndsAt)) {
            return Carbon::parse($rawEndsAt, 'Asia/Kolkata')->endOfDay()->utc();
        }

        if ($course->enrolment_duration === null || $course->enrolment_duration === 'lifetime') {
            return null;
        }

        $base = Carbon::parse($startsAt, 'Asia/Kolkata');

        return match ($course->enrolment_duration) {
            '1_day' => $base->addDay()->endOfDay()->utc(),
            '1_week' => $base->addWeek()->endOfDay()->utc(),
            '1_month' => $base->addMonth()->endOfDay()->utc(),
            '3_months' => $base->addMonths(3)->endOfDay()->utc(),
            '6_months', 'session' => $base->addMonths(6)->endOfDay()->utc(),
            default => null,
        };
    }
}
