<?php

namespace App\Http\Controllers\Manage;

use App\Enums\EnrolmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Support\Csv\CsvFormulaGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance report + CSV export for one live class (Phase 12).
 *
 * The page is deliberately bounded: attendance rows come back in one eager
 * query, the absentee list in one more (enrolments + their users), so the
 * whole report is a fixed number of queries whether the tenant has five
 * students or a hundred (LiveClassReportTest asserts exactly that).
 *
 * ?min_minutes=N filters the attendee list to stays of at least N minutes and
 * hides the absentee list entirely — "who cleared the bar" is a different
 * question from "who showed up", and mixing them would make the filtered view
 * lie about who was absent from a filtered-out seat.
 */
class LiveClassAttendanceController extends Controller
{
    public function index(Request $request, Course $course, LiveClass $liveClass): View
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $minMinutes = $this->minMinutes($request);

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->with('user')
            ->orderByDesc('duration_seconds')
            ->orderBy('joined_at')
            ->get();

        $absent = collect();

        if ($minMinutes === null) {
            $absent = Enrolment::where('course_id', $course->id)
                ->where('status', EnrolmentStatus::Active->value)
                ->whereNotIn('user_id', $attendance->pluck('user_id'))
                ->with('user')
                ->get();
        } else {
            $attendance = $attendance
                ->filter(fn (LiveClassAttendance $row) => (int) $row->duration_seconds >= $minMinutes * 60)
                ->values();
        }

        return view('manage.live-classes.attendance', [
            'course' => $course,
            'liveClass' => $liveClass,
            'attendance' => $attendance,
            'absent' => $absent,
            'minMinutes' => $minMinutes,
            'enrolledCount' => Enrolment::where('course_id', $course->id)
                ->where('status', EnrolmentStatus::Active->value)
                ->count(),
        ]);
    }

    public function export(Course $course, LiveClass $liveClass): StreamedResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manageLiveClasses', $course);

        $rows = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->with('user')
            ->orderByDesc('duration_seconds')
            ->orderBy('joined_at')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Student', 'Email', 'Joined at', 'Left at', 'Minutes']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    // Formula guard: a student named =HYPERLINK(...) (or +,-,@)
                    // must land in the cell as inert text, not as a formula.
                    CsvFormulaGuard::escape((string) $row->user?->name),
                    CsvFormulaGuard::escape((string) $row->user?->email),
                    $row->joined_at->format('Y-m-d H:i:s'),
                    $row->left_at?->format('Y-m-d H:i:s'),
                    (string) (int) round((int) $row->duration_seconds / 60),
                ]);
            }

            fclose($out);
        }, "live-class-{$liveClass->id}-attendance.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function minMinutes(Request $request): ?int
    {
        $raw = $request->query('min_minutes');

        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        return max(0, (int) $raw);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('coaching.live_classes_enabled'), Response::HTTP_NOT_FOUND);
    }
}
