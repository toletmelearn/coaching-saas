@extends('layouts.app')

@section('content')
    <h1>{{ $user->name }}</h1>
    <p>{{ $user->email ?? $user->phone }}</p>
    <p>{{ __('users.roles.'.$user->role) }}</p>
@endsection
