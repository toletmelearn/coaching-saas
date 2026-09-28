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

    <form method="POST" action="{{ url('/admin/login') }}">
        @csrf

        <label for="email">{{ __('auth.login.identifier') }}</label>
        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus>

        <label for="password">{{ __('auth.login.password') }}</label>
        <input type="password" name="password" id="password" required>

        <button type="submit">{{ __('auth.login.submit') }}</button>
    </form>
@endsection
