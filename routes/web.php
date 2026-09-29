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
use App\Http\Controllers\LessonVideoStreamController;
use App\Http\Controllers\LessonVideoUploadController;
use App\Http\Controllers\Manage\ChapterController as ManageChapterController;
use App\Http\Controllers\Manage\CourseController as ManageCourseController;
use App\Http\Controllers\Manage\EnrolmentController as ManageEnrolmentController;
use App\Http\Controllers\Manage\LessonAttachmentController as ManageLessonAttachmentController;
use App\Http\Controllers\Manage\LessonController as ManageLessonController;
use App\Http\Controllers\Manage\LessonVideoController as ManageLessonVideoController;
use App\Http\Controllers\PlatformAdminDashboardController;
use App\Http\Controllers\PlatformHomeController;
use App\Http\Controllers\UserController;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

// Central-domain-only routes — restricted via Route::domain(), so a tenant subdomain
// never matches any of these at all (routing-level, not just an in-controller check).
foreach (config('tenancy.central_domains', []) as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::get('/', [PlatformHomeController::class, 'show']);
        Route::post('demo-requests', [DemoRequestController::class, 'store']);

        Route::prefix('admin')->group(function () {
            Route::get('login', [PlatformAdminLoginController::class, 'show'])->name('admin.login');
            Route::post('login', [PlatformAdminLoginController::class, 'store']);
            Route::post('logout', [PlatformAdminLogoutController::class, 'store'])
                ->middleware('auth:platform_admin');

            Route::middleware('auth:platform_admin')->group(function () {
                Route::get('dashboard', [PlatformAdminDashboardController::class, 'show']);

                Route::get('institutes', [InstituteController::class, 'index'])->name('admin.institutes.index');
                Route::get('institutes/create', [InstituteController::class, 'create'])->name('admin.institutes.create');
                Route::post('institutes', [InstituteController::class, 'store']);
                Route::get('institutes/{tenant}', [InstituteController::class, 'show'])->name('admin.institutes.show');
                Route::post('institutes/{tenant}/suspend', [InstituteController::class, 'suspend']);
                Route::post('institutes/{tenant}/reactivate', [InstituteController::class, 'reactivate']);
                Route::post('institutes/{tenant}/reset-owner-password', [InstituteController::class, 'resetOwnerPassword']);

                Route::get('demo-requests', [AdminDemoRequestController::class, 'index'])->name('admin.demo-requests.index');
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
    Route::get('login', [TenantLoginController::class, 'show'])->name('login');
    Route::post('login', [TenantLoginController::class, 'store']);

    // Public catalogue and free-preview lessons — no login required. Both middleware are
    // no-ops for guests (they check for a resolved tenant user first), so a guest still
    // passes through untouched; a logged-in student who is disabled or must change their
    // password gets the same redirect here as on every other tenant route (Phase 4.1 —
    // this group previously sat outside auth:tenant entirely, so a disabled/must-change
    // student's session was never re-checked on these specific routes).
    Route::middleware(['active.tenant.user', 'must.change.password'])->group(function () {
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

        Route::middleware('active.tenant.user')->group(function () {
            Route::get('auth/change-password', [ChangePasswordController::class, 'show']);
            Route::post('auth/change-password', [ChangePasswordController::class, 'update']);

            Route::middleware('must.change.password')->group(function () {
                Route::get('dashboard', [DashboardController::class, 'show']);

                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/create', [UserController::class, 'create']);
                Route::post('users', [UserController::class, 'store']);
                Route::get('users/{user}', [UserController::class, 'show']);
                Route::patch('users/{user}', [UserController::class, 'update']);
                Route::post('users/{user}/disable', [UserController::class, 'disable']);
                Route::post('users/{user}/enable', [UserController::class, 'enable']);
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

                Route::prefix('manage')->group(function () {
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

                    Route::post('lessons/{lesson}/video/start-upload', [ManageLessonVideoController::class, 'startUpload']);
                    Route::post('lessons/{lesson}/video/refresh-status', [ManageLessonVideoController::class, 'refreshStatus']);
                    Route::delete('lessons/{lesson}/video', [ManageLessonVideoController::class, 'destroy']);
                    Route::post('lesson-videos/{lessonVideo}/upload', [LessonVideoUploadController::class, 'store'])
                        ->name('lesson-videos.fake-upload')
                        ->middleware('signed');

                    Route::get('courses/{course}/enrolments', [ManageEnrolmentController::class, 'index']);
                    Route::post('courses/{course}/enrolments', [ManageEnrolmentController::class, 'store']);
                    Route::post('enrolments/{enrolment}/revoke', [ManageEnrolmentController::class, 'revoke']);
                    Route::post('enrolments/{enrolment}/reenrol', [ManageEnrolmentController::class, 'reenrol']);
                });
            });
        });
    });
});
