<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Coaching SaaS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php
        $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null;
    @endphp
    @if ($tenant)
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="{{ $tenant->theme_color }}">
        <link rel="apple-touch-icon" href="/pwa/icons/180.png">
        @vite('resources/js/pwa.js')
    @endif
</head>
<body class="min-w-[360px] bg-gray-50 text-gray-900 font-sans">
@php
    $tenantUser = auth('tenant')->user();
    $platformAdmin = auth('platform_admin')->user();
@endphp

@if ($tenant)
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="flex items-center gap-2 font-semibold text-gray-900">
                @if ($tenant->logo_path)
                    <img src="/branding/logo?v={{ $tenant->branding_version }}" alt="" class="h-8 w-8 rounded object-contain">
                @endif
                {{ $tenant->name }}
            </span>

            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                @if ($tenantUser)
                    @if ($tenantUser->role->canManageUsers())
                        <a href="{{ url('/dashboard') }}" class="min-h-[44px] flex items-center">{{ __('nav.dashboard') }}</a>
                        <a href="{{ url('/manage/courses') }}" class="min-h-[44px] flex items-center">{{ __('nav.courses') }}</a>
                        <a href="{{ url('/users') }}" class="min-h-[44px] flex items-center">{{ __('nav.people') }}</a>
                    @else
                        <a href="{{ url('/dashboard') }}" class="min-h-[44px] flex items-center">{{ __('nav.my_courses') }}</a>
                    @endif

                    @if ($tenantUser->role->isOwner())
                        <a href="{{ url('/manage/settings') }}" class="min-h-[44px] flex items-center">{{ __('nav.settings') }}</a>
                    @endif

                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="min-h-[44px] text-left">{{ __('nav.logout') }}</button>
                    </form>
                @else
                    <a href="{{ url('/courses') }}" class="min-h-[44px] flex items-center">{{ __('nav.courses') }}</a>
                    <a href="{{ url('/login') }}" class="min-h-[44px] flex items-center">{{ __('nav.login') }}</a>
                @endif

                <button type="button" id="pwa-install-button" hidden class="min-h-[44px] px-3 rounded-md bg-indigo-600 text-white">
                    {{ __('settings.pwa.install') }}
                </button>
            </nav>
        </div>
    </header>

    <div id="pwa-ios-hint" hidden class="bg-indigo-50 border-b border-indigo-100 text-sm text-indigo-900">
        <div class="max-w-3xl mx-auto px-4 py-2 flex items-center justify-between gap-2">
            <span>{{ __('settings.pwa.ios_hint') }}</span>
            <button type="button" id="pwa-ios-hint-dismiss" class="min-h-[44px] px-2 font-medium">{{ __('settings.pwa.dismiss') }}</button>
        </div>
    </div>
@elseif ($platformAdmin)
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold text-gray-900">{{ config('app.name', 'Coaching SaaS') }}</span>

            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <a href="{{ url('/admin/dashboard') }}" class="min-h-[44px] flex items-center">{{ __('platform.admin.nav.dashboard') }}</a>
                <a href="{{ url('/admin/institutes') }}" class="min-h-[44px] flex items-center">{{ __('platform.admin.nav.institutes') }}</a>
                <a href="{{ url('/admin/demo-requests') }}" class="min-h-[44px] flex items-center">{{ __('platform.admin.nav.demo_requests') }}</a>

                <form method="POST" action="{{ url('/admin/logout') }}">
                    @csrf
                    <button type="submit" class="min-h-[44px] text-left">{{ __('platform.admin.nav.logout') }}</button>
                </form>
            </nav>
        </div>
    </header>
@else
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold text-gray-900">{{ config('app.name', 'Coaching SaaS') }}</span>

            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <a href="{{ url('/admin/login') }}" class="min-h-[44px] flex items-center">{{ __('platform.admin_login_link') }}</a>
            </nav>
        </div>
    </header>
@endif

<main class="max-w-3xl mx-auto px-4 py-6">
    @yield('content')
</main>
</body>
</html>
