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

    @if (session('bunny_test'))
        @php $bt = session('bunny_test'); @endphp
        <div @class([
            'mb-6 rounded-md border p-3 text-sm',
            'border-green-300 bg-green-50 text-green-800' => $bt === 'ok',
            'border-red-300 bg-red-50 text-red-700' => $bt === 'fail',
            'border-amber-300 bg-amber-50 text-amber-800' => $bt === 'missing',
        ])>
            {{ __('platform.admin.services.bunny_'.$bt) }}
        </div>
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

        <x-button type="submit">{{ __('platform.admin.services.submit') }}</x-button>
    </form>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ url('/admin/settings/services/test-bunny') }}">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('platform.admin.services.test_bunny') }}</x-button>
        </form>
    </div>
@endsection
