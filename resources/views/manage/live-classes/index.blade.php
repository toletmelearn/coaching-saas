@extends('layouts.app')

@section('content')
    <x-page-header :title="__('live_classes.manage.heading')" :subtitle="$course->title">
        <x-slot:actions>
            <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes/create') }}" variant="primary" size="sm">
                {{ __('live_classes.manage.schedule') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    <div class="ui-section-title">
        <h2 class="ui-h2">{{ __('live_classes.manage.upcoming_section') }}</h2>
    </div>

    <ul class="ui-list ui-fade mb-6">
        @forelse ($upcoming as $liveClass)
            <li>
                <div class="ui-list-item" style="align-items: flex-start;">
                    <div class="min-w-0 flex-1">
                        <p class="ui-h2" style="margin: 0;">{{ $liveClass->title }}</p>
                        <p class="ui-subtle" style="margin-top: 0.125rem;">
                            {{ \App\Support\LiveClasses\IstDateTime::display($liveClass->starts_at) }}
                        </p>
                        <p class="ui-subtle" style="margin-top: 0.125rem;">
                            {{ __('live_classes.status.'.$liveClass->status->value) }}
                        </p>
                    </div>
                    <div style="flex: none; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                        <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/edit') }}" size="sm">
                            {{ __('live_classes.manage.edit') }}
                        </x-link>
                        <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/attendance') }}" size="sm">
                            {{ __('live_classes.manage.attendance') }}
                        </x-link>
                        <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/cancel') }}"
                              onsubmit="return confirm('{{ __('live_classes.manage.confirm_cancel') }}')">
                            @csrf
                            <button type="submit" class="ui-btn ui-btn-ghost ui-btn-sm">{{ __('live_classes.manage.cancel_class') }}</button>
                        </form>
                    </div>
                </div>
            </li>
        @empty
            <li>
                <div class="ui-empty" style="border: 0;">
                    <p class="ui-h2" style="margin: 0;">{{ __('live_classes.manage.empty') }}</p>
                </div>
            </li>
        @endforelse
    </ul>

    @if ($past->isNotEmpty())
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('live_classes.manage.past') }}</h2>
        </div>

        <ul class="ui-list ui-fade mb-6">
            @foreach ($past as $liveClass)
                <li>
                    <div class="ui-list-item">
                        <div class="min-w-0 flex-1">
                            <span style="font-weight: 550;">{{ $liveClass->title }}</span>
                            <p class="ui-subtle" style="margin-top: 0.125rem;">
                                {{ \App\Support\LiveClasses\IstDateTime::display($liveClass->starts_at) }}
                                · {{ __('live_classes.status.'.$liveClass->status->value) }}
                            </p>
                        </div>
                        <div style="flex: none; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                            <x-link href="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id.'/attendance') }}" size="sm">
                                {{ __('live_classes.manage.attendance') }}
                            </x-link>
                            <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/live-classes/'.$liveClass->id) }}"
                                  onsubmit="return confirm('{{ __('live_classes.manage.confirm_delete') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ui-btn ui-btn-ghost ui-btn-sm">{{ __('live_classes.manage.delete') }}</button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <x-link href="{{ url('/manage/courses/'.$course->id) }}" variant="ghost" size="sm">
        {{ __('live_classes.manage.back') }}
    </x-link>
@endsection
