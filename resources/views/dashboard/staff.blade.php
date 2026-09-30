@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.dashboard.welcome').', '.$user->name" />

    @if ($showGettingStarted)
        <div class="mb-6 rounded-md border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="font-semibold text-gray-900">{{ __('dashboard.getting_started.title') }}</h2>
                <form method="POST" action="{{ url('/manage/getting-started/dismiss') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 underline min-h-[44px]">{{ __('dashboard.getting_started.hide') }}</button>
                </form>
            </div>
            <ul class="space-y-2">
                @foreach ($checklist as $step)
                    <li class="flex items-center gap-2 text-sm">
                        <span aria-hidden="true">{{ $step['done'] ? '✅' : '⬜' }}</span>
                        @if ($step['done'])
                            <span class="text-gray-500 line-through">{{ $step['label'] }}</span>
                        @else
                            <a href="{{ url($step['link']) }}" class="text-indigo-600 underline">{{ $step['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        <a href="{{ url('/manage/courses') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
            {{ __('nav.courses') }}
        </a>
        <a href="{{ url('/users') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
            {{ __('users.dashboard.manage_people') }}
        </a>
    </div>
@endsection
