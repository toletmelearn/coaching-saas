@extends('layouts.app')

@section('content')
    <x-page-header :title="__('auth.login.submit')" />

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/login') }}">
        @csrf

        <x-field name="email" type="email" :label="__('auth.login.identifier')" required />
        <x-field name="password" type="password" :label="__('auth.login.password')" required />

        <x-button>{{ __('auth.login.submit') }}</x-button>
    </form>
@endsection
