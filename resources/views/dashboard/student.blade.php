@extends('layouts.app')

@section('content')
    <x-page-header :kicker="__('users.dashboard.welcome')" :title="__('nav.my_courses')" />

    @if ($liveNowClasses->isNotEmpty() || $startingSoonClasses->isNotEmpty())
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('live_classes.section_title') }}</h2>
        </div>
        <ul class="ui-list ui-fade mb-6">
            @foreach ($liveNowClasses as $liveClass)
                <li>
                    <div class="ui-list-item">
                        <div class="min-w-0 flex-1">
                            <a href="{{ url('/live-classes/'.$liveClass->id) }}" class="ui-h2" style="display: block; text-decoration: none;">
                                {{ $liveClass->title }}
                            </a>
                            <p class="ui-subtle" style="margin-top: 0.125rem;">{{ $liveClass->course?->title }}</p>
                        </div>
                        <div style="flex: none; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                            <x-badge tone="danger">{{ __('live_classes.live_now') }}</x-badge>
                            <x-link href="{{ url('/live-classes/'.$liveClass->id) }}" variant="primary" size="sm">
                                {{ __('live_classes.join') }}
                            </x-link>
                        </div>
                    </div>
                </li>
            @endforeach
            @foreach ($startingSoonClasses as $liveClass)
                <li>
                    <div class="ui-list-item">
                        <div class="min-w-0 flex-1">
                            <a href="{{ url('/live-classes/'.$liveClass->id) }}" class="ui-h2" style="display: block; text-decoration: none;">
                                {{ $liveClass->title }}
                            </a>
                            <p class="ui-subtle" style="margin-top: 0.125rem;">{{ $liveClass->course?->title }}</p>
                        </div>
                        <div style="flex: none; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                            <x-badge tone="neutral">
                                {{ __('live_classes.starts_in', ['minutes' => (int) ceil(($liveClass->starts_at->getTimestamp() - now()->getTimestamp()) / 60)]) }}
                            </x-badge>
                            <x-link href="{{ url('/live-classes/'.$liveClass->id) }}" size="sm">
                                {{ __('live_classes.upcoming') }}
                            </x-link>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <ul class="ui-list ui-fade mb-6">
        @forelse ($active as $enrolment)
            @php $progress = $courseProgress[$enrolment->course_id] ?? null; @endphp
            <li>
                <div class="ui-list-item" style="align-items: flex-start;">
                    <div class="min-w-0 flex-1">
                        <a href="{{ url('/courses/'.$enrolment->course->slug) }}" class="ui-h2" style="display: block; text-decoration: none;">
                            {{ $enrolment->course->title }}
                        </a>

                        @if ($enrolment->course->class_level || $enrolment->course->subject)
                            <p class="ui-subtle" style="margin-top: 0.125rem;">
                                {{ collect([$enrolment->course->class_level, $enrolment->course->subject])->filter()->implode(' · ') }}
                            </p>
                        @endif

                        @if ($progress && $progress['total'] > 0)
                            <div class="ui-progress" style="margin-top: 0.75rem;">
                                <span style="width: {{ $progress['percent'] }}%"></span>
                            </div>
                            <p class="ui-subtle" style="margin-top: 0.375rem;">
                                {{ __('progress.lessons_complete', ['completed' => $progress['completed'], 'total' => $progress['total'], 'percent' => $progress['percent']]) }}
                            </p>
                        @endif
                    </div>

                    <div style="flex: none; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                        @if ($enrolment->course->fee_paise && ! $enrolment->approved_payment_id)
                            <x-link href="{{ url('/enrolments/'.$enrolment->id.'/payment') }}" size="sm">
                                {{ __('payments.page.heading') }}
                            </x-link>
                        @endif

                        @if ($progress && $progress['nextLesson'])
                            <x-link href="{{ url('/courses/'.$enrolment->course->slug.'/lessons/'.$progress['nextLesson']->id) }}" variant="primary" size="sm">
                                {{ __('lessons.continue') }}
                                <x-icon name="arrow-right" :size="15" />
                            </x-link>
                        @elseif ($progress && $progress['allCompleted'])
                            <x-badge tone="success">
                                <x-icon name="check" :size="13" />
                                {{ __('progress.course_complete') }}
                            </x-badge>
                        @else
                            <x-link href="{{ url('/courses/'.$enrolment->course->slug) }}" size="sm">
                                {{ __('nav.courses') }}
                            </x-link>
                        @endif
                    </div>
                </div>
            </li>
        @empty
            <li>
                <div class="ui-empty" style="border: 0;">
                    <span class="ui-empty-icon"><x-icon name="book" :size="20" /></span>
                    <p class="ui-h2" style="margin: 0;">{{ __('courses.index.empty') }}</p>
                </div>
            </li>
        @endforelse
    </ul>

    @if ($ended->isNotEmpty())
        <div class="ui-section-title">
            <h2 class="ui-h2" style="color: var(--ink-subtle);">{{ __('users.dashboard.access_ended') }}</h2>
        </div>
        <ul class="ui-list ui-fade mb-6">
            @foreach ($ended as $enrolment)
                <li>
                    <div class="ui-list-item">
                        <span style="color: var(--ink-muted);">{{ $enrolment->course->title }}</span>
                        <x-badge tone="warning">{{ __('users.dashboard.access_ended') }}</x-badge>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="ui-panel ui-fade" style="padding: 1.125rem 1.25rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span class="ui-empty-icon"><x-icon name="book" :size="18" /></span>
            <span class="ui-h2">{{ __('nav.courses') }}</span>
        </div>
        <x-link href="{{ url('/courses') }}" variant="primary" size="sm">
            {{ __('lessons.continue') }}
            <x-icon name="arrow-right" :size="15" />
        </x-link>
    </div>
@endsection
