@extends('layouts.app')

@section('content')
    <x-page-header :title="__('live_classes.list.heading')" :subtitle="__('live_classes.list.subtitle')" />

    {{-- Filters. The controller normalises ?filter= to upcoming/past/all, so the
         active chip can never be "none of these" — an unrecognised value simply
         lands on All. Wrapped for horizontal scroll: at 360px the three chips
         would otherwise push the page sideways (docs/PILOT_CHECKLIST.md). --}}
    <div class="overflow-x-auto" style="margin-bottom: 1.5rem;">
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; min-width: max-content;">
            @foreach (['upcoming', 'past', 'all'] as $option)
                <a href="{{ url('/manage/live-classes?filter='.$option) }}" class="ui-chip {{ $filter === $option ? 'is-active' : '' }}">
                    {{ __('live_classes.list.filters.'.$option) }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('live_classes.list.columns.course') }}</th>
                    <th scope="col">{{ __('live_classes.list.columns.title') }}</th>
                    <th scope="col">{{ __('live_classes.list.columns.starts') }}</th>
                    <th scope="col">{{ __('live_classes.list.columns.status') }}</th>
                    <th scope="col">{{ __('live_classes.list.columns.attendance') }}</th>
                    <th scope="col">{{ __('live_classes.list.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($liveClasses as $liveClass)
                <tr>
                    {{-- The course cell is the way back into that course's own
                         live-classes page, which is where "Schedule a live
                         class" lives — this table never schedules anything
                         itself (no course is in scope for a tenant-wide POST). --}}
                    <td>
                        <a href="{{ url('/manage/courses/'.$liveClass->course_id.'/live-classes') }}" class="ui-link">
                            {{ $liveClass->course?->title }}
                        </a>
                    </td>
                    <td style="font-weight: 650;">{{ $liveClass->title }}</td>
                    <td>{{ $liveClass->starts_at->format('D, d M Y, H:i') }}</td>
                    <td>
                        <x-badge :tone="match ($liveClass->status->value) {
                            'live' => 'danger',
                            'cancelled' => 'warning',
                            default => 'neutral',
                        }">{{ __('live_classes.status.'.$liveClass->status->value) }}</x-badge>
                    </td>
                    {{-- withCount('attendance') on the query: a number, never a
                         per-row query, however many classes are listed. --}}
                    <td>{{ $liveClass->attendance_count }}</td>
                    <td>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <x-link href="{{ url('/manage/courses/'.$liveClass->course_id.'/live-classes/'.$liveClass->id.'/edit') }}" size="sm">
                                {{ __('live_classes.manage.edit') }}
                            </x-link>

                            {{-- Only classes that still have a door offer Join; the
                                 refusal reasons (too early / cancelled / ended) stay
                                 on the class page where they can be explained. --}}
                            @if (in_array($liveClass->status->value, ['scheduled', 'live'], true))
                                <x-link href="{{ url('/live-classes/'.$liveClass->id.'/join') }}" size="sm">
                                    {{ __('live_classes.join') }}
                                </x-link>
                            @endif

                            <x-link href="{{ url('/manage/courses/'.$liveClass->course_id.'/live-classes/'.$liveClass->id.'/attendance') }}" size="sm">
                                {{ __('live_classes.manage.attendance') }}
                            </x-link>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color: var(--ink-subtle);">
                        {{ $filter === 'all' ? __('live_classes.list.empty') : __('live_classes.list.empty_filter') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
