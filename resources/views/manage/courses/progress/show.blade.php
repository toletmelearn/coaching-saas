@extends('layouts.app')

@section('content')
    <p class="mb-2">
        <a href="{{ url('/manage/courses/'.$course->id.'/progress') }}" class="text-sm text-gray-600">&larr; {{ __('progress.teacher.back_to_list') }}</a>
    </p>

    <x-page-header :title="$student->name.' — '.$course->title" />

    @if ($enrolment->status->value !== 'active')
        <p class="mb-4 inline-block rounded-full px-2 py-0.5 text-xs bg-gray-200 text-gray-700">{{ __('progress.teacher.access_ended') }}</p>
    @endif

    <ul class="divide-y divide-gray-100">
        @foreach ($lessons as $lesson)
            @php $progress = $progressByLesson->get($lesson->id); @endphp
            <li class="py-3 flex items-center justify-between gap-2">
                <span>{{ $lesson->title }}</span>
                <span class="text-sm">
                    @if ($progress && $progress->isCompleted())
                        <span class="text-green-700">{{ __('progress.completed') }}</span>
                    @elseif ($progress && $progress->duration_seconds)
                        {{ __('progress.teacher.in_progress', ['percent' => min(100, (int) round($progress->watched_seconds / max(1, $progress->duration_seconds) * 100))]) }}
                    @else
                        <span class="text-gray-500">{{ __('progress.teacher.not_started') }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endsection
