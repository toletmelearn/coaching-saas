@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.health.heading')" :subtitle="__('platform.admin.health.intro')">
        <x-slot:actions>
            <form method="GET" action="{{ url('/admin/health') }}">
                <x-button type="submit" variant="secondary">{{ __('platform.admin.health.rerun') }}</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @if ($fixable)
        <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{{ __('platform.admin.health.fix_note') }}</p>
    @endif

    <ul class="mb-6 divide-y divide-gray-100 rounded-md border border-gray-200">
        @foreach ($outcomes as $outcome)
            <li class="flex flex-wrap items-center justify-between gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <span class="font-medium {{ $outcome['passed'] ? 'text-gray-900' : 'text-red-800' }}">{{ $outcome['label'] }}</span>

                    @if (! $outcome['passed'])
                        <p class="mt-1 text-sm break-words text-red-700">{{ $outcome['message'] }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($outcome['passed'])
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-800">
                            <x-icon name="check" :size="13" />{{ __('platform.admin.health.pass') }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-800">{{ __('platform.admin.health.fail') }}</span>

                        @if (in_array($outcome['id'], ['video_driver', 'jitsi'], true))
                            <a href="{{ url('/admin/settings/services') }}" class="ui-btn ui-btn-ghost ui-btn-sm">{{ __('platform.admin.health.set_credentials') }}</a>
                        @endif

                        @if ($fixable && $outcome['fix'] !== null)
                            <form method="POST" action="{{ url('/admin/health/fix') }}">
                                @csrf
                                <input type="hidden" name="check" value="{{ $outcome['id'] }}">
                                <x-button type="submit" variant="secondary" size="sm">{{ __('platform.admin.health.fix') }}</x-button>
                            </form>
                        @endif
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endsection
