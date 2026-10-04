@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.services.heading')" :subtitle="__('platform.admin.services.intro')">
        <x-slot:actions>
            <a href="{{ url('/admin/settings/system') }}" class="ui-btn ui-btn-secondary">{{ __('platform.admin.services.system_link') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @foreach ([
        'bunny_test' => ['ok' => 'bunny_ok', 'fail' => 'bunny_fail', 'missing' => 'bunny_missing'],
        'jitsi_test' => ['ok' => 'jitsi_ok', 'fail' => 'jitsi_fail', 'missing' => 'jitsi_missing'],
    ] as $flashKey => $messages)
        @if (session($flashKey))
            <div @class([
                'mb-6 rounded-md border p-3 text-sm',
                'border-green-300 bg-green-50 text-green-800' => session($flashKey) === 'ok',
                'border-red-300 bg-red-50 text-red-700' => session($flashKey) === 'fail',
                'border-amber-300 bg-amber-50 text-amber-800' => session($flashKey) === 'missing',
            ])>
                {{ __('platform.admin.services.'.$messages[session($flashKey)]) }}
            </div>
        @endif
    @endforeach

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/settings/services') }}" class="mb-8 max-w-xl">
        @csrf

        <div class="mb-5">
            <label for="bunny_account_api_key" class="block font-medium">{{ __('platform.admin.services.bunny_key') }}</label>
            <p class="mb-1 text-sm text-gray-600">{{ __('platform.admin.services.bunny_help') }}</p>
            <input
                type="password"
                id="bunny_account_api_key"
                name="bunny_account_api_key"
                value="{{ old('bunny_account_api_key') }}"
                autocomplete="new-password"
                class="w-full rounded-md border border-gray-300 px-3 py-2"
                placeholder="{{ $configured['bunny_account_api_key'] ? str_repeat('•', 12) : '' }}"
            >
            <p class="mt-1 text-xs text-gray-500">
                {{ __('platform.admin.services.blank_kept') }}
                — {{ $configured['bunny_account_api_key'] ? __('platform.admin.services.configured') : __('platform.admin.services.not_configured') }}
            </p>
        </div>

        <div class="mb-5">
            <label for="jitsi_app_id" class="block font-medium">{{ __('platform.admin.services.jitsi_app_id') }}</label>
            <p class="mb-1 text-sm text-gray-600">{{ __('platform.admin.services.jitsi_help') }}</p>
            <input
                type="text"
                id="jitsi_app_id"
                name="jitsi_app_id"
                value="{{ old('jitsi_app_id', $appId) }}"
                autocomplete="off"
                class="w-full rounded-md border border-gray-300 px-3 py-2"
            >
            <p class="mt-1 text-xs text-gray-500">{{ $configured['jitsi_app_id'] ? __('platform.admin.services.configured') : __('platform.admin.services.not_configured') }}</p>
        </div>

        <div class="mb-5">
            <label for="jitsi_app_secret" class="block font-medium">{{ __('platform.admin.services.jitsi_app_secret') }}</label>
            <input
                type="password"
                id="jitsi_app_secret"
                name="jitsi_app_secret"
                value="{{ old('jitsi_app_secret') }}"
                autocomplete="new-password"
                class="w-full rounded-md border border-gray-300 px-3 py-2"
                placeholder="{{ $configured['jitsi_app_secret'] ? str_repeat('•', 12) : '' }}"
            >
            <p class="mt-1 text-xs text-gray-500">
                {{ __('platform.admin.services.blank_kept') }}
                — {{ $configured['jitsi_app_secret'] ? __('platform.admin.services.configured') : __('platform.admin.services.not_configured') }}
            </p>
        </div>

        <x-button type="submit">{{ __('platform.admin.services.submit') }}</x-button>
    </form>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ url('/admin/settings/services/test-bunny') }}">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('platform.admin.services.test_bunny') }}</x-button>
        </form>
        <form method="POST" action="{{ url('/admin/settings/services/test-jitsi') }}">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('platform.admin.services.test_jitsi') }}</x-button>
        </form>
    </div>
@endsection
