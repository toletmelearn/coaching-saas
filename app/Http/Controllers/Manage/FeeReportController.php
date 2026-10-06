<?php

namespace App\Http\Controllers\Manage;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Payment;
use App\Support\Csv\CsvFormulaGuard;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FeeReportController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewFeeReport', Payment::class);

        [$courses, $monthly] = $this->buildData();

        return view('manage.fee-report.index', compact('courses', 'monthly'));
    }

    public function export(): Response
    {
        Gate::authorize('viewFeeReport', Payment::class);

        [$courses] = $this->buildData();

        $filename = 'fee-report-'.now()->timezone('Asia/Kolkata')->format('Y-m-d').'.csv';

        // Build CSV into php://temp so getContent() works in tests (StreamedResponse
        // writes to php://output and getContent() returns false in that case).
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['course', 'paid_count', 'total_collected_inr', 'unpaid_count']);

        foreach ($courses as $course) {
            fputcsv($handle, [
                CsvFormulaGuard::escape($course->title),
                (string) $course->paid_count,
                number_format($course->total_paise / 100, 2, '.', ''),
                (string) $course->unpaid_count,
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{0: Collection, 1: Collection}
     */
    private function buildData(): array
    {
        $tenantId = app(TenantContext::class)->id();

        // Approved payment stats grouped by course.
        // Raw query for cross-table aggregation; verified to filter by tenant_id.
        $paidStats = DB::table('payments as p')
            ->join('enrolments as e', function ($join) {
                $join->on('p.enrolment_id', '=', 'e.id')
                     ->on('p.tenant_id', '=', 'e.tenant_id');
            })
            ->where('p.tenant_id', $tenantId)
            ->where('p.status', PaymentStatus::Approved->value)
            ->selectRaw('e.course_id, COUNT(DISTINCT e.id) as paid_count, COALESCE(SUM(p.amount_paise), 0) as total_paise')
            ->groupBy('e.course_id')
            ->get()
            ->keyBy('course_id');

        // Total enrolments per course; unpaid_count = total - paid_count.
        // Raw query; verified to filter by tenant_id.
        $enrolmentTotals = DB::table('enrolments')
            ->where('tenant_id', $tenantId)
            ->selectRaw('course_id, COUNT(*) as total')
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        $courses = Course::query()
            ->orderBy('title')
            ->get()
            ->map(function ($course) use ($paidStats, $enrolmentTotals) {
                $stats = $paidStats->get($course->id);
                $totals = $enrolmentTotals->get($course->id);

                $course->paid_count = $stats ? (int) $stats->paid_count : 0;
                $course->total_paise = $stats ? (int) $stats->total_paise : 0;
                $totalEnrolments = $totals ? (int) $totals->total : 0;
                $course->unpaid_count = $totalEnrolments - $course->paid_count;

                return $course;
            });

        // Monthly collections grouped by IST calendar month.
        // PHP-side timezone conversion keeps this database-agnostic (no CONVERT_TZ).
        // Raw query; verified to filter by tenant_id.
        $allApproved = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('status', PaymentStatus::Approved->value)
            ->whereNotNull('reviewed_at')
            ->select(['amount_paise', 'reviewed_at'])
            ->get();

        $monthly = $allApproved
            ->groupBy(fn ($p) => Carbon::parse($p->reviewed_at)->timezone('Asia/Kolkata')->format('Y-m'))
            ->map(function ($group, $key) {
                return (object) [
                    'month'      => Carbon::parse($group->first()->reviewed_at)->timezone('Asia/Kolkata')->format('F Y'),
                    'total_paise' => (int) $group->sum('amount_paise'),
                    'month_key'  => $key,
                ];
            })
            ->sortByDesc('month_key')
            ->values();

        return [$courses, $monthly];
    }
}
