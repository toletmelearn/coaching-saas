<?php

namespace App\Http\Controllers\Manage;

use App\Enums\ConsentMethod;
use App\Enums\ConsentPurpose;
use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

/**
 * Phase 15 — the owner/staff side of DPDP consent for one student: the list of
 * recorded consents, the record form, and withdrawal of a single consent.
 *
 * Authorization is UserPolicy::manageConsents — owner/staff and a student
 * target only; a student requesting any of these (even their own) is a 403, and
 * a target from another tenant never resolves (TenantScope) so it is a 404.
 */
class StudentConsentsController extends Controller
{
    public function index(User $student): View
    {
        Gate::authorize('manageConsents', $student);

        $consents = Consent::query()
            ->where('user_id', $student->id)
            ->with('recorder')
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get();

        return view('manage.students.consents', [
            'student' => $student,
            'consents' => $consents,
            'purposes' => ConsentPurpose::cases(),
            'methods' => ConsentMethod::cases(),
        ]);
    }

    public function store(Request $request, User $student): RedirectResponse
    {
        Gate::authorize('manageConsents', $student);

        $data = $request->validate([
            'purpose' => ['required', new Enum(ConsentPurpose::class)],
            'consent_method' => ['required', new Enum(ConsentMethod::class)],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relationship' => ['nullable', 'string', 'max:100'],
            'guardian_phone' => ['nullable', 'string', 'max:20'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
        ]);

        // Guardian details posted alongside the consent are saved onto the
        // student record; fields that were left empty change nothing.
        $guardian = collect(['guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email'])
            ->filter(fn (string $column): bool => isset($data[$column]) && $data[$column] !== '')
            ->mapWithKeys(fn (string $column): array => [$column => $data[$column]]);

        if ($guardian->isNotEmpty()) {
            $student->forceFill($guardian->all())->save();
        }

        $actor = $request->user('tenant');

        $consent = new Consent;
        $consent->forceFill([
            'user_id' => $student->id,
            'purpose' => $data['purpose'],
            'method' => $data['consent_method'],
            'notice_version' => Consent::NOTICE_VERSION,
            'granted_at' => now(),
            'recorded_by' => $actor->id,
        ]);
        $consent->save();

        return redirect("/manage/students/{$student->id}/consents");
    }

    public function withdrawForm(User $student, Consent $consent): View
    {
        Gate::authorize('manageConsents', $student);

        abort_unless((int) $consent->user_id === (int) $student->id, 404);

        return view('manage.students.withdraw', [
            'student' => $student,
            'consent' => $consent,
        ]);
    }

    public function withdraw(Request $request, User $student, Consent $consent): RedirectResponse
    {
        Gate::authorize('manageConsents', $student);

        abort_unless((int) $consent->user_id === (int) $student->id, 404);

        // Idempotent: the first withdrawal is the record. A repeat POST changes nothing, so the
        // withdrawn_at timestamp and reason in the ledger are never overwritten.
        if ($consent->withdrawn_at !== null) {
            return redirect("/manage/students/{$student->id}/consents");
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // The grant row survives: only the withdrawal columns are set, so the
        // history keeps both what was consented to and why it stopped.
        $consent->forceFill([
            'withdrawn_at' => now(),
            'withdrawn_reason' => $data['reason'],
        ])->save();

        if ($consent->purpose === ConsentPurpose::ProgressTracking->value) {
            // Opting out of tracking erases what was tracked — this student's
            // progress rows only, never anyone else's.
            LessonProgress::query()->where('user_id', $consent->user_id)->delete();
        }

        // course_delivery needs no stored side effect: ConsentAccess reads the
        // withdrawal itself and the course/lesson controllers refuse from there.

        return redirect("/manage/students/{$student->id}/consents");
    }
}
