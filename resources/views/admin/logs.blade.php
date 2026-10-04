@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.logs.heading')">
        <x-slot:actions>
            <form method="GET" action="{{ url('/admin/logs') }}" class="flex flex-wrap items-center gap-2">
                <label for="log-q" class="sr-only">{{ __('platform.admin.logs.search') }}</label>
                <input
                    type="search"
                    id="log-q"
                    name="q"
                    value="{{ $query }}"
                    placeholder="{{ __('platform.admin.logs.placeholder') }}"
                    class="rounded-md border border-gray-300 px-3 py-2"
                >
                <x-button type="submit" variant="secondary">{{ __('platform.admin.logs.apply') }}</x-button>
                <a href="{{ url('/admin/logs') }}" class="ui-btn ui-btn-ghost">{{ __('platform.admin.logs.clear') }}</a>
            </form>
        </x-slot:actions>
    </x-page-header>

    <p class="mb-3 text-sm text-gray-600">
        {{ __('platform.admin.logs.path') }}: <span class="font-mono">{{ $path }}</span>
        @if ($exists)
            — {{ __('platform.admin.logs.count', ['count' => count($lines)]) }}
        @endif
    </p>

    @if (! $exists)
        <p class="rounded-md border border-gray-200 p-4 text-sm text-gray-600">{{ __('platform.admin.logs.no_file') }}</p>
    @elseif (count($lines) === 0)
        <p class="rounded-md border border-gray-200 p-4 text-sm text-gray-600">{{ __('platform.admin.logs.empty') }}</p>
    @else
        {{-- Escaped as plain text inside <pre>: log lines often contain HTML-looking payloads. --}}
        <pre class="max-h-[70vh] overflow-auto rounded-md border border-gray-200 bg-gray-950 p-4 text-xs leading-relaxed text-gray-100">@foreach ($lines as $line)
{{ $line }}
@endforeach</pre>
    @endif
@endsection
