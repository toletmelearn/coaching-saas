@extends('layouts.app')

@section('content')
    <p class="mb-2">
        <a href="{{ url('/courses/'.$course->slug) }}" class="text-sm text-gray-600">&larr; {{ __('lessons.back_to_course') }}</a>
    </p>

    <x-page-header :title="$lesson->title" />

    @if ($lesson->board_tag)
        <span class="inline-block rounded-full px-2 py-0.5 text-xs bg-gray-200 text-gray-700 mb-4">{{ $lesson->board_tag }}</span>
    @endif

    @if ($lesson->youtube_video_id)
        <div class="aspect-video w-full mb-4">
            <iframe
                class="w-full h-full"
                src="https://www.youtube-nocookie.com/embed/{{ $lesson->youtube_video_id }}?rel=0"
                title="{{ $lesson->title }}"
                frameborder="0"
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            ></iframe>
        </div>
    @else
        <p class="mb-4 text-gray-600">{{ __('lessons.video_coming_soon') }}</p>
    @endif

    <p class="mb-6">{{ $lesson->description }}</p>

    <h2 class="text-lg font-semibold mb-2">{{ __('lessons.notes') }}</h2>
    <ul class="divide-y divide-gray-100 mb-6">
        @forelse ($lesson->attachments as $attachment)
            <li>
                <a
                    href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id.'/attachments/'.$attachment->id) }}"
                    target="_blank"
                    class="flex items-center justify-between gap-2 py-3 min-h-[44px]"
                >
                    <span>{{ $attachment->original_name }}</span>
                    <span class="text-sm text-indigo-600">{{ __('lessons.open_note') }}</span>
                </a>
            </li>
        @empty
            <li class="py-3 text-gray-500">{{ __('lessons.no_notes') }}</li>
        @endforelse
    </ul>

    <div class="flex items-center justify-between gap-2">
        <div>
            @if ($previous)
                <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$previous->id) }}" class="text-sm">&larr; {{ __('lessons.previous_lesson') }}: {{ $previous->title }}</a>
            @endif
        </div>
        <div>
            @if ($next)
                <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$next->id) }}" class="text-sm">{{ __('lessons.next_lesson') }}: {{ $next->title }} &rarr;</a>
            @endif
        </div>
    </div>
@endsection
