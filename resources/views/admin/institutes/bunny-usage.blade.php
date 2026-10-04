@extends('layouts.app')

@section('content')
    @php
        $formatBytes = function (?int $bytes): string {
            if ($bytes === null) {
                return __('platform.admin.bunny_usage.na');
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $index = 0;
            $value = (float) $bytes;

            while ($value >= 1024 && $index < count($units) - 1) {
                $value /= 1024;
                $index++;
            }

            return round($value, 1).' '.$units[$index];
        };
    @endphp

    <x-page-header :title="__('platform.admin.bunny_usage.heading', ['name' => $tenant->name])">
        <x-slot:actions>
            <a href="{{ url('/admin/institutes/'.$tenant->id) }}" class="ui-btn ui-btn-secondary">{{ __('platform.admin.bunny_usage.back') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (! $usage['ok'])
        <div class="mb-6 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
            @if ($usage['error'] === 'missing_key')
                {{ __('platform.admin.bunny_usage.error_missing_key') }}
            @elseif ($usage['error'] === 'unreachable')
                {{ __('platform.admin.bunny_usage.error_unreachable') }}
            @elseif ($usage['error'] !== null && str_starts_with($usage['error'], 'http_'))
                {{ __('platform.admin.bunny_usage.error_http', ['code' => substr($usage['error'], 5)]) }}
            @else
                {{ __('platform.admin.bunny_usage.error_unreachable') }}
            @endif
        </div>
    @elseif ($usage['error'] === 'unprovisioned')
        <div class="mb-6 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            {{ __('platform.admin.bunny_usage.unprovisioned') }}
        </div>
    @endif

    <dl class="mb-6 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.library_id') }}:</dt>
            <dd class="inline font-mono">{{ $tenant->bunny_library_id ?? '—' }}</dd>
        </div>
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.videos') }}:</dt>
            <dd class="inline">{{ $usage['video_count'] ?? __('platform.admin.bunny_usage.na') }}</dd>
        </div>
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.storage') }}:</dt>
            <dd class="inline">{{ $formatBytes($usage['storage_bytes']) }}</dd>
        </div>
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.traffic') }}:</dt>
            <dd class="inline">{{ $formatBytes($usage['traffic_bytes']) }}</dd>
        </div>
    </dl>

    @if ($usage['ok'] && $usage['error'] !== 'unprovisioned')
        <p class="mb-6 text-xs text-gray-500">{{ __('platform.admin.bunny_usage.live_note') }}</p>
    @endif

    <h2 class="mb-2 text-lg font-semibold">{{ __('platform.admin.bunny_usage.local_title') }}</h2>
    <dl class="mb-6 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.local_videos') }}:</dt>
            <dd class="inline">{{ $usage['local_videos'] }}</dd>
        </div>
        <div>
            <dt class="inline font-medium">{{ __('platform.admin.bunny_usage.local_bytes') }}:</dt>
            <dd class="inline">{{ $formatBytes($usage['local_bytes']) }}</dd>
        </div>
    </dl>
@endsection
