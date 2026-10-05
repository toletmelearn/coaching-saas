<?php

namespace App\Http\Controllers\Manage;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\Enrolment;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 15 — the student-data screen: JSON export of one student's data and
 * DPDP erasure.
 *
 * Erasure follows the four Phase 15 decisions:
 *
 *  1. The row is anonymised, not dropped: name → "Deleted Student", email → a
 *     unique sentinel on the reserved .invalid TLD (so users_email_or_phone_check
 *     stays intact and the address can never resolve), phone/guardian → null.
 *  4. Enrolments and live-class attendance are HARD-deleted; their key fields —
 *     and those of the payments that cascade away with the enrolments — are
 *     snapshotted as JSON into consent_audit_logs before the delete.
 *
 * Consent rows themselves survive: they are the audit trail of what was
 * collected, not personal data.
 *
 * Authorization is UserPolicy::manageStudentData (owner/staff, student target);
 * another tenant's student never resolves (TenantScope) → 404.
 */
class StudentDataController extends Controller
{
    /** The fixed name an erased student's row is renamed to. */
    public const ERASED_NAME = 'Deleted Student';

    public function show(User $student): View
    {
        Gate::authorize('manageStudentData', $student);

        return view('manage.students.data', ['student' => $student]);
    }

    /**
     * One student's data as a JSON attachment: exactly the six collections the
     * contract names, all scoped to this one student (never their classmates).
     */
    public function export(User $student): JsonResponse
    {
        Gate::authorize('manageStudentData', $student);

        $enrolments = Enrolment::query()->where('user_id', $student->id)->get();

        $payload = [
            'user' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                'role' => $student->role->value,
                'status' => $student->status->value,
                'guardian_name' => $student->guardian_name,
                'guardian_relationship' => $student->guardian_relationship,
                'guardian_phone' => $student->guardian_phone,
                'guardian_email' => $student->guardian_email,
                'created_at' => $student->created_at?->toIso8601String(),
            ],
            'enrolments' => $enrolments->values()->all(),
            'progress' => LessonProgress::query()->where('user_id', $student->id)->get()->values()->all(),
            'devices' => UserDevice::query()->where('user_id', $student->id)->get()->values()->all(),
            'consents' => Consent::query()->where('user_id', $student->id)->get()->values()->all(),
            'payments' => Payment::query()
                ->whereIn('enrolment_id', $enrolments->pluck('id'))
                ->get()
                ->values()
                ->all(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="student-'.$student->id.'-data.json"',
        ], JSON_PRETTY_PRINT);
    }

    public function erase(Request $request, User $student): RedirectResponse
    {
        Gate::authorize('manageStudentData', $student);

        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        // Erasure is irreversible, so the confirmation must be the student's
        // exact name — not a checkbox, not a generic "yes".
        if ($data['confirmation'] !== $student->name) {
            throw ValidationException::withMessages([
                'confirmation' => __('consents.errors.confirmation_mismatch'),
            ]);
        }

        $actor = $request->user('tenant');

        $screenshotPaths = [];

        DB::transaction(function () use ($student, $actor, &$screenshotPaths) {
            $enrolments = DB::table('enrolments')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->get();

            $attendance = DB::table('live_class_attendance')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->get();

            $payments = DB::table('payments')
                ->where('tenant_id', $student->tenant_id)
                ->whereIn('enrolment_id', $enrolments->pluck('id'))
                ->get();

            $screenshotPaths = $payments->pluck('screenshot_path')->filter()->values()->all();

            // Snapshot everything that is about to disappear — the payments
            // first: they leave with their enrolment (ON DELETE CASCADE), and
            // this row is what keeps their financial facts auditable.
            $log = new ConsentAuditLog;
            $log->forceFill([
                'tenant_id' => $student->tenant_id,
                'user_id' => $student->id,
                'actor_id' => $actor->id,
                'action' => ConsentAuditLog::ACTION_STUDENT_ERASED,
                'metadata' => [
                    // Allowlisted: ids, amounts, status and timestamps only. Free text (payment_note,
                    // rejection_reason), UPI references and screenshot paths are not kept.
                    'enrolments' => $enrolments->map(fn ($row): array => collect((array) $row)->only(
                        ['id', 'course_id', 'user_id', 'status', 'starts_at', 'ends_at', 'revoked_at', 'created_at', 'updated_at']
                    )->all())->values()->all(),
                    'live_class_attendance' => $attendance->map(fn ($row): array => collect((array) $row)->only(
                        ['id', 'live_class_id', 'duration_seconds']
                    )->all())->values()->all(),
                    'payments' => $payments->map(fn ($row): array => collect((array) $row)->only(
                        ['id', 'enrolment_id', 'amount_paise', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_at', 'updated_at']
                    )->all())->values()->all(),
                ],
            ]);
            $log->save();

            DB::table('lesson_progress')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->delete();

            DB::table('user_devices')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->delete();

            DB::table('live_class_attendance')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->delete();

            // Hard delete — the row is really gone, and the payments of each
            // enrolment follow it through the composite FK's ON DELETE CASCADE.
            DB::table('enrolments')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $student->id)
                ->delete();

            $student->forceFill([
                'name' => self::ERASED_NAME,
                // Sentinel, not null: users_email_or_phone_check requires one
                // contact column, and .invalid (RFC 2606) can never resolve or
                // receive mail. The row id makes it unique per tenant.
                'email' => sprintf('erased-%d@removed.invalid', $student->id),
                'phone' => null,
                'guardian_name' => null,
                'guardian_relationship' => null,
                'guardian_phone' => null,
                'guardian_email' => null,
                'remember_token' => null,
                // Erased rows can never be used to sign in again: disabled, and given a
                // random password nobody holds (the cast hashes it on assignment).
                'status' => UserStatus::Disabled,
                'password' => Str::random(64),
            ])->save();
        });

        // Files go only after the commit above: a rollback must never leave rows pointing at
        // deleted evidence. A file that cannot be deleted is recorded and reported, not fatal.
        $failedPaths = [];

        foreach ($screenshotPaths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
                $failedPaths[] = $path;
            }
        }

        if ($failedPaths !== []) {
            $log = new ConsentAuditLog;
            $log->forceFill([
                'tenant_id' => $student->tenant_id,
                'user_id' => $student->id,
                'actor_id' => $actor->id,
                'action' => ConsentAuditLog::ACTION_SCREENSHOT_DELETE_FAILED,
                'metadata' => ['paths' => $failedPaths],
            ])->save();

            return redirect()->route('users.index')->with('erasure_warning', __('consents.erasure.file_warning'));
        }

        return redirect()->route('users.index');
    }
}
