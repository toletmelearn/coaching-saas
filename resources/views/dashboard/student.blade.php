@extends('layouts.app')

@section('content')
    <x-page-header :title="__('nav.my_courses')" />

    <ul class="divide-y divide-gray-100 mb-6">
        @forelse ($active as $enrolment)
            <li class="py-2">
                <a href="{{ url('/courses/'.$enrolment->course->slug) }}">{{ $enrolment->course->title }}</a>
            </li>
        @empty
            <li class="py-2 text-gray-500">{{ __('courses.index.empty') }}</li>
        @endforelse
    </ul>

    @if ($ended->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ __('users.dashboard.access_ended') }}</h2>
        <ul class="divide-y divide-gray-100">
            @foreach ($ended as $enrolment)
                <li class="py-2 text-gray-500">{{ $enrolment->course->title }}</li>
            @endforeach
        </ul>
    @endif
@endsection
