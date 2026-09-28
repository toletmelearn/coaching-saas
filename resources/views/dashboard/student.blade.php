@extends('layouts.app')

@section('content')
    <h1>{{ __('users.dashboard.welcome') }}, {{ $user->name }}</h1>

    <form method="POST" action="{{ url('/logout') }}">
        @csrf
        <button type="submit">{{ __('auth.login.logout') }}</button>
    </form>
@endsection
