@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.index.heading')" />

    <ul class="ui-list ui-fade">
        @forelse ($courses as $course)
            <li>
                <div class="ui-list-item">
                    <div class="min-w-0 flex-1">
                        <a href="{{ url('/courses/'.$course->slug) }}" class="ui-h2" style="display: block; text-decoration: none;">
                            {{ $course->title }}
                        </a>

                        <p class="ui-subtle" style="margin-top: 0.125rem;">
                            @if ($course->class_level)
                                {{ $course->class_level }}
                            @endif
                            @if ($course->class_level && $course->subject)
                                ·
                            @endif
                            @if ($course->subject)
                                {{ $course->subject }}
                            @endif
                            @if ($course->class_level || $course->subject)
                                ·
                            @endif
                            {{ __('courses.index.lessons_count', ['count' => $course->lessons_count]) }}
                        </p>
                    </div>

                    <span class="ui-empty-icon" aria-hidden="true" style="flex: none;">
                        <x-icon name="arrow-right" :size="18" />
                    </span>
                </div>
            </li>
        @empty
            <li>
                <div class="ui-empty" style="border: 0;">
                    <span class="ui-empty-icon"><x-icon name="book" :size="20" /></span>
                    <p class="ui-h2" style="margin: 0;">{{ __('courses.index.empty') }}</p>
                </div>
            </li>
        @endforelse
    </ul>

    {{ $courses->links() }}
@endsection
