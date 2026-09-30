@extends('layouts.app')

@if ($isRecordableStudent && $videoPlayback)
    @push('scripts')
        @vite('resources/js/lesson-progress.js')
    @endpush
@endif

@section('content')
    <p class="mb-2">
        <a href="{{ url('/courses/'.$course->slug) }}" class="text-sm text-gray-600">&larr; {{ __('lessons.back_to_course') }}</a>
    </p>

    <x-page-header :title="$lesson->title" />

    @if ($lesson->board_tag)
        <span class="inline-block rounded-full px-2 py-0.5 text-xs bg-gray-200 text-gray-700 mb-4">{{ $lesson->board_tag }}</span>
    @endif

    @if ($videoPlayback)
        @if ($isRecordableStudent && $resumePosition > 0)
            <p class="mb-2 text-sm text-gray-600">{{ __('progress.resuming_from', ['time' => gmdate($resumePosition >= 3600 ? 'H:i:s' : 'i:s', $resumePosition)]) }}</p>
        @endif

        <div
            id="video-wrapper-{{ $lesson->id }}"
            class="relative aspect-video w-full mb-4 bg-black"
            @if ($isRecordableStudent)
                data-progress-url="{{ url('/lessons/'.$lesson->id.'/progress') }}"
                data-start-position="{{ $resumePosition }}"
                data-driver="{{ $videoPlayback['driver'] }}"
                data-completed="{{ ($progress !== null && $progress->isCompleted()) ? 'true' : 'false' }}"
            @endif
        >
            @if ($isRecordableStudent && $progress !== null && $progress->isCompleted())
                <span class="absolute top-2 right-2 z-10 rounded-full px-2 py-0.5 text-xs bg-green-600 text-white">{{ __('progress.completed') }}</span>
            @endif

            @if ($videoPlayback['driver'] === 'bunny')
                <iframe
                    class="w-full h-full"
                    src="{{ $videoPlayback['url'] }}"
                    title="{{ $lesson->title }}"
                    frameborder="0"
                    referrerpolicy="strict-origin-when-cross-origin"
                ></iframe>
            @else
                <video
                    class="w-full h-full"
                    controls
                    preload="metadata"
                    controlsList="nofullscreen noremoteplayback"
                    disablePictureInPicture
                    oncontextmenu="return false"
                >
                    <source src="{{ $videoPlayback['url'] }}" type="video/mp4">
                </video>
            @endif

            <div
                class="video-watermark"
                style="position: absolute; top: 5%; left: 5%; pointer-events: none; color: rgba(255,255,255,0.75); font-size: 0.7rem; text-shadow: 0 1px 2px rgba(0,0,0,0.85); transition: top 0.6s ease, left 0.6s ease;"
            >{{ $videoPlayback['watermarkText'] }}</div>

            <button
                type="button"
                class="video-fullscreen-button"
                style="position: absolute; bottom: 0.5rem; right: 0.5rem; min-height: 32px; padding: 0 0.5rem; font-size: 0.75rem; background: rgba(0,0,0,0.6); color: #fff; border-radius: 0.25rem; border: 0;"
                onclick="document.getElementById('video-wrapper-{{ $lesson->id }}').requestFullscreen()"
            >{{ __('lessons.video.fullscreen') }}</button>
        </div>

        <script>
            (function () {
                var watermark = document.querySelector('#video-wrapper-{{ $lesson->id }} .video-watermark');
                if (!watermark) return;

                function reposition() {
                    watermark.style.top = (Math.random() * 80) + '%';
                    watermark.style.left = (Math.random() * 80) + '%';
                }

                function schedule() {
                    // Reposition every 20000-40000 ms (20-40 seconds) to deter simple screen recording.
                    var minMs = 20000, maxMs = 40000;
                    setTimeout(function () {
                        reposition();
                        schedule();
                    }, Math.random() * (maxMs - minMs) + minMs);
                }

                schedule();
            })();
        </script>
    @elseif ($lesson->youtube_video_id)
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

    @if ($isRecordableStudent && ! $videoPlayback)
        <form method="POST" action="{{ url('/lessons/'.$lesson->id.'/completion') }}" class="mb-4">
            @csrf
            @method('PUT')
            @if ($progress !== null && $progress->isCompleted())
                <input type="hidden" name="completed" value="0">
                <x-button type="submit" variant="secondary">{{ __('progress.completed_check') }} — {{ __('progress.mark_not_complete') }}</x-button>
            @else
                <input type="hidden" name="completed" value="1">
                <x-button type="submit">{{ __('progress.mark_complete') }}</x-button>
            @endif
        </form>
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
