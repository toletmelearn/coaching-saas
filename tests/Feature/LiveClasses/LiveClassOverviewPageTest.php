<?php

use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Tests\Support\LiveClassFixtures;
use Tests\Support\MobileSafetyChecker;

/**
 * Phase 12.1 — the cross-course /manage/live-classes page.
 *
 * Phase 12's list is reachable only through one course
 * (/manage/courses/{course}/live-classes); this page is the tenant-wide view an
 * owner opens from the header. The contract: owner and staff only, every course
 * in one table (course, title, start, status, attendance count, actions),
 * upcoming/past/all filters, and no N+1 — the attendance column comes from
 * withCount(), never a per-row query.
 */

/**
 * The single <tr> that carries $title. The overview table has one row per live
 * class, so the row containing a distinctive title is the row under test —
 * used to assert several cells (course, status, attendance, actions) at once
 * instead of matching bare numbers against the whole page.
 *
 * Returns '' when no such row exists, which every ->toContain() assertion below
 * then fails on — the right failure mode for a row that never rendered.
 */
function overviewRowFor(string $html, string $title): string
{
    return preg_match('/<tr[^>]*>.*?'.preg_quote($title, '/').'.*?<\/tr>/s', $html, $m) ? $m[0] : '';
}

// === Access ===

test('the cross-course live-classes page is guest-redirected, student-403 and owner/staff-200', function () {
    $f = LiveClassFixtures::setup();

    // Guest first: actingAs() below leaves the guard holding a user for the rest
    // of the test, so the unauthenticated case has to run before any of them.
    $this->get("http://{$f['domain']}/manage/live-classes")
        ->assertRedirect("http://{$f['domain']}/login");

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/manage/live-classes")
        ->assertForbidden();

    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/live-classes")
        ->assertOk();

    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/live-classes")
        ->assertOk();
});

test('the cross-course live-classes page 404s while the feature flag is off', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner']);

    config(['coaching.live_classes_enabled' => false]);

    // Same contract as every other live-class surface (Phase 12): off means the
    // feature does not exist, not "an empty page that is really a dead link".
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/live-classes")
        ->assertNotFound();
});

// === The list itself ===

test('the list shows every live class across courses with its course, status, attendance count and actions', function () {
    $f = LiveClassFixtures::setup(['students' => 2]);

    $other = inTenant($f['tenant'], fn () => Course::factory()->published()->create([
        'title' => 'Chemistry for the overview page',
    ]));

    $algebra = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Algebra live room',
        'starts_at' => now()->addHour(),
    ]);
    LiveClassFixtures::attend($algebra, $f['students'][0]);
    LiveClassFixtures::attend($algebra, $f['students'][1]);

    // A second course's class, already over — proves the page is tenant-wide
    // rather than a restatement of one course's list.
    $chemistry = LiveClassFixtures::liveClass($other, $f['owner'], [
        'title' => 'Chemistry live room',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => 'ended',
    ]);

    $page = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/live-classes");
    $page->assertOk();

    $html = $page->getContent();
    $algebraRow = overviewRowFor($html, 'Algebra live room');
    $chemistryRow = overviewRowFor($html, 'Chemistry live room');

    expect($algebraRow)->not->toBe('')
        ->and($algebraRow)->toContain($f['course']->title)
        ->and($algebraRow)->toContain(__('live_classes.status.scheduled'))
        ->and($algebraRow)->toContain('<td>2</td>')
        ->and($algebraRow)->toContain(url('/manage/courses/'.$f['course']->id.'/live-classes/'.$algebra->id.'/edit'))
        ->and($algebraRow)->toContain(url('/live-classes/'.$algebra->id.'/join'))
        ->and($algebraRow)->toContain(url('/manage/courses/'.$f['course']->id.'/live-classes/'.$algebra->id.'/attendance'));

    expect($chemistryRow)->not->toBe('')
        ->and($chemistryRow)->toContain('Chemistry for the overview page')
        ->and($chemistryRow)->toContain(__('live_classes.status.ended'))
        ->and($chemistryRow)->toContain('<td>0</td>')
        // An over class offers no door: Edit and Attendance stay (the history and
        // the report still matter), Join does not.
        ->and($chemistryRow)->toContain(url('/manage/courses/'.$other->id.'/live-classes/'.$chemistry->id.'/edit'))
        ->and($chemistryRow)->not->toContain('/join');
});

test('the upcoming, past and all filters narrow the list', function () {
    $f = LiveClassFixtures::setup();

    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Filter upcoming room',
        'starts_at' => now()->addHour(),
    ]);
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Filter live room',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Filter ended room',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => 'ended',
    ]);
    LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Filter cancelled room',
        'starts_at' => now()->addDay(),
        'status' => 'cancelled',
    ]);

    $url = "http://{$f['domain']}/manage/live-classes";

    // Default: everything — this page's job is to list every live class.
    $this->actingAs($f['owner'], 'tenant')->get($url)
        ->assertOk()
        ->assertSee('Filter upcoming room')
        ->assertSee('Filter live room')
        ->assertSee('Filter ended room')
        ->assertSee('Filter cancelled room');

    // Upcoming = the classes that still have a door (scheduled + live).
    $this->actingAs($f['owner'], 'tenant')->get($url.'?filter=upcoming')
        ->assertOk()
        ->assertSee('Filter upcoming room')
        ->assertSee('Filter live room')
        ->assertDontSee('Filter ended room')
        ->assertDontSee('Filter cancelled room');

    // Past = history (ended + cancelled).
    $this->actingAs($f['owner'], 'tenant')->get($url.'?filter=past')
        ->assertOk()
        ->assertSee('Filter ended room')
        ->assertSee('Filter cancelled room')
        ->assertDontSee('Filter upcoming room')
        ->assertDontSee('Filter live room');

    $this->actingAs($f['owner'], 'tenant')->get($url.'?filter=all')
        ->assertOk()
        ->assertSee('Filter upcoming room')
        ->assertSee('Filter ended room');

    // An unrecognised value is "all", never an empty page pretending to be one.
    $this->actingAs($f['owner'], 'tenant')->get($url.'?filter=whatever')
        ->assertOk()
        ->assertSee('Filter cancelled room');
});

test('the list stays query-bounded as classes are added (no N+1)', function () {
    $f = LiveClassFixtures::setup();

    $measure = function () use ($f) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/live-classes");
        $page->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach (range(1, 5) as $i) {
        LiveClassFixtures::liveClass($f['course'], $f['owner'], [
            'title' => "Overview room {$i}",
            'starts_at' => now()->addHours($i),
        ]);
    }
    $small = $measure();

    // Fifteen more rows (20 in total) must not buy fifteen more queries: the
    // courses are eager-loaded and the attendance column is a withCount().
    foreach (range(6, 20) as $i) {
        LiveClassFixtures::liveClass($f['course'], $f['owner'], [
            'title' => "Overview room {$i}",
            'starts_at' => now()->addHours($i),
        ]);
    }
    $large = $measure();

    // Measured: 4 queries at 5 classes, 4 at 20 — growth 0. The bound below
    // leaves room for framework noise, not for per-row work.
    expect($large)->toBeLessThanOrEqual(6)
        ->and($large - $small)->toBeLessThanOrEqual(1);
});

// === Discoverability: the header link ===

test('the owner/staff header links to the cross-course live-classes page, and hides with the feature', function () {
    $f = LiveClassFixtures::setup();

    // Built from the tenant host rather than url(): the helper would resolve
    // against config('app.url') before the first request has set the root.
    $navLink = '<a href="http://'.$f['domain'].'/manage/live-classes" class="ui-nav-link"';

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee($navLink, false)
        ->assertSee(__('live_classes.nav_label'));

    // Staff get the same manage bar as the owner (same canManageUsers() nav branch).
    $this->actingAs($f['staff'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertSee($navLink, false);

    // A student's header has no manage links at all, and the page itself is 403.
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee($navLink, false);

    // Flag off: no link to a route that would 404.
    config(['coaching.live_classes_enabled' => false]);

    $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/dashboard")
        ->assertOk()
        ->assertDontSee($navLink, false);
});

// === Mobile ===

test('the cross-course live-classes page is mobile-safe', function () {
    $f = LiveClassFixtures::setup();
    LiveClassFixtures::liveClass($f['course'], $f['owner']);

    $page = $this->actingAs($f['owner'], 'tenant')->get("http://{$f['domain']}/manage/live-classes");

    // Positive control: a 404 page would satisfy the checker vacuously.
    $page->assertOk();

    expect(MobileSafetyChecker::violations($page->getContent()))->toBe([]);
});
