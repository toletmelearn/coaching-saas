@extends('layouts.app')

@section('content')
    <x-page-header :title="__('auth.change_password.heading')" />

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/auth/change-password') }}">
        @csrf

        <x-field name="password" type="password" :label="__('auth.change_password.new_password')" required />
        <x-field name="password_confirmation" type="password" :label="__('auth.change_password.confirm_password')" required />

        <x-button>{{ __('auth.change_password.submit') }}</x-button>
    </form>
@endsection
