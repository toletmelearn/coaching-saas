@extends('layouts.app')

@section('content')
    @if (session('recovery_codes'))
        <div class="ui-alert" style="margin-bottom: 1.25rem;">
            <div>
                <p class="ui-h2" style="margin: 0 0 0.375rem;">{{ __('admin_2fa.recovery.heading') }}</p>
                <p style="margin: 0 0 0.5rem;">{{ __('admin_2fa.recovery.help') }}</p>
                <ul class="font-mono" style="list-style: none;">
                    @foreach (session('recovery_codes') as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
    <x-page-header :title="__('platform.admin.dashboard.heading')" />

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-600">{{ __('platform.admin.dashboard.active_institutes') }}</p>
            <p class="text-2xl font-bold">{{ $counts['active_institutes'] }}</p>
        </div>
        <div class="rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-600">{{ __('platform.admin.dashboard.suspended_institutes') }}</p>
            <p class="text-2xl font-bold">{{ $counts['suspended_institutes'] }}</p>
        </div>
        <div class="rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-600">{{ __('platform.admin.dashboard.total_students') }}</p>
            <p class="text-2xl font-bold">{{ $counts['total_students'] }}</p>
        </div>
        <div class="rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-600">{{ __('platform.admin.dashboard.total_courses') }}</p>
            <p class="text-2xl font-bold">{{ $counts['total_courses'] }}</p>
        </div>
        <div class="rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-600">{{ __('platform.admin.dashboard.new_demo_requests') }}</p>
            <p class="text-2xl font-bold">{{ $counts['new_demo_requests'] }}</p>
        </div>
    </div>
@endsection
