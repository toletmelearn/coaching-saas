@extends('layouts.app')

@section('content')
    <h1>{{ __('courses.index.heading') }}</h1>

    @forelse ($courses as $course)
        <p>
            <a href="{{ url('/courses/'.$course->slug) }}">{{ $course->title }}</a>
            @if ($course->class_level) — {{ $course->class_level }} @endif
            @if ($course->subject) — {{ $course->subject }} @endif
            — {{ __('courses.index.lessons_count', ['count' => $course->lessons_count]) }}
        </p>
    @empty
        <p>{{ __('courses.index.empty') }}</p>
    @endforelse

    {{ $courses->links() }}
@endsection
