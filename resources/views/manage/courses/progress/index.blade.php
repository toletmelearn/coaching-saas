@extends('layouts.app')

@section('content')
    <div class="overflow-x-auto" style="margin-bottom: 1.5rem;">
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; min-width: max-content;">
            <a href="{{ url('/manage/courses/'.$course->id.'/progress') }}" class="ui-chip {{ $filter ? '' : 'is-active' }}">{{ __('progress.teacher.filter_all') }}</a>
            <a href="{{ url('/manage/courses/'.$course->id.'/progress?filter=inactive_7d') }}" class="ui-chip {{ $filter === 'inactive_7d' ? 'is-active' : '' }}">{{ __('progress.teacher.filter_inactive') }}</a>
            <a href="{{ url('/manage/courses/'.$course->id.'/progress?filter=not_started') }}" class="ui-chip {{ $filter === 'not_started' ? 'is-active' : '' }}">{{ __('progress.teacher.filter_not_started') }}</a>

            <span style="flex: 1;"></span>

            <a href="{{ url('/manage/courses/'.$course->id.'/progress?sort=progress') }}" class="ui-chip {{ $sort === 'progress' ? 'is-active' : '' }}">{{ __('progress.teacher.sort_progress') }}</a>
            <a href="{{ url('/manage/courses/'.$course->id.'/progress?sort=last_active') }}" class="ui-chip {{ $sort === 'last_active' ? 'is-active' : '' }}">{{ __('progress.teacher.sort_last_active') }}</a>
        </div>
    </div>

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('progress.teacher.student') }}</th>
                    <th scope="col">{{ __('progress.teacher.contact') }}</th>
                    <th scope="col">{{ __('progress.teacher.enrolled_since') }}</th>
                    <th scope="col">{{ __('progress.teacher.lessons') }}</th>
                    <th scope="col">{{ __('progress.teacher.last_active') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('progress.teacher.view') }}</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($enrolments as $enrolment)
                @php
                    $completed = (int) ($enrolment->completed_count ?? 0);
                    $percent = $totalLessons > 0 ? (int) round($completed / $totalLessons * 100) : 0;
                @endphp
                <tr data-student-row>
                    <td>
                        <span style="font-weight: 650;">{{ $enrolment->user->name }}</span>
                        @if ($enrolment->status->value !== 'active')
                            <x-badge tone="warning" style="margin-left: 0.375rem;">{{ __('progress.teacher.access_ended') }}</x-badge>
                        @endif
                    </td>
                    <td>{{ $enrolment->user->phone ?? $enrolment->user->email }}</td>
                    <td>{{ $enrolment->starts_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                    <td style="min-width: 11rem;">
                        <span style="font-variant-numeric: tabular-nums;">{{ __('progress.teacher.lessons', ['completed' => $completed, 'total' => $totalLessons]) }}</span>
                        <div class="ui-progress" style="margin-top: 0.375rem; width: 7rem;">
                            <span style="width: {{ $percent }}%"></span>
                        </div>
                    </td>
                    <td>{{ $enrolment->last_activity_at ? \Illuminate\Support\Carbon::parse($enrolment->last_activity_at)->diffForHumans() : __('progress.teacher.never') }}</td>
                    <td>
                        <x-link href="{{ url('/manage/courses/'.$course->id.'/progress/'.$enrolment->user_id) }}" variant="ghost" size="sm">{{ __('progress.teacher.view') }}</x-link>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color: var(--ink-subtle);">{{ __('progress.teacher.no_students') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $enrolments->links() }}
@endsection
