@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.heading')">
        <x-slot:actions>
            @can('create', \App\Models\Course::class)
                <a href="{{ url('/manage/courses/create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
                    {{ __('courses.manage.create') }}
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <table class="w-full text-sm">
        <tbody>
        @forelse ($courses as $course)
            <tr class="border-b border-gray-100">
                <td class="py-2 pr-2"><a href="{{ url('/manage/courses/'.$course->id) }}">{{ $course->title }}</a></td>
                <td class="py-2 pr-2">{{ __('courses.manage.status') }}: {{ $course->status->value }}</td>
            </tr>
        @empty
            <tr><td class="py-2">{{ __('courses.index.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $courses->links() }}
@endsection
