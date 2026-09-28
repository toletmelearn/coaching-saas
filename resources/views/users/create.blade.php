@extends('layouts.app')

@section('content')
    <h1>{{ __('users.create.heading') }}</h1>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/users') }}">
        @csrf

        <label for="name">{{ __('users.create.name') }}</label>
        <input type="text" name="name" id="name" value="{{ old('name') }}" required>

        <label for="email">{{ __('users.create.email') }}</label>
        <input type="email" name="email" id="email" value="{{ old('email') }}">

        <label for="phone">{{ __('users.create.phone') }}</label>
        <input type="text" name="phone" id="phone" value="{{ old('phone') }}">

        <label for="role">{{ __('users.create.role') }}</label>
        <select name="role" id="role">
            <option value="student">{{ __('users.roles.student') }}</option>
            <option value="staff">{{ __('users.roles.staff') }}</option>
            <option value="owner">{{ __('users.roles.owner') }}</option>
        </select>

        <button type="submit">{{ __('users.create.submit') }}</button>
    </form>
@endsection
