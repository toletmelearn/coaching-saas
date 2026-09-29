<?php

use App\Http\Middleware\EnsureActiveTenantUser;
use App\Http\Middleware\RedirectIfMustChangePassword;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetReferrerPolicy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            SetReferrerPolicy::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'require.tenant' => RequireTenant::class,
            'active.tenant.user' => EnsureActiveTenantUser::class,
            'must.change.password' => RedirectIfMustChangePassword::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin/*') ? '/admin/login' : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
