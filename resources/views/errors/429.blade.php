@extends('layouts.app')

@section('content')
    <h1>{{ __('errors.429.heading') }}</h1>
    <p>{{ __('errors.429.message') }}</p>

    @auth('tenant')
        <p><a href="{{ url('/dashboard') }}">{{ __('errors.back_to_dashboard') }}</a></p>
    @else
        <p><a href="{{ url('/login') }}">{{ __('errors.back_to_login') }}</a></p>
    @endauth
@endsection
