@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.dashboard.welcome').', '.$user->name" />

    <div class="flex flex-wrap gap-2">
        <a href="{{ url('/manage/courses') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
            {{ __('nav.courses') }}
        </a>
        <a href="{{ url('/users') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
            {{ __('users.dashboard.manage_people') }}
        </a>
    </div>
@endsection
