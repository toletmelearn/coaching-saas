@extends('layouts.app')

@section('content')
    <x-page-header :title="$course->title" :subtitle="$course->description" :kicker="$course->class_level || $course->subject ? collect([$course->class_level, $course->subject])->filter()->implode(' · ') : null" />

    @if ($progressSummary)
        <div class="ui-card ui-rise" style="margin-bottom: 1.25rem;">
            <p class="ui-subtle" style="margin: 0 0 0.5rem;">
                {{ __('progress.lessons_complete', ['completed' => $progressSummary['completed'], 'total' => $progressSummary['total'], 'percent' => $progressSummary['percent']]) }}
            </p>
            <div class="ui-progress" role="progressbar" aria-valuenow="{{ $progressSummary['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                <span style="width: {{ $progressSummary['percent'] }}%"></span>
            </div>
        </div>
    @endif

    @if ($upcomingClasses->isNotEmpty() || $pastClasses->isNotEmpty())
        <section class="mb-6 ui-fade">
            <div class="ui-section-title">
                <h2 class="ui-h2">{{ __('live_classes.section_title') }}</h2>
            </div>

            <ul class="ui-list">
                @foreach ($upcomingClasses as $liveClass)
                    @php $minutes = (int) ceil(($liveClass->starts_at->getTimestamp() - now()->getTimestamp()) / 60); @endphp
                    <li>
                        <div style="display: flex; align-items: center; gap: 0.625rem; padding-block: 0.375rem; flex-wrap: wrap;">
                            <a href="{{ url('/live-classes/'.$liveClass->id) }}" class="min-w-0" style="flex: 1; font-weight: 550;">
                                {{ $liveClass->title }}
                            </a>

                            @if ($liveClass->status === \App\Enums\LiveClassStatus::Live)
                                <x-badge tone="danger">{{ __('live_classes.live_now') }}</x-badge>
                            @elseif ($minutes > (int) config('coaching.live_class_join_window_minutes', 15))
                                <x-badge tone="neutral">{{ __('live_classes.starts_in', ['minutes' => max(0, $minutes)]) }}</x-badge>
                            @else
                                <x-badge tone="brand">{{ __('live_classes.upcoming') }}</x-badge>
                            @endif
                        </div>
                    </li>
                @endforeach

                @foreach ($pastClasses as $liveClass)
                    <li>
                        <div style="display: flex; align-items: center; gap: 0.625rem; padding-block: 0.375rem; flex-wrap: wrap;">
                            <a href="{{ url('/live-classes/'.$liveClass->id) }}" class="min-w-0" style="flex: 1; font-weight: 550;">
                                {{ $liveClass->title }}
                            </a>
                            <x-badge tone="neutral">
                                {{ $liveClass->status === \App\Enums\LiveClassStatus::Ended ? __('live_classes.ended') : __('live_classes.cancelled') }}
                            </x-badge>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @forelse ($course->chapters as $chapter)
        @php $publishedLessons = $chapter->lessons->where('status', \App\Enums\LessonStatus::Published); @endphp
        <section class="mb-6 ui-fade">
            <div class="ui-section-title">
                <h2 class="ui-h2">{{ $chapter->title }}</h2>
            </div>

            <ul class="ui-list">
                @foreach ($publishedLessons as $lesson)
                    {{-- The `py-2` on this element is asserted by PublicPagesUiTest — it is the
                         visible proof that a draft lesson rendered no stray empty row. Keep it. --}}
                    <li class="py-2">
                        <div style="display: flex; align-items: center; gap: 0.625rem; padding-block: 0.375rem;">
                            @if ($progressSummary && $progressSummary['completedLessonIds']->contains($lesson->id))
                                <span class="ui-empty-icon" aria-hidden="true" style="width: 24px; height: 24px; background: #ecfdf5; color: #047857;">
                                    <x-icon name="check" :size="14" />
                                </span>
                            @else
                                <span class="ui-empty-icon" aria-hidden="true" style="width: 24px; height: 24px;">
                                    <x-icon name="play" :size="13" />
                                </span>
                            @endif

                            <a href="{{ url('/courses/'.$course->slug.'/lessons/'.$lesson->id) }}" class="min-w-0" style="flex: 1; font-weight: 550;">
                                {{ $lesson->title }}
                            </a>

                            @if ($lesson->is_free_preview)
                                <x-badge tone="success">{{ __('courses.show.free') }}</x-badge>
                            @elseif ($access->lessonAccess($viewer, $lesson) !== 'ok')
                                <x-badge tone="neutral">
                                    <x-icon name="shield" :size="13" />
                                    {{ __('courses.show.locked') }}
                                </x-badge>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="ui-empty ui-fade">
            <span class="ui-empty-icon"><x-icon name="inbox" :size="20" /></span>
            <p class="ui-h2" style="margin: 0;">{{ __('courses.show.no_chapters') }}</p>
        </div>
    @endforelse
@endsection
