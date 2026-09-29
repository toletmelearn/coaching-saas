<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Coaching SaaS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-w-[360px] bg-gray-50 text-gray-900 font-sans">
@php
    $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null;
    $tenantUser = auth('tenant')->user();
@endphp

@if ($tenant)
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold text-gray-900">{{ $tenant->name }}</span>

            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                @if ($tenantUser)
                    @if ($tenantUser->role->canManageUsers())
                        <a href="{{ url('/dashboard') }}" class="min-h-[44px] flex items-center">{{ __('nav.dashboard') }}</a>
                        <a href="{{ url('/manage/courses') }}" class="min-h-[44px] flex items-center">{{ __('nav.courses') }}</a>
                        <a href="{{ url('/users') }}" class="min-h-[44px] flex items-center">{{ __('nav.people') }}</a>
                    @else
                        <a href="{{ url('/dashboard') }}" class="min-h-[44px] flex items-center">{{ __('nav.my_courses') }}</a>
                    @endif

                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="min-h-[44px] text-left">{{ __('nav.logout') }}</button>
                    </form>
                @else
                    <a href="{{ url('/courses') }}" class="min-h-[44px] flex items-center">{{ __('nav.courses') }}</a>
                    <a href="{{ url('/login') }}" class="min-h-[44px] flex items-center">{{ __('nav.login') }}</a>
                @endif
            </nav>
        </div>
    </header>
@endif

<main class="max-w-3xl mx-auto px-4 py-6">
    @yield('content')
</main>
</body>
</html>
