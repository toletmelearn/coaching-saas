@extends('layouts.app')

@section('content')
    <x-page-header :title="__('progress.teacher.heading').': '.$course->title" />

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ url('/manage/courses/'.$course->id.'/progress') }}" class="min-h-[36px] flex items-center px-2 rounded-md {{ $filter ? 'bg-gray-100 text-gray-700' : 'bg-indigo-100 text-indigo-800' }}">{{ __('progress.teacher.filter_all') }}</a>
        <a href="{{ url('/manage/courses/'.$course->id.'/progress?filter=inactive_7d') }}" class="min-h-[36px] flex items-center px-2 rounded-md {{ $filter === 'inactive_7d' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-700' }}">{{ __('progress.teacher.filter_inactive') }}</a>
        <a href="{{ url('/manage/courses/'.$course->id.'/progress?filter=not_started') }}" class="min-h-[36px] flex items-center px-2 rounded-md {{ $filter === 'not_started' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-700' }}">{{ __('progress.teacher.filter_not_started') }}</a>

        <span class="flex-1"></span>

        <a href="{{ url('/manage/courses/'.$course->id.'/progress?sort=progress') }}" class="min-h-[36px] flex items-center px-2 rounded-md {{ $sort === 'progress' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-700' }}">{{ __('progress.teacher.sort_progress') }}</a>
        <a href="{{ url('/manage/courses/'.$course->id.'/progress?sort=last_active') }}" class="min-h-[36px] flex items-center px-2 rounded-md {{ $sort === 'last_active' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-700' }}">{{ __('progress.teacher.sort_last_active') }}</a>
    </div>

    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b border-gray-200">
                    <th class="py-2 pr-2">{{ __('progress.teacher.student') }}</th>
                    <th class="py-2 pr-2">{{ __('progress.teacher.contact') }}</th>
                    <th class="py-2 pr-2">{{ __('progress.teacher.enrolled_since') }}</th>
                    <th class="py-2 pr-2">{{ __('progress.teacher.lessons') }}</th>
                    <th class="py-2 pr-2">{{ __('progress.teacher.last_active') }}</th>
                    <th class="py-2 pr-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($enrolments as $enrolment)
                @php
                    $completed = (int) ($enrolment->completed_count ?? 0);
                    $percent = $totalLessons > 0 ? (int) round($completed / $totalLessons * 100) : 0;
                @endphp
                <tr data-student-row class="border-b border-gray-100">
                    <td class="py-2 pr-2">
                        {{ $enrolment->user->name }}
                        @if ($enrolment->status->value !== 'active')
                            <span class="ml-1 inline-block rounded-full px-2 py-0.5 text-xs bg-gray-200 text-gray-700">{{ __('progress.teacher.access_ended') }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-2">{{ $enrolment->user->phone ?? $enrolment->user->email }}</td>
                    <td class="py-2 pr-2">{{ $enrolment->starts_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                    <td class="py-2 pr-2">
                        {{ __('progress.teacher.lessons', ['completed' => $completed, 'total' => $totalLessons]) }}
                        <div class="mt-1 h-1.5 w-24 rounded-full bg-gray-200">
                            <div class="h-1.5 rounded-full bg-indigo-600" style="width: {{ $percent }}%"></div>
                        </div>
                    </td>
                    <td class="py-2 pr-2">{{ $enrolment->last_activity_at ? \Illuminate\Support\Carbon::parse($enrolment->last_activity_at)->diffForHumans() : __('progress.teacher.never') }}</td>
                    <td class="py-2 pr-2">
                        <a href="{{ url('/manage/courses/'.$course->id.'/progress/'.$enrolment->user_id) }}" class="text-indigo-600">{{ __('progress.teacher.view') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td class="py-2" colspan="6">{{ __('progress.teacher.no_students') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $enrolments->links() }}
@endsection
