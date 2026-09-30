@extends('layouts.app')

@section('content')
    @php $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null; @endphp
    @if ($tenant?->logo_path)
        <img src="/branding/logo?v={{ $tenant->branding_version }}" alt="" class="h-16 w-16 mb-4 rounded object-contain">
    @endif

    <x-page-header :title="__('auth.login.submit')" />

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
@endsection
