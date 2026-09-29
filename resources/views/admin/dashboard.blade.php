@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.index.heading')" />

    <form method="POST" action="{{ url('/admin/logout') }}">
        @csrf
        <x-button variant="secondary">{{ __('auth.login.logout') }}</x-button>
    </form>
@endsection
