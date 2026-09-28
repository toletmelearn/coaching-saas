@extends('layouts.app')

@section('content')
    <h1>{{ __('auth.change_password.heading') }}</h1>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/auth/change-password') }}">
        @csrf

        <label for="password">{{ __('auth.change_password.new_password') }}</label>
        <input type="password" name="password" id="password" required>

        <label for="password_confirmation">{{ __('auth.change_password.confirm_password') }}</label>
        <input type="password" name="password_confirmation" id="password_confirmation" required>

        <button type="submit">{{ __('auth.change_password.submit') }}</button>
    </form>
@endsection
