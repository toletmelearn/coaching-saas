@extends('layouts.app')

@section('content')
    <h1>{{ $course->title }}</h1>
    <p>{{ $course->description }}</p>

    @forelse ($course->chapters as $chapter)
        <h2>{{ $chapter->title }}</h2>
        <ul>
            @foreach ($chapter->lessons as $lesson)
                <li>
                    @if ($lesson->status->value === 'published')
                        @if ($lesson->is_free_preview)
                            <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id) }}">{{ $lesson->title }}</a>
                            <span>{{ __('courses.show.free') }}</span>
                        @else
                            <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id) }}">{{ $lesson->title }}</a>
                            <span>{{ __('courses.show.locked') }}</span>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <p>{{ __('courses.show.no_chapters') }}</p>
    @endforelse
@endsection
