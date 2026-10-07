@extends('layouts.app')

@section('content')
    <x-page-header :title="__('live_classes.manage.edit')" :subtitle="$liveClass->title" />

    @if ($liveClass->status === \App\Enums\LiveClassStatus::Cancelled)
        <x-alert tone="warning">{{ __('live_classes.manage.cancelled_notice') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert tone="danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alert>
    @endif

    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id) }}">
        @csrf
        @method('PATCH')

        <x-field name="title" :label="__('live_classes.manage.title')" :value="$liveClass->title" required />

        <x-field name="description" type="textarea" :label="__('live_classes.manage.description')" :value="$liveClass->description" />

        <x-field name="starts_at" type="datetime-local" :label="__('live_classes.manage.starts_at')" :value="\App\Support\LiveClasses\IstDateTime::toFormValue($liveClass->starts_at)" :hint="'IST'" required />

        <x-field
            name="ends_at"
            type="datetime-local"
            :label="__('live_classes.manage.ends_at')"
            :value="$liveClass->ends_at !== null ? \App\Support\LiveClasses\IstDateTime::toFormValue($liveClass->ends_at) : null"
            :hint="__('live_classes.manage.ends_at_hint', ['minutes' => (int) config('coaching.live_class_default_duration_minutes', 90)])"
        />

        <x-field name="meeting_url" type="url" :label="__('live_classes.manage.meeting_url')" :value="$liveClass->meeting_url" :hint="__('live_classes.manage.meeting_url_hint')" />

        <div class="mb-5">
            <label for="lesson_id" class="ui-label">{{ __('live_classes.manage.lesson') }}</label>
            <select name="lesson_id" id="lesson_id" class="ui-input">
                <option value="">{{ __('live_classes.manage.lesson_none') }}</option>
                @foreach ($lessons as $lesson)
                    <option value="{{ $lesson['id'] }}" @selected((string) old('lesson_id', $liveClass->lesson_id) === (string) $lesson['id'])>{{ $lesson['title'] }}</option>
                @endforeach
            </select>
        </div>

        @if ($liveClass->status !== \App\Enums\LiveClassStatus::Cancelled)
            <x-button>{{ __('live_classes.manage.save_changes') }}</x-button>
        @endif
    </form>

    {{-- Danger zone: cancel and delete. Cancel is only shown while the class
         is still actionable (not already cancelled/ended). Delete is always
         available so a mistake can be undone regardless of status. --}}
    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">

        @if (in_array($liveClass->status->value, ['scheduled', 'live'], true))
            <form method="POST"
                  action="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/cancel') }}"
                  onsubmit="return confirm('{{ __('live_classes.manage.confirm_cancel') }}')">
                @csrf
                <button type="submit" class="ui-btn ui-btn-ghost ui-btn-sm">
                    {{ __('live_classes.manage.cancel_class') }}
                </button>
            </form>
        @endif

        <form method="POST"
              action="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id) }}"
              onsubmit="return confirm('{{ __('live_classes.manage.confirm_delete') }}')">
            @csrf
            @method('DELETE')
            <button type="submit" class="ui-btn ui-btn-ghost ui-btn-sm" style="color: var(--tone-danger, #dc2626);">
                {{ __('live_classes.manage.delete') }}
            </button>
        </form>

        <a href="{{ url('/manage/courses/'.$course->id.'/live-classes') }}" class="ui-link ui-subtle" style="margin-left: auto;">
            {{ __('live_classes.manage.back') }}
        </a>
    </div>
@endsection
