@extends('layouts.app')

@section('content')
    <h1>{{ __('auth.login.submit') }}</h1>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/login') }}">
        @csrf

        <label for="identifier">{{ __('auth.login.identifier') }}</label>
        <input type="text" name="identifier" id="identifier" value="{{ old('identifier') }}" required autofocus>

        <label for="password">{{ __('auth.login.password') }}</label>
        <input type="password" name="password" id="password" required>

        <label>
            <input type="checkbox" name="remember" value="1"> {{ __('auth.login.remember') }}
        </label>

        <button type="submit">{{ __('auth.login.submit') }}</button>
    </form>
@endsection
