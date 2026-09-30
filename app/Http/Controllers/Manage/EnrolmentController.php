<?php

namespace App\Http\Controllers\Manage;

use App\Enums\CourseStatus;
use App\Enums\EnrolmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
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

        $validator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('user_ids', []) as $userId) {
                $user = User::where('id', $userId)->first();

                if ($user === null || $user->role !== UserRole::Student || $user->status !== UserStatus::Active) {
                    $validator->errors()->add('user_ids', __('courses.manage.enrolment_ineligible'));
                }
            }
        });

        $data = $validator->validate();

        // A date-only "Ends" value (whether pre-filled from the institute's academic
        // year end or typed in directly) means the enrolment is valid through the end
        // of that day in India, not from midnight at its start. An empty string (the
        // teacher explicitly cleared the pre-filled value) means no expiry at all —
        // normalized to null rather than left as '' (Carbon::parse('') means "now",
        // not "no value").
        $data['ends_at'] = empty($data['ends_at'])
            ? null
            : Carbon::parse($data['ends_at'], 'Asia/Kolkata')->endOfDay()->utc();

        $actor = $request->user('tenant');
        $enrolled = 0;
        $alreadyEnrolled = 0;

        foreach ($data['user_ids'] as $userId) {
            $existing = Enrolment::where('course_id', $course->id)->where('user_id', $userId)->first();

            if ($existing !== null && $existing->status === EnrolmentStatus::Active) {
                $alreadyEnrolled++;

                continue;
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'status' => EnrolmentStatus::Active,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'] ?? null,
                    'payment_note' => $data['payment_note'] ?? null,
                    'revoked_at' => null,
                    'revoked_by' => null,
                ])->save();
            } else {
                $enrolment = new Enrolment([
                    'course_id' => $course->id,
                    'user_id' => $userId,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'] ?? null,
                    'payment_note' => $data['payment_note'] ?? null,
                ]);
                $enrolment->forceFill(['enrolled_by' => $actor->id]);
                $enrolment->save();
            }

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
}
