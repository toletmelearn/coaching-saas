@extends('layouts.app')

@section('content')
    <h1>{{ __('courses.manage.heading') }}</h1>

    @can('create', \App\Models\Course::class)
        <p><a href="{{ url('/manage/courses/create') }}">{{ __('courses.manage.create') }}</a></p>
    @endcan

    <table>
        <tbody>
        @forelse ($courses as $course)
            <tr>
                <td><a href="{{ url('/manage/courses/'.$course->id) }}">{{ $course->title }}</a></td>
                <td>{{ __('courses.manage.status') }}: {{ $course->status->value }}</td>
            </tr>
        @empty
            <tr><td>{{ __('courses.index.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $courses->links() }}
@endsection
