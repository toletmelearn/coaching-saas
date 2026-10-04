@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.system.heading')" :subtitle="__('platform.admin.system.intro')">
        <x-slot:actions>
            <a href="{{ url('/admin/settings/services') }}" class="ui-btn ui-btn-secondary">{{ __('platform.admin.system.services_link') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ session('status') }}
            @if (session('changed'))
                <p class="mt-1">{{ __('platform.admin.system.changed', ['keys' => implode(', ', session('changed'))]) }}</p>
            @endif
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="mb-4 text-sm text-gray-600">{{ __('platform.admin.system.blank_kept') }} {{ __('platform.admin.system.apply_note') }}</p>

    <form method="POST" action="{{ url('/admin/settings/system') }}" class="mb-8 max-w-2xl">
        @csrf

        <div class="divide-y divide-gray-100 rounded-md border border-gray-200">
            @foreach ($allowed as $key)
                @php
                    $isSecret = $key === 'MAIL_PASSWORD';
                    $current = $values->get($key);
                @endphp
                <div class="flex flex-wrap items-center gap-3 p-3">
                    <label for="env_{{ $key }}" class="w-52 font-mono text-sm font-medium">{{ $key }}</label>
                    <input
                        type="{{ $isSecret ? 'password' : 'text' }}"
                        id="env_{{ $key }}"
                        name="values[{{ $key }}]"
                        value="{{ old('values.'.$key, $isSecret ? '' : $current) }}"
                        autocomplete="{{ $isSecret ? 'new-password' : 'off' }}"
                        class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2"
                        placeholder="{{ $isSecret && $current !== null ? str_repeat('•', 12) : '' }}"
                    >
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-button type="submit">{{ __('platform.admin.system.save') }}</x-button>
        </div>
    </form>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ url('/admin/settings/system/clear-cache') }}">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('platform.admin.system.clear_cache') }}</x-button>
        </form>
        <form method="POST" action="{{ url('/admin/settings/system/optimize') }}">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('platform.admin.system.optimize') }}</x-button>
        </form>
    </div>
@endsection
