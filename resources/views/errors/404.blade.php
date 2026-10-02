@extends('layouts.app')

@section('content')
    <div class="ui-rise" style="max-width: 34rem;">
        <span class="ui-kicker">404</span>
        <h1 class="ui-h1">{{ __('errors.404.heading') }}</h1>
        <p class="ui-sub">{{ __('errors.404.message') }}</p>

        <div class="ui-card ui-empty" style="margin-top: 1.5rem;">
            <span class="ui-empty-icon"><x-icon name="arrow-right" :size="20" /></span>
            @auth('tenant')
                <x-link href="{{ url('/dashboard') }}" variant="primary">{{ __('errors.back_to_dashboard') }}</x-link>
            @else
                <x-link href="{{ url('/login') }}" variant="primary">{{ __('errors.back_to_login') }}</x-link>
            @endauth
        </div>
    </div>
@endsection
