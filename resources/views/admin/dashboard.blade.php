@extends('layouts.app')

@section('content')
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
