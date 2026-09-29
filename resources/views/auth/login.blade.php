@extends('layouts.app')

@section('content')
    <h1 class="text-xl font-semibold mb-1">{{ $tenant->name }}</h1>
    <h2 class="text-lg text-gray-700 mb-4">{{ __('auth.login.submit') }}</h2>

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
