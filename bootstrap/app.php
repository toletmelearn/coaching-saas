<?php

use App\Http\Middleware\CheckTenantSuspended;
use App\Http\Middleware\EnforceDeviceLimit;
use App\Http\Middleware\EnsureActiveTenantUser;
use App\Http\Middleware\RedirectIfMustChangePassword;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetReferrerPolicy;
use App\Http\Middleware\ShowDeviceRevokedNotice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            ResolveTenant::class,
        ]);

        $middleware->web(append: [
            CheckTenantSuspended::class,
            SetReferrerPolicy::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'require.tenant' => RequireTenant::class,
            'active.tenant.user' => EnsureActiveTenantUser::class,
            'must.change.password' => RedirectIfMustChangePassword::class,
            'device.limit' => EnforceDeviceLimit::class,
            'device.revoked.notice' => ShowDeviceRevokedNotice::class,
        ]);

        // Laravel's default middleware priority list runs SubstituteBindings (implicit
        // route-model binding) before any route-specific middleware that isn't itself
        // in the priority list — including require.tenant. Left unfixed, a request to
        // any require.tenant route with a tenant-scoped bound Eloquent parameter (e.g.
        // Lesson, Course) hits TenantScope with no tenant context on the central
        // domain, throwing an uncaught MissingTenantContextException (500) instead of
        // require.tenant's intended 404. Pinning require.tenant immediately before
        // SubstituteBindings in the priority list closes that gap at the root: no
        // route-model binding for a tenant-scoped model can ever run before the tenant
        // is confirmed to exist. See tests/Feature/Tenancy/CentralDomainRouteBindingTest.php.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireTenant::class,
        );

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin/*') ? '/admin/login' : '/login',
        );

        // Only trust X-Forwarded-For / X-Forwarded-Proto when the immediate TCP connection
        // (REMOTE_ADDR) is Cloudflare itself — config('cloudflare.ip_ranges') isn't used
        // here because config() isn't guaranteed to be booted this early; a direct
        // require keeps this the single source of truth (see config/cloudflare.php for
        // how to refresh the list). A request from any other IP has its forwarded headers
        // ignored entirely, so $request->ip() falls back to REMOTE_ADDR and a forged
        // header can never spoof or share another visitor's rate-limit bucket.
        //
        // Deliberately NOT trusting HEADER_X_FORWARDED_HOST or HEADER_X_FORWARDED_PORT:
        // Cloudflare always forwards the real Host header itself, and tenant resolution is
        // host-based (ResolveTenant uses $request->getHost()) — honouring a forwarded host
        // would let a forged X-Forwarded-Host resolve the wrong tenant. See SECURITY.md.
        $middleware->trustProxies(
            at: (require __DIR__.'/../config/cloudflare.php')['ip_ranges'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
