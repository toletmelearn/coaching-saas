@extends('layouts.app')

@section('content')
    <x-page-header :title="__('live_classes.manage.schedule')" :subtitle="$course->title" />

    @if ($errors->any())
        <x-alert tone="danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alert>
    @endif

    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/live-classes') }}">
        @csrf

        <x-field name="title" :label="__('live_classes.manage.title')" required />

        <x-field name="description" type="textarea" :label="__('live_classes.manage.description')" />

        <x-field name="starts_at" type="datetime-local" :label="__('live_classes.manage.starts_at')" required />

        <x-field
            name="ends_at"
            type="datetime-local"
            :label="__('live_classes.manage.ends_at')"
            :hint="__('live_classes.manage.ends_at_hint', ['minutes' => (int) config('coaching.live_class_default_duration_minutes', 90)])"
        />

        <x-field name="meeting_url" type="url" :label="__('live_classes.manage.meeting_url')" :hint="__('live_classes.manage.meeting_url_hint')" />

        <div class="mb-5">
            <label for="lesson_id" class="ui-label">{{ __('live_classes.manage.lesson') }}</label>
            <select name="lesson_id" id="lesson_id" class="ui-input">
                <option value="">{{ __('live_classes.manage.lesson_none') }}</option>
                @foreach ($lessons as $lesson)
                    <option value="{{ $lesson['id'] }}" @selected(old('lesson_id') == $lesson['id'])>{{ $lesson['title'] }}</option>
                @endforeach
            </select>
        </div>

        <x-button>{{ __('live_classes.manage.save') }}</x-button>
    </form>
@endsection
