@extends('layouts.app')

@section('content')
    <div class="ui-rise" style="width: 100%; max-width: 26.5rem; margin-inline: auto;">
        <div class="ui-card" style="padding: 1.5rem 1.25rem 1.75rem;">
            <x-page-header :title="__('auth.change_password.heading')" />

            @if ($errors->any())
                <x-alert tone="danger">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </x-alert>
            @endif

            <form method="POST" action="{{ url('/auth/change-password') }}">
                @csrf

                <x-field name="password" type="password" :label="__('auth.change_password.new_password')" required />
                <x-field name="password_confirmation" type="password" :label="__('auth.change_password.confirm_password')" required />

                <x-button class="w-full">{{ __('auth.change_password.submit') }}</x-button>
            </form>
        </div>
    </div>
@endsection
