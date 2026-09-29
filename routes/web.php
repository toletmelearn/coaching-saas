<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\PlatformAdminLoginController;
use App\Http\Controllers\Auth\PlatformAdminLogoutController;
use App\Http\Controllers\Auth\TenantLoginController;
use App\Http\Controllers\Auth\TenantLogoutController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonAttachmentController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\Manage\ChapterController as ManageChapterController;
use App\Http\Controllers\Manage\CourseController as ManageCourseController;
use App\Http\Controllers\Manage\EnrolmentController as ManageEnrolmentController;
use App\Http\Controllers\Manage\LessonAttachmentController as ManageLessonAttachmentController;
use App\Http\Controllers\Manage\LessonController as ManageLessonController;
use App\Http\Controllers\PlatformAdminDashboardController;
use App\Http\Controllers\UserController;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! app(TenantContext::class)->has()) {
        return view('platform.home');
    }

    return auth('tenant')->check() ? redirect('/dashboard') : redirect('/courses');
});

// Platform admin — restricted to each configured central domain via Route::domain(),
// so a tenant subdomain never matches these at all (routing-level defense-in-depth,
// not just the in-controller TenantContext check in PlatformAdminLoginController).
foreach (config('tenancy.central_domains', []) as $centralDomain) {
    Route::domain($centralDomain)->prefix('admin')->group(function () {
        Route::get('login', [PlatformAdminLoginController::class, 'show'])->name('admin.login');
        Route::post('login', [PlatformAdminLoginController::class, 'store']);
        Route::post('logout', [PlatformAdminLogoutController::class, 'store'])
            ->middleware('auth:platform_admin');
        Route::get('dashboard', [PlatformAdminDashboardController::class, 'show'])
            ->middleware('auth:platform_admin');
    });
}

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

                    Route::get('courses/{course}/enrolments', [ManageEnrolmentController::class, 'index']);
                    Route::post('courses/{course}/enrolments', [ManageEnrolmentController::class, 'store']);
                    Route::post('enrolments/{enrolment}/revoke', [ManageEnrolmentController::class, 'revoke']);
                    Route::post('enrolments/{enrolment}/reenrol', [ManageEnrolmentController::class, 'reenrol']);
                });
            });
        });
    });
});
