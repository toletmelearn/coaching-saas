<?php

namespace App\Http\Controllers\Manage;

use App\Enums\CourseStatus;
use App\Enums\LiveClassStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LiveClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The tenant-wide live-class list (Phase 12.1).
 *
 * Phase 12's list is reachable only through one course; this is the same
 * information for every course in the tenant, which is what makes the feature
 * findable from the header. Authorization is the same bar as
 * CoursePolicy::manageLiveClasses (canManageCourses — owner/staff, never a
 * student), the feature flag 404s exactly as it does on every other live-class
 * surface, and BelongsToTenant does the rest: a class in another tenant can
 * never appear in this table.
 *
 * Courses and attendance counts ride along with the rows (`with`/`withCount`),
 * so the query count is flat no matter how many classes the tenant accumulates.
 */
class LiveClassOverviewController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless((bool) config('coaching.live_classes_enabled'), 404);
        abort_unless(Auth::guard('tenant')->user()->role->canManageCourses(), 403);

        // Anything unrecognised means "all" — never an empty page pretending
        // the tenant has no classes.
        $requested = (string) $request->query('filter', 'all');
        $filter = in_array($requested, ['upcoming', 'past', 'all'], true) ? $requested : 'all';

        $query = LiveClass::with('course')->withCount('attendance');

        if ($filter === 'upcoming') {
            // Still has a door: not started, or running right now.
            $query->whereIn('status', [LiveClassStatus::Scheduled->value, LiveClassStatus::Live->value]);
        } elseif ($filter === 'past') {
            $query->whereIn('status', [LiveClassStatus::Ended->value, LiveClassStatus::Cancelled->value]);
        }

        // Soonest first for what is still to come, newest first for history —
        // a past list read top-down should begin with what just happened.
        $query->orderBy('starts_at', $filter === 'past' ? 'desc' : 'asc');

        // Courses for the "schedule a class" picker — only published/draft ones
        // that the teacher can actually schedule into (archived courses are excluded).
        $courses = Course::whereIn('status', [CourseStatus::Published->value, CourseStatus::Draft->value])
            ->orderBy('title')
            ->get(['id', 'title']);

        return view('manage.live-classes.overview', [
            'liveClasses' => $query->get(),
            'filter' => $filter,
            'courses' => $courses,
        ]);
    }
}
