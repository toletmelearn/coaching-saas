@extends('layouts.app')

@section('content')
    @php $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null; @endphp
    @if ($tenant?->logo_path)
        <img src="/branding/logo?v={{ $tenant->branding_version }}" alt="" class="h-16 w-16 mb-4 rounded object-contain">
    @endif

    <x-page-header :title="__('auth.login.submit')" />

    @if (session('status'))
        <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/login') }}">
        @csrf

        <x-field name="identifier" :label="__('auth.login.identifier')" required />
        <x-field name="password" type="password" :label="__('auth.login.password')" required />
        <x-checkbox name="remember" :label="__('auth.login.remember')" />

        <x-button>{{ __('auth.login.submit') }}</x-button>
    </form>

    <div class="mt-6 text-sm text-gray-600">
        <p>{{ __('auth.help.generic') }}</p>
        @if ($tenant?->contact_phone)
            <p class="mt-1">
                <a href="tel:{{ $tenant->contact_phone }}" class="text-indigo-600 underline">{{ __('auth.help.contact', ['phone' => $tenant->contact_phone]) }}</a>
                <a href="https://wa.me/91{{ $tenant->contact_phone }}" class="text-indigo-600 underline ml-2">{{ __('auth.help.whatsapp') }}</a>
            </p>
        @endif
    </div>
@endsection
