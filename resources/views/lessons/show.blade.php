@extends('layouts.app')

@section('content')
    <h1>{{ $lesson->title }}</h1>

    @if ($lesson->board_tag)
        <span>{{ $lesson->board_tag }}</span>
    @endif

    @if ($lesson->youtube_video_id)
        <iframe
            width="100%"
            height="240"
            src="https://www.youtube-nocookie.com/embed/{{ $lesson->youtube_video_id }}"
            title="{{ $lesson->title }}"
            frameborder="0"
            allowfullscreen
        ></iframe>
    @else
        <p>{{ __('lessons.video_coming_soon') }}</p>
    @endif

    <p>{{ $lesson->description }}</p>

    <h2>{{ __('lessons.notes') }}</h2>
    @forelse ($lesson->attachments as $attachment)
        <p>
            <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id.'/attachments/'.$attachment->id) }}" target="_blank">
                {{ $attachment->original_name }} — {{ __('lessons.open_note') }}
            </a>
        </p>
    @empty
        <p>{{ __('lessons.no_notes') }}</p>
    @endforelse
@endsection
