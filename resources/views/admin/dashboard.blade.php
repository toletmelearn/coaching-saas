@extends('layouts.app')

@section('content')
    <h1>{{ __('users.index.heading') }}</h1>

    <form method="POST" action="{{ url('/admin/logout') }}">
        @csrf
        <button type="submit">{{ __('auth.login.logout') }}</button>
    </form>
@endsection
