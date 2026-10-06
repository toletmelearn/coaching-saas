@extends('layouts.app')

@section('content')
    <x-page-header
        :title="$liveClass->title"
        :subtitle="$liveClass->description"
        :kicker="match ($status) {
            \App\Enums\LiveClassStatus::Live => __('live_classes.live_now'),
            \App\Enums\LiveClassStatus::Scheduled => __('live_classes.upcoming'),
            \App\Enums\LiveClassStatus::Ended => __('live_classes.ended'),
            \App\Enums\LiveClassStatus::Cancelled => __('live_classes.cancelled'),
        }"
    />

    <div class="ui-card ui-rise" style="margin-bottom: 1.25rem;">
        <p class="ui-subtle" style="margin: 0 0 0.25rem;">
            {{ __('live_classes.page.when') }}:
            {{ \App\Support\LiveClasses\IstDateTime::display($liveClass->starts_at) }}
        </p>
        @if ($liveClass->ends_at)
            <p class="ui-subtle" style="margin: 0;">
                {{ __('live_classes.duration_minutes', ['minutes' => (int) $liveClass->starts_at->diffInMinutes($liveClass->ends_at)]) }}
            </p>
        @endif
    </div>

    {{-- The class's live state. The room name and the JaaS URL never appear here —
         they only ever leave the server inside the join redirect's Location header. --}}
    <div class="ui-card ui-rise" style="margin-bottom: 1.25rem;">
        @if ($status === \App\Enums\LiveClassStatus::Live && $canJoin)
            <p class="ui-h2" style="margin: 0 0 0.5rem; color: var(--brand-ink);">
                {{ __('live_classes.live_now') }}
            </p>
            <x-link href="{{ url('/live-classes/'.$liveClass->id.'/join') }}" variant="primary">
                {{ __('live_classes.join') }}
            </x-link>
            <p class="ui-subtle" style="margin: 0.75rem 0 0;">{{ __('live_classes.page.join_hint') }}</p>
        @elseif ($status === \App\Enums\LiveClassStatus::Scheduled)
            @if ($canJoin)
                {{-- Inside the pre-open window: the room is open and the button shows,
                     with the countdown alongside it. --}}
                <p class="ui-h2" style="margin: 0 0 0.5rem;">
                    {{ __('live_classes.starts_in', ['minutes' => $minutesUntilStart]) }}
                </p>
                <x-link href="{{ url('/live-classes/'.$liveClass->id.'/join') }}" variant="primary">
                    {{ __('live_classes.join') }}
                </x-link>
            @else
                <p class="ui-h2" style="margin: 0 0 0.5rem;">
                    {{ __('live_classes.starts_in', ['minutes' => $minutesUntilStart]) }}
                </p>
                <p class="ui-subtle" style="margin: 0;">{{ __('live_classes.page.not_started') }}</p>
            @endif
        @elseif ($status === \App\Enums\LiveClassStatus::Ended)
            <p class="ui-h2" style="margin: 0;">{{ __('live_classes.ended') }}</p>
        @else
            <p class="ui-h2" style="margin: 0;">{{ __('live_classes.cancelled') }}</p>
        @endif

        @if ($hasAttended)
            <p class="ui-subtle" style="margin: 1rem 0 0; display: flex; align-items: center; gap: 0.375rem;">
                <x-icon name="check" :size="15" />
                {{ __('live_classes.you_attended') }}
            </p>
        @endif
    </div>

    @if ($recordingEnabled)
        <x-alert tone="warning">{{ __('live_classes.recording_notice') }}</x-alert>
    @endif
@endsection
