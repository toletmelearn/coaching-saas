@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.index.heading')" />

    <ul class="divide-y divide-gray-100">
        @forelse ($courses as $course)
            <li class="py-3">
                <a href="{{ url('/courses/'.$course->slug) }}" class="font-medium">{{ $course->title }}</a>
                <span class="text-sm text-gray-600">
                    @if ($course->class_level) — {{ $course->class_level }} @endif
                    @if ($course->subject) — {{ $course->subject }} @endif
                    — {{ __('courses.index.lessons_count', ['count' => $course->lessons_count]) }}
                </span>
            </li>
        @empty
            <li class="py-3 text-gray-500">{{ __('courses.index.empty') }}</li>
        @endforelse
    </ul>

    {{ $courses->links() }}
@endsection
