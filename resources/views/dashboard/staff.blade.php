@extends('layouts.app')

@section('content')
    <x-page-header :kicker="__('users.dashboard.welcome')" :title="$user->name" />

    @if ($showGettingStarted)
        <div class="ui-card ui-rise" style="margin-bottom: 1.5rem;">
            <div class="ui-section-title">
                <h2 class="ui-h2">{{ __('dashboard.getting_started.title') }}</h2>
                <form method="POST" action="{{ url('/manage/getting-started/dismiss') }}">
                    @csrf
                    <button type="submit" class="ui-btn ui-btn-ghost ui-btn-sm">{{ __('dashboard.getting_started.hide') }}</button>
                </form>
            </div>

            <ul style="list-style: none; display: grid; gap: 0.625rem;">
                @foreach ($checklist as $step)
                    <li style="display: flex; align-items: center; gap: 0.625rem; font-size: 0.9375rem;">
                        <span aria-hidden="true" class="ui-empty-icon" style="width: 26px; height: 26px; {{ $step['done'] ? 'background: #ecfdf5; color: #047857;' : 'background: #f5f5f4; color: var(--ink-subtle);' }}">
                            <x-icon :name="$step['done'] ? 'check' : 'sparkle'" :size="14" />
                        </span>
                        @if ($step['done'])
                            <span style="color: var(--ink-subtle); text-decoration: line-through;">{{ $step['label'] }}</span>
                        @else
                            <a href="{{ url($step['link']) }}" class="ui-link">{{ $step['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ui-fade" style="display: flex; flex-wrap: wrap; gap: 0.625rem;">
        <x-link href="{{ url('/manage/courses') }}" variant="primary">
            <x-icon name="book" :size="17" />
            {{ __('nav.courses') }}
        </x-link>
        <x-link href="{{ url('/users') }}">
            <x-icon name="users" :size="17" />
            {{ __('users.dashboard.manage_people') }}
        </x-link>
        <x-link href="{{ url('/manage/help') }}" variant="ghost">
            <x-icon name="help" :size="17" />
            {{ __('help.nav_label') }}
        </x-link>
    </div>
@endsection
