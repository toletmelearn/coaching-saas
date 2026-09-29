@extends('layouts.app')

@section('content')
    <x-page-header :title="$user->name" />

    <p>{{ $user->email }}</p>
    <p>{{ $user->phone }}</p>
    <p>{{ __('users.roles.'.$user->role->value) }}</p>
    <p>{{ __('users.statuses.'.$user->status->value) }}</p>
@endsection
