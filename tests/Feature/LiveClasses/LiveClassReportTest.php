<?php

use App\Models\LiveClass;
use Illuminate\Support\Facades\DB;
use Tests\Support\LiveClassFixtures;

/**
 * Builds the standard report fixture: three enrolled, distinctly named students —
 * Alpha attended 30 minutes, Beta attended 5, Gamma never joined.
 *
 * @return array{0: array, 1: LiveClass}
 */
function liveClassReportFixture(): array
{
    $f = LiveClassFixtures::setup(['students' => 3]);

    inTenant($f['tenant'], function () use ($f) {
        $f['students'][0]->forceFill(['name' => 'Alpha Attendee'])->save();
        $f['students'][1]->forceFill(['name' => 'Beta Attendee'])->save();
        $f['students'][2]->forceFill(['name' => 'Gamma Absent'])->save();
    });

    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(10),
        'status' => 'ended',
        'title' => 'Weekly revision class',
    ]);

    LiveClassFixtures::attend($class, $f['students'][0], [
        'joined_at' => now()->subMinutes(50),
        'last_seen_at' => now()->subMinutes(20),
        'left_at' => now()->subMinutes(20),
        'duration_seconds' => 1800,
    ]);
    LiveClassFixtures::attend($class, $f['students'][1], [
        'joined_at' => now()->subMinutes(45),
        'last_seen_at' => now()->subMinutes(40),
        'left_at' => now()->subMinutes(40),
        'duration_seconds' => 300,
    ]);

    return [$f, $class];
}

test('the attendance report lists every enrolled student — attendees sorted by duration, absentees included', function () {
    [$f, $class] = liveClassReportFixture();

    $page = $this->actingAs($f['owner'], 'tenant')->get(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance"
    );

    $page->assertOk()
        ->assertSee('Alpha Attendee')
        ->assertSee('Beta Attendee')
        ->assertSee('Gamma Absent')
        ->assertSee(__('live_classes.absent'));

    $html = $page->getContent();
    expect(strpos($html, 'Alpha Attendee'))->toBeLessThan(strpos($html, 'Beta Attendee'));
});

test('staff can view the attendance report but a student cannot', function () {
    [$f, $class] = liveClassReportFixture();
    $url = "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance";

    // Positive control: staff (canManageCourses) may view
    $this->actingAs($f['staff'], 'tenant')->get($url)->assertOk();

    $this->actingAs($f['student'], 'tenant')->get($url)->assertForbidden();
});

test('the attendance report can be filtered to students who attended at least N minutes', function () {
    [$f, $class] = liveClassReportFixture();
    $url = "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance";

    $page = $this->actingAs($f['owner'], 'tenant')->get("{$url}?min_minutes=6");

    $page->assertOk()
        ->assertSee('Alpha Attendee')
        ->assertDontSee('Beta Attendee')
        ->assertDontSee('Gamma Absent');
});

test('the attendance CSV export is formula-injection guarded', function () {
    $f = LiveClassFixtures::setup(['students' => 2]);

    inTenant($f['tenant'], function () use ($f) {
        $f['students'][0]->forceFill(['name' => '=HYPERLINK("http://evil.example","click")'])->save();
    });

    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(10),
        'status' => 'ended',
    ]);
    LiveClassFixtures::attend($class, $f['students'][0]);

    $response = $this->actingAs($f['owner'], 'tenant')->get(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance/export"
    );

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Student')
        ->and($csv)->toContain('Minutes')
        ->and($csv)->toContain('\'=HYPERLINK');
});

test('the attendance report stays bounded as enrolments grow (no N+1)', function () {
    // Small tenant: 5 enrolled
    $small = LiveClassFixtures::setup(['students' => 5]);
    $smallClass = LiveClassFixtures::liveClass($small['course'], $small['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(10),
        'status' => 'ended',
    ]);
    LiveClassFixtures::attend($smallClass, $small['students'][0]);

    $measure = function (array $f, $class) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = $this->actingAs($f['owner'], 'tenant')->get(
            "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/attendance"
        );
        $page->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $smallCount = $measure($small, $smallClass);

    // Large tenant: 100 enrolled — needs its own host, tenant_domains is unique per domain
    $large = LiveClassFixtures::setup(['students' => 100, 'domain' => 'liveclasses-wide.coaching.test']);
    $largeClass = LiveClassFixtures::liveClass($large['course'], $large['owner'], [
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(10),
        'status' => 'ended',
    ]);
    LiveClassFixtures::attend($largeClass, $large['students'][0]);

    $largeCount = $measure($large, $largeClass);

    // Twenty times the enrolments must not mean twenty times the queries: the
    // page is allowed a small fixed budget, and growing the tenant by 95
    // students may cost at most two more queries than the small tenant.
    expect($largeCount)->toBeLessThanOrEqual(15)
        ->and($largeCount - $smallCount)->toBeLessThanOrEqual(2);
});
