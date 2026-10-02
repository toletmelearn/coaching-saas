@extends('layouts.app')

@section('content')
    @php
        $tenant = app(\App\Support\TenantContext::class)->has() ? app(\App\Support\TenantContext::class)->get() : null;
    @endphp

    {{-- Deliberately narrow: one column, one decision, nothing competing with it.
         The institute name lives in the header only — StudentFacingPolishTest asserts
         it appears exactly once on this page, never repeated in the body. --}}
    <div class="ui-rise" style="width: 100%; max-width: 26.5rem; margin-inline: auto;">
        <div class="ui-card" style="padding: 1.5rem 1.25rem 1.75rem;">
            <x-page-header :title="__('auth.login.submit')" />

            @if (session('status'))
                <x-alert tone="warning">{{ session('status') }}</x-alert>
            @endif

            @if ($errors->any())
                <x-alert tone="danger">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </x-alert>
            @endif

            <form method="POST" action="{{ url('/login') }}">
                @csrf

                <x-field name="identifier" :label="__('auth.login.identifier')" required />
                <x-field name="password" type="password" :label="__('auth.login.password')" required />
                <x-checkbox name="remember" :label="__('auth.login.remember')" />

                <x-button class="w-full">{{ __('auth.login.submit') }}</x-button>
            </form>

            <div class="ui-inset" style="padding: 0.875rem 1rem; margin-top: 1.25rem;">
                <p class="ui-subtle" style="margin: 0;">{{ __('auth.help.generic') }}</p>
                @if ($tenant?->contact_phone)
                    <p style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.75rem; font-size: 0.875rem;">
                        <a href="tel:{{ $tenant->contact_phone }}" class="ui-link">{{ __('auth.help.contact', ['phone' => $tenant->contact_phone]) }}</a>
                        <a href="https://wa.me/91{{ $tenant->contact_phone }}" class="ui-link">{{ __('auth.help.whatsapp') }}</a>
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection
