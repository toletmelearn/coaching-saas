<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\PlatformAdminLoginController;
use App\Http\Controllers\Auth\PlatformAdminLogoutController;
use App\Http\Controllers\Auth\TenantLoginController;
use App\Http\Controllers\Auth\TenantLogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlatformAdminDashboardController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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

// Fallback POST /admin/login with no domain restriction: a tenant-subdomain attempt
// must still get a real response (session validation error), not a bare 404 — the
// domain-restricted registration above already wins on a genuine central domain, so
// this only ever matches when the host isn't a central domain.
Route::post('admin/login', [PlatformAdminLoginController::class, 'store']);

// Tenant-scoped routes.
Route::middleware('require.tenant')->group(function () {
    Route::get('login', [TenantLoginController::class, 'show'])->name('login');
    Route::post('login', [TenantLoginController::class, 'store']);

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
            });
        });
    });
});
