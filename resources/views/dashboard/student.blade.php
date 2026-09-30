@extends('layouts.app')

@section('content')
    <x-page-header :title="__('nav.my_courses')" />

    <ul class="divide-y divide-gray-100 mb-6">
        @forelse ($active as $enrolment)
            @php $progress = $courseProgress[$enrolment->course_id] ?? null; @endphp
            <li class="py-3">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ url('/courses/'.$enrolment->course->slug) }}">{{ $enrolment->course->title }}</a>
                    @if ($progress && $progress['nextLesson'])
                        <a href="{{ url('/courses/'.$enrolment->course->slug.'/lessons/'.$progress['nextLesson']->id) }}" class="text-sm text-indigo-600">{{ __('lessons.continue') }}</a>
                    @elseif ($progress && $progress['allCompleted'])
                        <a href="{{ url('/courses/'.$enrolment->course->slug) }}" class="text-sm text-green-700">{{ __('progress.course_complete') }}</a>
                    @endif
                </div>
                @if ($progress && $progress['total'] > 0)
                    <div class="mt-1 h-1.5 w-full rounded-full bg-gray-200">
                        <div class="h-1.5 rounded-full bg-indigo-600" style="width: {{ $progress['percent'] }}%"></div>
                    </div>
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
