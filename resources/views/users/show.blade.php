@extends('layouts.app')

@section('content')
    <x-page-header :title="$user->name" :kicker="__('users.index.heading')">
        <x-slot:actions>
            <x-link href="{{ url('/users') }}" variant="ghost">
                <x-icon name="arrow-left" :size="16" />
                {{ __('users.index.heading') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    <div class="ui-list ui-rise" style="display: block;">
        <div class="ui-list-item">
            <span class="ui-subtle">{{ __('users.columns.email') }}</span>
            <span style="font-weight: 550; word-break: break-all; text-align: right;">{{ $user->email ?: '—' }}</span>
        </div>
        <div class="ui-list-item">
            <span class="ui-subtle">{{ __('users.columns.phone') }}</span>
            <span style="font-weight: 550;">{{ $user->phone ?: '—' }}</span>
        </div>
        <div class="ui-list-item">
            <span class="ui-subtle">{{ __('users.columns.role') }}</span>
            <x-badge tone="brand">{{ __('users.roles.'.$user->role->value) }}</x-badge>
        </div>
        <div class="ui-list-item">
            <span class="ui-subtle">{{ __('users.columns.status') }}</span>
            <x-badge :tone="$user->status->value === 'active' ? 'success' : 'warning'">{{ __('users.statuses.'.$user->status->value) }}</x-badge>
        </div>
    </div>
@endsection
