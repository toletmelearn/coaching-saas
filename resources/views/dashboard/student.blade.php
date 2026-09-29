@extends('layouts.app')

@section('content')
    <x-page-header :title="__('nav.my_courses')" />

    <ul class="divide-y divide-gray-100 mb-6">
        @forelse ($active as $enrolment)
            @php $continueLesson = $continueLessons[$enrolment->course_id] ?? null; @endphp
            <li class="py-2 flex items-center justify-between gap-2">
                <a href="{{ url('/courses/'.$enrolment->course->slug) }}">{{ $enrolment->course->title }}</a>
                @if ($continueLesson)
                    <a href="{{ url('/courses/'.$enrolment->course->slug.'/lessons/'.$continueLesson->id) }}" class="text-sm text-indigo-600">{{ __('lessons.continue') }}</a>
                @endif
            </li>
        @empty
            <li class="py-2 text-gray-500">{{ __('courses.index.empty') }}</li>
        @endforelse
    </ul>

    @if ($ended->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ __('users.dashboard.access_ended') }}</h2>
        <ul class="divide-y divide-gray-100">
            @foreach ($ended as $enrolment)
                <li class="py-2 text-gray-500">{{ $enrolment->course->title }}</li>
            @endforeach
        </ul>
    @endif
@endsection
