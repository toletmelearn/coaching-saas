@extends('layouts.app')

@section('content')
    <x-page-header :title="$course->title" />

    @if ($progressSummary)
        <div class="mb-4">
            <p class="text-sm text-gray-700 mb-1">{{ __('progress.lessons_complete', ['completed' => $progressSummary['completed'], 'total' => $progressSummary['total'], 'percent' => $progressSummary['percent']]) }}</p>
            <div class="h-2 w-full rounded-full bg-gray-200">
                <div class="h-2 rounded-full bg-indigo-600" style="width: {{ $progressSummary['percent'] }}%"></div>
            </div>
        </div>
    @endif

    <p class="mb-6">{{ $course->description }}</p>

    @forelse ($course->chapters as $chapter)
        @php $publishedLessons = $chapter->lessons->where('status', \App\Enums\LessonStatus::Published); @endphp
        <section class="mb-6">
            <h2 class="text-lg font-semibold mb-2">{{ $chapter->title }}</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($publishedLessons as $lesson)
                    <li class="py-2">
                        @if ($progressSummary && $progressSummary['completedLessonIds']->contains($lesson->id))
                            <span class="text-green-600" aria-hidden="true">&check;</span>
                        @endif
                        <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id) }}">{{ $lesson->title }}</a>
                        @if ($lesson->is_free_preview)
                            <span class="ml-2 inline-block rounded-full px-2 py-0.5 text-xs bg-green-100 text-green-800">{{ __('courses.show.free') }}</span>
                        @elseif ($access->lessonAccess($viewer, $lesson) !== 'ok')
                            <span class="ml-2 inline-block rounded-full px-2 py-0.5 text-xs bg-gray-200 text-gray-700">{{ __('courses.show.locked') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p>{{ __('courses.show.no_chapters') }}</p>
    @endforelse
@endsection
