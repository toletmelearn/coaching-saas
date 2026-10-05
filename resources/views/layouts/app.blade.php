<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('meta.description') }}">
    <title>{{ config('app.name', 'Coaching SaaS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php
        $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null;
    @endphp
    @if ($tenant)
        {{-- Per-tenant accent. Everything downstream (buttons, links, progress bars,
             focus rings, badges) reads --brand and derives --brand-ink/--brand-soft/
             --brand-line from it in resources/css/app.css, so one runtime override
             themes the whole product. Only --brand is written here on purpose: the
             derived names must never appear in the document, because
             CredentialsSheetTest extracts the temporary password from
             strip_tags(response) and strip_tags() keeps <style> contents — a selector
             such as `brand-line` would be matched before the real password. --}}
        <style>
            :root {
                --brand: {{ $tenant->theme_color }};
            }
        </style>
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="{{ $tenant->theme_color }}">
        <link rel="apple-touch-icon" href="/pwa/icons/180.png">
        @vite('resources/js/pwa.js')
    @endif
    @stack('scripts')
</head>
<body class="min-w-[360px] antialiased">
@php
    $tenantUser = auth('tenant')->user();
    $platformAdmin = auth('platform_admin')->user();
    $isOwner = (bool) ($tenantUser?->role->isOwner());
    $canTeach = (bool) ($tenantUser?->role->canManageUsers());

    $isCurrent = fn (string $path): string => request()->is(trim($path, '/').'*')
        || request()->is(trim($path, '/'))
        ? ' aria-current="page"'
        : '';
@endphp

<a href="#main-content" class="ui-skip">Skip to content</a>

@if ($tenant && $tenantUser && session('impersonation'))
    <div class="ui-alert" role="alert" style="border-radius: 0; justify-content: center;">
        <div class="ui-shell" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; width: 100%;">
            <strong>{{ __('impersonation.banner', ['name' => $tenantUser->name]) }}</strong>
            <form method="POST" action="{{ url('/impersonation/exit') }}">
                @csrf
                <button type="submit" class="ui-btn ui-btn-sm">{{ __('impersonation.exit') }}</button>
            </form>
        </div>
    </div>
@endif

<header class="ui-header">
    <div class="ui-shell ui-header-inner">
        @if ($tenant)
            <a href="{{ url('/dashboard') }}" class="ui-brand">
                @if ($tenant->logo_path)
                    <span class="ui-brand-mark"><img src="/branding/logo?v={{ $tenant->branding_version }}" alt=""></span>
                @else
                    <span class="ui-brand-mark" aria-hidden="true">{{ strtoupper(substr($tenant->name, 0, 1)) }}</span>
                @endif
                <span class="truncate max-w-[10rem] sm:max-w-[16rem]">{{ $tenant->name }}</span>
            </a>
        @else
            <a href="{{ url('/') }}" class="ui-brand">
                <span class="ui-brand-mark" aria-hidden="true">{{ strtoupper(substr(config('app.name', 'Coaching SaaS'), 0, 1)) }}</span>
                <span>{{ config('app.name', 'Coaching SaaS') }}</span>
            </a>
        @endif

        <nav class="ui-nav" aria-label="Primary">
            @if ($tenant)
                @if ($tenantUser)
                    @if ($canTeach)
                        <a href="{{ url('/dashboard') }}" class="ui-nav-link"{!! $isCurrent('/dashboard') !!}>
                            <x-icon name="home" :size="17" />{{ __('nav.dashboard') }}
                        </a>
                        <a href="{{ url('/manage/courses') }}" class="ui-nav-link"{!! $isCurrent('/manage/courses') !!}>
                            <x-icon name="book" :size="17" />{{ __('nav.courses') }}
                        </a>
                        <a href="{{ url('/users') }}" class="ui-nav-link"{!! $isCurrent('/users') !!}>
                            <x-icon name="users" :size="17" />{{ __('nav.people') }}
                        </a>
                        @if ((bool) config('coaching.live_classes_enabled'))
                            {{-- Phase 12.1: the tenant-wide list, linked only while
                                 the feature is on so the header can never point at
                                 a route that answers 404. --}}
                            <a href="{{ url('/manage/live-classes') }}" class="ui-nav-link"{!! $isCurrent('/manage/live-classes') !!}>
                                <x-icon name="play" :size="17" />{{ __('live_classes.nav_label') }}
                            </a>
                        @endif
                    @else
                        <a href="{{ url('/dashboard') }}" class="ui-nav-link"{!! $isCurrent('/dashboard') !!}>
                            <x-icon name="home" :size="17" />{{ __('nav.my_courses') }}
                        </a>
                        <a href="{{ url('/courses') }}" class="ui-nav-link"{!! $isCurrent('/courses') !!}>
                            <x-icon name="book" :size="17" />{{ __('nav.courses') }}
                        </a>
                    @endif

                    <span class="ui-nav-divider" aria-hidden="true"></span>

                    @if ($canTeach)
                        <a href="{{ url('/manage/help') }}" class="ui-nav-link"{!! $isCurrent('/manage/help') !!}>
                            <x-icon name="help" :size="17" />{{ __('help.nav_label') }}
                        </a>
                    @endif

                    @if ($isOwner)
                        <a href="{{ url('/manage/settings') }}" class="ui-nav-link"{!! $isCurrent('/manage/settings') !!}>
                            <x-icon name="settings" :size="17" />{{ __('nav.settings') }}
                        </a>
                    @endif

                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="ui-nav-link">
                            <x-icon name="logout" :size="17" />{{ __('nav.logout') }}
                        </button>
                    </form>
                @else
                    <a href="{{ url('/courses') }}" class="ui-nav-link"{!! $isCurrent('/courses') !!}>
                        <x-icon name="book" :size="17" />{{ __('nav.courses') }}
                    </a>
                    <a href="{{ url('/login') }}" class="ui-nav-link ui-nav-cta"{!! $isCurrent('/login') !!}>
                        {{ __('nav.login') }}
                    </a>
                @endif

                <button type="button" id="pwa-install-button" hidden class="ui-btn ui-btn-secondary ui-btn-sm">
                    {{ __('settings.pwa.install') }}
                </button>
            @elseif ($platformAdmin)
                <a href="{{ url('/admin/dashboard') }}" class="ui-nav-link"{!! $isCurrent('/admin/dashboard') !!}>
                    <x-icon name="chart" :size="17" />{{ __('platform.admin.nav.dashboard') }}
                </a>
                <a href="{{ url('/admin/institutes') }}" class="ui-nav-link"{!! $isCurrent('/admin/institutes') !!}>
                    <x-icon name="shield" :size="17" />{{ __('platform.admin.nav.institutes') }}
                </a>
                <a href="{{ url('/admin/demo-requests') }}" class="ui-nav-link"{!! $isCurrent('/admin/demo-requests') !!}>
                    <x-icon name="inbox" :size="17" />{{ __('platform.admin.nav.demo_requests') }}
                </a>

                <span class="ui-nav-divider" aria-hidden="true"></span>

                <a href="{{ url('/admin/health') }}" class="ui-nav-link"{!! $isCurrent('/admin/health') !!}>
                    <x-icon name="pulse" :size="17" />{{ __('platform.admin.nav.health') }}
                </a>
                <a href="{{ url('/admin/backups') }}" class="ui-nav-link"{!! $isCurrent('/admin/backups') !!}>
                    <x-icon name="archive" :size="17" />{{ __('platform.admin.nav.backups') }}
                </a>
                <a href="{{ url('/admin/logs') }}" class="ui-nav-link"{!! $isCurrent('/admin/logs') !!}>
                    <x-icon name="terminal" :size="17" />{{ __('platform.admin.nav.logs') }}
                </a>
                <a href="{{ url('/admin/audit') }}" class="ui-nav-link"{!! $isCurrent('/admin/audit') !!}>
                    <x-icon name="list" :size="17" />{{ __('platform.admin.nav.audit') }}
                </a>
                <a href="{{ url('/admin/settings/services') }}" class="ui-nav-link"{!! $isCurrent('/admin/settings') !!}>
                    <x-icon name="settings" :size="17" />{{ __('platform.admin.nav.settings') }}
                </a>

                <span class="ui-nav-divider" aria-hidden="true"></span>

                <form method="POST" action="{{ url('/admin/logout') }}">
                    @csrf
                    <button type="submit" class="ui-nav-link">
                        <x-icon name="logout" :size="17" />{{ __('platform.admin.nav.logout') }}
                    </button>
                </form>
            @else
                <a href="{{ url('/admin/login') }}" class="ui-nav-link">
                    {{ __('platform.admin_login_link') }}
                </a>
            @endif
        </nav>
    </div>
</header>

@if ($tenant)
    <div id="pwa-ios-hint" hidden class="ui-fade" style="background: var(--brand-soft); border-bottom: 1px solid var(--brand-line);">
        <div class="ui-shell" style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding-block: 0.5rem; font-size: 0.875rem; color: var(--brand-ink);">
            <span>{{ __('settings.pwa.ios_hint') }}</span>
            <button type="button" id="pwa-ios-hint-dismiss" class="ui-nav-link" style="font-weight: 650;">{{ __('settings.pwa.dismiss') }}</button>
        </div>
    </div>
@endif

<main id="main-content" class="ui-shell ui-main" tabindex="-1">
    @yield('content')
</main>

@if ($tenant || $platformAdmin)
    {{-- The signed-in product gets a quiet chrome footer; the public landing page carries
         its own marketing footer instead, so the two never stack. --}}
    <footer class="ui-shell" style="padding-bottom: 2.5rem;">
        {{-- `--ink-muted` rather than `--ink-subtle`: on the #f6f6f7 canvas the subtle
             step lands at 4.44:1, just under the 4.5:1 minimum (Lighthouse caught it).
             The subtle step is still fine inside white cards, which is where it is
             used everywhere else. --}}
        <div style="border-top: 1px solid var(--line); padding-top: 1.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; justify-content: space-between; font-size: 0.8125rem; color: var(--ink-muted);">
            <span>{{ config('app.name', 'Coaching SaaS') }}</span>
            <span>{{ now()->year }}</span>
        </div>
    </footer>
@endif
</body>
</html>
