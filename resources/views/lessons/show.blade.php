@extends('layouts.app')

@if ($isRecordableStudent && $videoPlayback)
    @push('scripts')
        @vite('resources/js/lesson-progress.js')
    @endpush
@endif

@section('content')
    <p style="margin-bottom: 1rem;">
        <x-link href="{{ url('/courses/'.$course->slug) }}" variant="ghost" size="sm">
            <x-icon name="arrow-left" :size="16" />
            {{ __('lessons.back_to_course') }}
        </x-link>
    </p>

    <x-page-header :title="$lesson->title" />

    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.25rem;">
        @if ($lesson->board_tag)
            <x-badge tone="brand">{{ $lesson->board_tag }}</x-badge>
        @endif

        @if ($progress !== null && $progress->isCompleted() && $isRecordableStudent)
            <x-badge tone="success">
                <x-icon name="check" :size="13" />
                {{ __('progress.completed') }}
            </x-badge>
        @endif

        @if ($videoPlayback && $isRecordableStudent && $resumePosition > 0)
            <x-badge tone="neutral">{{ __('progress.resuming_from', ['time' => gmdate($resumePosition >= 3600 ? 'H:i:s' : 'i:s', $resumePosition)]) }}</x-badge>
        @endif
    </div>

    @if ($videoPlayback)
        <div
            id="video-wrapper-{{ $lesson->id }}"
            class="relative aspect-video w-full mb-4 bg-black ui-card"
            style="overflow: hidden; padding: 0;"
            @if ($isRecordableStudent)
                data-progress-url="{{ url('/lessons/'.$lesson->id.'/progress') }}"
                data-start-position="{{ $resumePosition }}"
                data-driver="{{ $videoPlayback['driver'] }}"
                data-completed="{{ ($progress !== null && $progress->isCompleted()) ? 'true' : 'false' }}"
            @endif
        >
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
                style="position: absolute; bottom: 0.5rem; right: 0.5rem; min-height: 32px; padding: 0 0.75rem; font-size: 0.75rem; font-weight: 600; background: rgba(0,0,0,0.6); color: #fff; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.25); backdrop-filter: blur(6px);"
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
        <div class="aspect-video w-full mb-4 ui-card" style="overflow: hidden; padding: 0;">
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
        <div class="ui-panel ui-fade" style="padding: 1.125rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
            <span class="ui-empty-icon"><x-icon name="play" :size="18" /></span>
            <span style="color: var(--brand-ink); font-weight: 550;">{{ __('lessons.video_coming_soon') }}</span>
        </div>
    @endif

    @if ($isRecordableStudent && ! $videoPlayback)
        <form method="POST" action="{{ url('/lessons/'.$lesson->id.'/completion') }}" class="mb-5">
            @csrf
            @method('PUT')
            @if ($progress !== null && $progress->isCompleted())
                <input type="hidden" name="completed" value="0">
                <x-button type="submit" variant="secondary">{{ __('progress.completed_check') }} — {{ __('progress.mark_not_complete') }}</x-button>
            @else
                <input type="hidden" name="completed" value="1">
                <x-button type="submit">
                    <x-icon name="check" :size="17" />
                    {{ __('progress.mark_complete') }}
                </x-button>
            @endif
        </form>
    @endif

    @if ($lesson->description)
        <div class="ui-card ui-fade" style="margin-bottom: 1.5rem; padding: 1rem 1.125rem;">
            <p style="margin: 0; color: var(--ink-muted);">{{ $lesson->description }}</p>
        </div>
    @endif

    <div class="ui-section-title">
        <h2 class="ui-h2">{{ __('lessons.notes') }}</h2>
    </div>
    <ul class="ui-list ui-fade" style="margin-bottom: 1.5rem;">
        @forelse ($lesson->attachments as $attachment)
            <li>
                <a
                    href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id.'/attachments/'.$attachment->id) }}"
                    target="_blank"
                    class="ui-list-item"
                    style="text-decoration: none;"
                >
                    <span style="display: inline-flex; align-items: center; gap: 0.625rem;">
                        <span class="ui-empty-icon" style="width: 30px; height: 30px;"><x-icon name="document" :size="15" /></span>
                        <span style="font-weight: 550;">{{ $attachment->original_name }}</span>
                    </span>
                    <span class="ui-link" style="font-size: 0.875rem;">{{ __('lessons.open_note') }}</span>
                </a>
            </li>
        @empty
            <li>
                <div class="ui-list-item" style="color: var(--ink-subtle);">
                    <span>{{ __('lessons.no_notes') }}</span>
                </div>
            </li>
        @endforelse
    </ul>

    {{-- Deliberately unlabelled: the two links already begin with "Previous lesson" and
         "Next lesson", which is a better accessible name than any generic label, and
         no untranslated copy is invented for it. --}}
    <nav class="ui-panel" style="padding: 0.875rem 1rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between;">
        <div class="min-w-0">
            @if ($previous)
                <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$previous->id) }}" class="ui-link" style="font-size: 0.875rem; display: inline-flex; align-items: center; gap: 0.375rem;">
                    <x-icon name="arrow-left" :size="15" />
                    {{ __('lessons.previous_lesson') }}: {{ $previous->title }}
                </a>
            @endif
        </div>
        <div class="min-w-0">
            @if ($next)
                <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$next->id) }}" class="ui-link" style="font-size: 0.875rem; display: inline-flex; align-items: center; gap: 0.375rem;">
                    {{ __('lessons.next_lesson') }}: {{ $next->title }}
                    <x-icon name="arrow-right" :size="15" />
                </a>
            @endif
        </div>
    </nav>
@endsection
