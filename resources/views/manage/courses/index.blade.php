@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.heading')">
        <x-slot:actions>
            @can('create', \App\Models\Course::class)
                <x-link href="{{ url('/manage/courses/create') }}" variant="primary">
                    <x-icon name="plus" :size="17" />
                    {{ __('courses.manage.create') }}
                </x-link>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto ui-table-wrap ui-fade">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('courses.manage.title') }}</th>
                    <th scope="col">{{ __('courses.manage.status') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($courses as $course)
                <tr>
                    <td>
                        <a href="{{ url('/manage/courses/'.$course->id) }}" class="ui-link" style="font-weight: 650;">{{ $course->title }}</a>
                    </td>
                    <td>
                        @php $status = __('courses.manage.course_statuses.'.$course->status->value); @endphp
                        <x-badge :tone="$course->status->value === 'published' ? 'success' : 'neutral'">{{ $status }}</x-badge>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" style="color: var(--ink-subtle);">{{ __('courses.index.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $courses->links() }}
@endsection
