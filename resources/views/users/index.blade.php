@extends('layouts.app')

@section('content')
    <h1>{{ __('users.index.heading') }}</h1>

    <p><a href="{{ url('/users/create') }}">{{ __('users.index.new') }}</a></p>

    <table>
        <tbody>
        @forelse ($users as $user)
            <tr>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email ?? $user->phone }}</td>
                <td>{{ __('users.roles.'.$user->role) }}</td>
            </tr>
        @empty
            <tr>
                <td>{{ __('users.index.empty') }}</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{ $users->links() }}
@endsection
