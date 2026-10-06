@extends('layouts.app')

@section('content')
    <x-page-header :title="$course->title" />

    <p class="mb-4 text-sm text-gray-600">{{ __('courses.manage.status') }}: {{ __('courses.manage.course_statuses.'.$course->status->value) }}</p>

    {{-- What a place on this course costs. The figure is only ever read server-side --}}
    {{-- when a payment is created, so nothing posted alongside it can change the price. --}}
    <div class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('courses.manage.fee_heading') }}</h2>
        </div>

        <form method="POST" action="{{ url('/manage/courses/'.$course->id) }}">
            @csrf
            @method('PATCH')

            <x-field
                name="fee"
                type="number"
                :label="__('courses.manage.fee_paise')"
                :hint="__('courses.manage.fee_paise_hint')"
                :value="$course->fee_paise !== null ? $course->fee_paise / 100 : ''"
            />

            <div class="mb-5">
                <label for="enrolment_duration" class="ui-label">{{ __('courses.manage.enrolment_duration') }}</label>
                <select name="enrolment_duration" id="enrolment_duration" class="ui-input">
                    <option value="">{{ __('courses.manage.no_default_duration') }}</option>
                    <option value="1_day" @selected(old('enrolment_duration', $course->enrolment_duration) === '1_day')>1 Day</option>
                    <option value="1_week" @selected(old('enrolment_duration', $course->enrolment_duration) === '1_week')>1 Week</option>
                    <option value="1_month" @selected(old('enrolment_duration', $course->enrolment_duration) === '1_month')>1 Month</option>
                    <option value="3_months" @selected(old('enrolment_duration', $course->enrolment_duration) === '3_months')>3 Months</option>
                    <option value="6_months" @selected(old('enrolment_duration', $course->enrolment_duration) === '6_months')>6 Months</option>
                    <option value="session" @selected(old('enrolment_duration', $course->enrolment_duration) === 'session')>Session (6 months)</option>
                    <option value="lifetime" @selected(old('enrolment_duration', $course->enrolment_duration) === 'lifetime')>Lifetime (no expiry)</option>
                </select>
            </div>

            <x-button variant="secondary">{{ __('courses.manage.save_fee') }}</x-button>
        </form>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        @can('publish', $course)
            @if ($course->status->value === 'published')
                <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/unpublish') }}">
                    @csrf
                    <x-button variant="secondary">{{ __('courses.manage.unpublish') }}</x-button>
                </form>
            @else
                <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/publish') }}">
                    @csrf
                    <x-button>{{ __('courses.manage.publish') }}</x-button>
                </form>
            @endif
        @endcan

        @can('archive', $course)
            <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/archive') }}" onsubmit="return confirm(@js(__('courses.manage.confirm_archive')))">
                @csrf
                <x-button variant="danger">{{ __('courses.manage.archive') }}</x-button>
            </form>
        @endcan

        @can('manageEnrolments', $course)
            <a href="{{ url('/manage/courses/'.$course->id.'/enrolments') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
                {{ __('courses.manage.enrolments') }}
            </a>
        @endcan

        @if ($course->status->value === 'published')
            <a href="{{ url('/courses/'.$course->slug) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
                {{ __('courses.manage.view_public_page') }}
            </a>
        @endif
    </div>

    @forelse ($course->chapters as $chapter)
        <section class="mb-6 border border-gray-200 rounded-md p-4">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h2 class="text-lg font-semibold">{{ $chapter->title }}</h2>
                <div class="flex gap-1">
                    <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/move-up') }}">
                        @csrf
                        <x-button variant="secondary" class="!min-h-0 !py-1 !px-2">↑</x-button>
                    </form>
                    <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/move-down') }}">
                        @csrf
                        <x-button variant="secondary" class="!min-h-0 !py-1 !px-2">↓</x-button>
                    </form>
                    <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id) }}" onsubmit="return confirm(@js(__('courses.manage.confirm_delete')))">
                        @csrf
                        @method('DELETE')
                        <x-button variant="danger" class="!min-h-0 !py-1 !px-2">{{ __('courses.manage.delete') }}</x-button>
                    </form>
                </div>
            </div>

            <ul class="divide-y divide-gray-100">
                @foreach ($chapter->lessons as $lesson)
                    <li class="py-2">
                        <div class="flex items-center justify-between gap-2">
                            <span>
                                {{ $lesson->title }}
                                <span class="ml-2 inline-block rounded-full px-2 py-0.5 text-xs {{ $lesson->status->value === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' }}">
                                    {{ __('courses.manage.lesson_statuses.'.$lesson->status->value) }}
                                </span>
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-1">
                            <a href="{{ url('/manage/lessons/'.$lesson->id.'/edit') }}" class="inline-flex items-center justify-center min-h-[36px] px-2 rounded-md text-sm bg-gray-100 text-gray-800 hover:bg-gray-200">{{ __('courses.manage.edit') }}</a>

                            <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/move-up') }}">
                                @csrf
                                <x-button variant="secondary" class="!min-h-[36px] !py-0 !px-2 text-sm">↑</x-button>
                            </form>
                            <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/move-down') }}">
                                @csrf
                                <x-button variant="secondary" class="!min-h-[36px] !py-0 !px-2 text-sm">↓</x-button>
                            </form>

                            @if ($lesson->status->value === 'published')
                                <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/unpublish') }}">
                                    @csrf
                                    <x-button variant="secondary" class="!min-h-[36px] !py-0 !px-2 text-sm">{{ __('courses.manage.unpublish') }}</x-button>
                                </form>
                            @else
                                <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/publish') }}">
                                    @csrf
                                    <x-button class="!min-h-[36px] !py-0 !px-2 text-sm">{{ __('courses.manage.publish') }}</x-button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/lessons') }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex-1 min-w-[10rem]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('courses.manage.title') }}</label>
                    <input type="text" name="title" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base" required>
                </div>
                <x-button>{{ __('courses.manage.add_lesson') }}</x-button>
            </form>
        </section>
    @empty
        <p class="mb-6">{{ __('courses.manage.no_chapters_yet') }}</p>
    @endforelse

    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/chapters') }}" class="flex flex-wrap items-end gap-2">
        @csrf
        <div class="flex-1 min-w-[10rem]">
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('courses.manage.title') }}</label>
            <input type="text" name="title" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base" required>
        </div>
        <x-button>{{ __('courses.manage.add_chapter') }}</x-button>
    </form>
@endsection
