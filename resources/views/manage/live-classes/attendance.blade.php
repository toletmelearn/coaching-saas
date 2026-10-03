@extends('layouts.app')

@section('content')
    <x-page-header :title="__('live_classes.report.heading')" :subtitle="$liveClass->title">
        <x-slot:actions>
            <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/attendance/export') }}" variant="ghost" size="sm">
                {{ __('live_classes.report.export') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    <p class="ui-subtle" style="margin-bottom: 1rem;">
        {{ __('live_classes.report.enrolled_count', ['count' => $enrolledCount]) }}
        @if ($minMinutes !== null)
            · {{ __('live_classes.report.filter_hint', ['minutes' => $minMinutes]) }}
            <a href="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/attendance') }}">{{ __('live_classes.report.filter_all') }}</a>
        @endif
    </p>

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('live_classes.report.student') }}</th>
                    <th scope="col">{{ __('live_classes.report.joined') }}</th>
                    <th scope="col">{{ __('live_classes.report.left') }}</th>
                    <th scope="col">{{ __('live_classes.report.minutes') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendance as $row)
                    <tr>
                        <td style="font-weight: 650;">{{ $row->user?->name }}</td>
                        <td>{{ $row->joined_at?->format('d M Y, H:i') }}</td>
                        <td>{{ $row->left_at?->format('d M Y, H:i') ?? __('live_classes.report.still_in') }}</td>
                        <td>{{ (int) round((int) $row->duration_seconds / 60) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="color: var(--ink-subtle);">{{ __('live_classes.report.no_attendance') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- The absentee list is a different question from "who cleared the bar", so
         it disappears entirely while a min_minutes filter is active. --}}
    @if ($minMinutes === null)
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('live_classes.absent') }}</h2>
        </div>

        <ul class="ui-list ui-fade mb-6">
            @forelse ($absent as $enrolment)
                <li>
                    <div class="ui-list-item">
                        <span style="color: var(--ink-muted);">{{ $enrolment->user?->name }}</span>
                        <x-badge tone="warning">{{ __('live_classes.absent') }}</x-badge>
                    </div>
                </li>
            @empty
                <li>
                    <div class="ui-list-item">
                        <span style="color: var(--ink-muted);">{{ __('live_classes.report.absent_none') }}</span>
                    </div>
                </li>
            @endforelse
        </ul>
    @endif

    <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes') }}" variant="ghost" size="sm">
        {{ __('live_classes.manage.back') }}
    </x-link>
@endsection
