<?php

use App\Http\Controllers\Admin\DemoRequestController as AdminDemoRequestController;
use App\Http\Controllers\Admin\InstituteController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\PlatformAdminLoginController;
use App\Http\Controllers\Auth\PlatformAdminLogoutController;
use App\Http\Controllers\Auth\TenantLoginController;
use App\Http\Controllers\Auth\TenantLogoutController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\LessonAttachmentController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\LessonProgressController;
use App\Http\Controllers\LessonVideoStreamController;
use App\Http\Controllers\LessonVideoUploadController;
use App\Http\Controllers\LiveClassController;
use App\Http\Controllers\Manage\ChapterController as ManageChapterController;
use App\Http\Controllers\Manage\CourseController as ManageCourseController;
use App\Http\Controllers\Manage\CourseProgressController as ManageCourseProgressController;
use App\Http\Controllers\Manage\EnrolmentController as ManageEnrolmentController;
use App\Http\Controllers\Manage\HelpController as ManageHelpController;
use App\Http\Controllers\Manage\LessonAttachmentController as ManageLessonAttachmentController;
use App\Http\Controllers\Manage\LessonController as ManageLessonController;
use App\Http\Controllers\Manage\LessonVideoController as ManageLessonVideoController;
use App\Http\Controllers\Manage\LiveClassAttendanceController as ManageLiveClassAttendanceController;
use App\Http\Controllers\Manage\LiveClassController as ManageLiveClassController;
use App\Http\Controllers\Manage\PaymentController as ManagePaymentController;
use App\Http\Controllers\Manage\SettingsController as ManageSettingsController;
use App\Http\Controllers\Manage\UserDeviceController as ManageUserDeviceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentScreenshotController;
use App\Http\Controllers\PlatformAdminDashboardController;
use App\Http\Controllers\PlatformHomeController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImportController;
use App\Http\Controllers\UserImportCredentialsController;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

// Central-domain-only routes — restricted via Route::domain(), so a tenant subdomain
// never matches any of these at all (routing-level, not just an in-controller check).
foreach (config('tenancy.central_domains', []) as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::get('/', [PlatformHomeController::class, 'show']);
        Route::post('demo-requests', [DemoRequestController::class, 'store']);

        // No ->name() on any route in this loop: Route::domain() runs this same
        // registration once per central domain, so a shared route name would be
        // assigned to several different Route objects — harmless for plain request
        // matching (route:list/route:clear work fine), but php artisan route:cache
        // (part of `optimize`, part of every deploy per docs/DEPLOY.md) serializes
        // routes into a flat name-keyed collection and throws
        // "Another route has already been assigned name [...]" the moment a second
        // domain registers the same name. Controllers build redirect URLs from a
        // plain path instead (matches this app's existing convention elsewhere, e.g.
        // Manage\LessonController), never route($name).
        Route::prefix('admin')->group(function () {
            Route::get('login', [PlatformAdminLoginController::class, 'show']);
            Route::post('login', [PlatformAdminLoginController::class, 'store']);
            Route::post('logout', [PlatformAdminLogoutController::class, 'store'])
                ->middleware('auth:platform_admin');

            Route::middleware('auth:platform_admin')->group(function () {
                Route::get('dashboard', [PlatformAdminDashboardController::class, 'show']);

                Route::get('institutes', [InstituteController::class, 'index']);
                Route::get('institutes/create', [InstituteController::class, 'create']);
                Route::post('institutes', [InstituteController::class, 'store']);
                Route::get('institutes/{tenant}', [InstituteController::class, 'show']);
                Route::post('institutes/{tenant}/suspend', [InstituteController::class, 'suspend']);
                Route::post('institutes/{tenant}/reactivate', [InstituteController::class, 'reactivate']);
                Route::post('institutes/{tenant}/reset-owner-password', [InstituteController::class, 'resetOwnerPassword']);

                Route::get('demo-requests', [AdminDemoRequestController::class, 'index']);
                Route::post('demo-requests/{demoRequest}/mark-contacted', [AdminDemoRequestController::class, 'markContacted']);
            });
        });
    });
}

// Tenant-domain fallback root — only ever reached on a tenant subdomain, since every
// central domain is intercepted by the domain-scoped group above.
Route::get('/', function () {
    if (! app(TenantContext::class)->has()) {
        abort(404);
    }

    return auth('tenant')->check() ? redirect('/dashboard') : redirect('/courses');
});

// Tenant-scoped routes.
Route::middleware('require.tenant')->group(function () {
    Route::get('login', [TenantLoginController::class, 'show'])
        ->name('login')
        ->middleware('device.revoked.notice');
    Route::post('login', [TenantLoginController::class, 'store']);

    // PWA endpoints — no auth required (a guest's browser needs the manifest/icons/
    // service worker too), scoped to the tenant only via require.tenant (404 on a
    // central domain) and the global CheckTenantSuspended middleware (503 while
    // suspended). See docs/specs/phase-6-branding-pwa.md.
    Route::get('manifest.webmanifest', [PwaController::class, 'manifest']);
    Route::get('pwa/icons/{sizeParam}', [PwaController::class, 'icon']);
    Route::get('branding/logo', [PwaController::class, 'logo']);
    Route::get('sw.js', [PwaController::class, 'serviceWorker']);
    Route::get('offline', [PwaController::class, 'offline']);

    // Public catalogue and free-preview lessons — no login required. Both middleware are
    // no-ops for guests (they check for a resolved tenant user first), so a guest still
    // passes through untouched; a logged-in student who is disabled or must change their
    // password gets the same redirect here as on every other tenant route (Phase 4.1 —
    // this group previously sat outside auth:tenant entirely, so a disabled/must-change
    // student's session was never re-checked on these specific routes).
    Route::middleware(['active.tenant.user', 'device.limit', 'must.change.password'])->group(function () {
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('courses/{course:slug}', [CourseController::class, 'show']);
        Route::get('courses/{course:slug}/lessons/{lesson}', [LessonController::class, 'show'])->scopeBindings();
        Route::get('courses/{course:slug}/lessons/{lesson}/attachments/{attachment}', [LessonAttachmentController::class, 'show'])->scopeBindings();

        Route::get('lesson-videos/{lessonVideo}/stream', [LessonVideoStreamController::class, 'show'])
            ->name('lesson-videos.stream')
            ->middleware('signed');
    });

    Route::middleware('auth:tenant')->group(function () {
        Route::post('logout', [TenantLogoutController::class, 'store']);

        Route::middleware(['active.tenant.user', 'device.limit'])->group(function () {
            Route::get('auth/change-password', [ChangePasswordController::class, 'show']);
            Route::post('auth/change-password', [ChangePasswordController::class, 'update']);

            // Proof-of-payment screenshots. Signed (short-lived, tamper-evident) and
            // additionally authorised per viewer inside the controller — a valid
            // signature over somebody else's payment still 403s. Kept out of the
            // must.change.password group below so an <img> in the review screen can
            // never be redirected to a password form instead.
            Route::get('payments/{payment}/screenshot', [PaymentScreenshotController::class, 'show'])
                ->middleware('signed')
                ->name('payments.screenshot');

            Route::middleware('must.change.password')->group(function () {
                Route::get('dashboard', [DashboardController::class, 'show']);

                // Manual UPI payment (Phase 10) — student-facing, on the same
                // middleware stack as /dashboard, and always scoped to the caller's
                // own enrolment (somebody else's is a 404, never a 403).
                Route::get('enrolments/{enrolment}/payment', [PaymentController::class, 'show']);
                Route::post('enrolments/{enrolment}/payment', [PaymentController::class, 'store']);

                // Lesson progress — heartbeat/completion. Deliberately NOT nested under
                // /manage: these are student-facing endpoints, gated by the same
                // auth:tenant -> active.tenant.user -> must.change.password stack as
                // /dashboard, so a guest gets the normal redirect/401 the middleware
                // already produces rather than a bespoke check in the controller.
                Route::post('lessons/{lesson}/progress', [LessonProgressController::class, 'heartbeat']);
                Route::put('lessons/{lesson}/completion', [LessonProgressController::class, 'completion']);

                // Live classes (Phase 12) — student-facing, on the same stack as
                // /dashboard. {liveClass} resolves through the TenantScope global
                // scope, so another tenant's class id is a 404; authorisation
                // (enrolment/staff) happens per-action in LiveClassPolicy.
                Route::get('live-classes/{liveClass}', [LiveClassController::class, 'show']);
                Route::get('live-classes/{liveClass}/join', [LiveClassController::class, 'join']);
                Route::post('live-classes/{liveClass}/heartbeat', [LiveClassController::class, 'heartbeat']);

                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/create', [UserController::class, 'create']);
                Route::post('users', [UserController::class, 'store']);

                // Registered before users/{user} — "import" must never be swallowed by
                // the {user} wildcard (which would otherwise attempt to route-model-bind
                // a User with id/route-key "import").
                Route::get('users/import/template', [UserImportController::class, 'template']);
                Route::get('users/import', [UserImportController::class, 'create']);
                Route::post('users/import', [UserImportController::class, 'store'])->middleware('throttle:10,60');
                Route::post('users/import/confirm', [UserImportController::class, 'confirm']);
                Route::get('users/import/sheet/{token}', [UserImportCredentialsController::class, 'show']);
                Route::get('users/import/sheet/{token}/download', [UserImportCredentialsController::class, 'download']);
                Route::post('users/import/sheet/{token}/clear', [UserImportCredentialsController::class, 'clear']);

                Route::get('users/{user}', [UserController::class, 'show']);
                Route::patch('users/{user}', [UserController::class, 'update']);
                Route::post('users/{user}/disable', [UserController::class, 'disable']);
                Route::post('users/{user}/enable', [UserController::class, 'enable']);
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
                Route::get('users/{user}/devices', [ManageUserDeviceController::class, 'index']);
                Route::post('users/{user}/devices/{device}/sign-out', [ManageUserDeviceController::class, 'signOutOne'])->scopeBindings();
                Route::post('users/{user}/devices/sign-out-all', [ManageUserDeviceController::class, 'signOutAll']);

                Route::prefix('manage')->group(function () {
                    Route::post('getting-started/dismiss', [DashboardController::class, 'dismissGettingStarted']);

                    Route::get('help', [ManageHelpController::class, 'show']);

                    Route::get('settings', [ManageSettingsController::class, 'show']);
                    Route::patch('settings', [ManageSettingsController::class, 'update']);
                    Route::post('settings/logo', [ManageSettingsController::class, 'storeLogo']);
                    Route::delete('settings/logo', [ManageSettingsController::class, 'destroyLogo']);

                    // Manual UPI payment review (Phase 10) — queued oldest-first at 25
                    // a page; approve/reject are owner-only via PaymentPolicy.
                    Route::get('payments', [ManagePaymentController::class, 'index']);
                    Route::get('payments/{payment}', [ManagePaymentController::class, 'show']);
                    Route::post('payments/{payment}/approve', [ManagePaymentController::class, 'approve']);
                    Route::post('payments/{payment}/reject', [ManagePaymentController::class, 'reject']);

                    Route::get('courses', [ManageCourseController::class, 'index']);
                    Route::get('courses/create', [ManageCourseController::class, 'create']);
                    Route::post('courses', [ManageCourseController::class, 'store']);
                    Route::get('courses/{course}', [ManageCourseController::class, 'edit']);
                    Route::patch('courses/{course}', [ManageCourseController::class, 'update']);
                    Route::post('courses/{course}/publish', [ManageCourseController::class, 'publish']);
                    Route::post('courses/{course}/unpublish', [ManageCourseController::class, 'unpublish']);
                    Route::post('courses/{course}/archive', [ManageCourseController::class, 'archive']);

                    Route::post('courses/{course}/chapters', [ManageChapterController::class, 'store']);
                    Route::post('chapters/{chapter}/move-up', [ManageChapterController::class, 'moveUp']);
                    Route::post('chapters/{chapter}/move-down', [ManageChapterController::class, 'moveDown']);
                    Route::delete('chapters/{chapter}', [ManageChapterController::class, 'destroy']);

                    Route::post('chapters/{chapter}/lessons', [ManageLessonController::class, 'store']);
                    Route::get('lessons/{lesson}/edit', [ManageLessonController::class, 'edit']);
                    Route::patch('lessons/{lesson}', [ManageLessonController::class, 'update']);
                    Route::post('lessons/{lesson}/publish', [ManageLessonController::class, 'publish']);
                    Route::post('lessons/{lesson}/unpublish', [ManageLessonController::class, 'unpublish']);
                    Route::post('lessons/{lesson}/move-up', [ManageLessonController::class, 'moveUp']);
                    Route::post('lessons/{lesson}/move-down', [ManageLessonController::class, 'moveDown']);
                    Route::delete('lessons/{lesson}', [ManageLessonController::class, 'destroy']);

                    Route::post('lessons/{lesson}/attachments', [ManageLessonAttachmentController::class, 'store']);
                    Route::delete('attachments/{attachment}', [ManageLessonAttachmentController::class, 'destroy']);

                    Route::post('lessons/{lesson}/video/start-upload', [ManageLessonVideoController::class, 'startUpload'])
                        ->middleware('throttle:10,60');
                    Route::post('lessons/{lesson}/video/refresh-status', [ManageLessonVideoController::class, 'refreshStatus']);
                    Route::delete('lessons/{lesson}/video', [ManageLessonVideoController::class, 'destroy']);
                    Route::post('lesson-videos/{lessonVideo}/upload', [LessonVideoUploadController::class, 'store'])
                        ->name('lesson-videos.fake-upload')
                        ->middleware(['signed', 'throttle:10,60']);

                    Route::get('courses/{course}/enrolments', [ManageEnrolmentController::class, 'index']);
                    Route::post('courses/{course}/enrolments', [ManageEnrolmentController::class, 'store']);
                    Route::post('enrolments/{enrolment}/revoke', [ManageEnrolmentController::class, 'revoke']);
                    Route::post('enrolments/{enrolment}/reenrol', [ManageEnrolmentController::class, 'reenrol']);

                    Route::get('courses/{course}/progress', [ManageCourseProgressController::class, 'index']);
                    Route::get('courses/{course}/progress/export', [ManageCourseProgressController::class, 'export']);
                    Route::get('courses/{course}/progress/{user}', [ManageCourseProgressController::class, 'show']);

                    // Live classes (Phase 12) — every teacher surface scoped
                    // through {course}: scopeBindings() resolves {liveClass}
                    // via the course relation, so a class id from another
                    // course (or tenant) is a 404, never a 403, and the
                    // feature flag 404s the whole group when it is off.
                    Route::prefix('courses/{course}')->scopeBindings()->group(function () {
                        Route::get('live-classes', [ManageLiveClassController::class, 'index']);
                        Route::get('live-classes/create', [ManageLiveClassController::class, 'create']);
                        Route::post('live-classes', [ManageLiveClassController::class, 'store']);
                        Route::get('live-classes/{liveClass}/attendance', [ManageLiveClassAttendanceController::class, 'index']);
                        Route::get('live-classes/{liveClass}/attendance/export', [ManageLiveClassAttendanceController::class, 'export']);
                        Route::get('live-classes/{liveClass}/edit', [ManageLiveClassController::class, 'edit']);
                        Route::patch('live-classes/{liveClass}', [ManageLiveClassController::class, 'update']);
                        Route::post('live-classes/{liveClass}/cancel', [ManageLiveClassController::class, 'cancel']);
                        Route::delete('live-classes/{liveClass}', [ManageLiveClassController::class, 'destroy']);
                    });
                });
            });
        });
    });
});
