@extends('layouts.app')

@section('content')
    <x-page-header :title="$course->title" />
    <p class="mb-6">{{ $course->description }}</p>

    @forelse ($course->chapters as $chapter)
        @php $publishedLessons = $chapter->lessons->where('status', \App\Enums\LessonStatus::Published); @endphp
        <section class="mb-6">
            <h2 class="text-lg font-semibold mb-2">{{ $chapter->title }}</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($publishedLessons as $lesson)
                    <li class="py-2">
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
