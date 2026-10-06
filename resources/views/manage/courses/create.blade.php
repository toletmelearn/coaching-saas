@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.create')" />

    <form method="POST" action="{{ url('/manage/courses') }}">
        @csrf

        <x-field name="title" :label="__('courses.manage.title')" required />
        <x-field name="class_level" :label="__('courses.manage.class_level')" />
        <x-field name="subject" :label="__('courses.manage.subject')" />
        <x-field name="description" type="textarea" :label="__('courses.manage.description')" />
        <x-field name="fee" type="number" :label="__('courses.manage.fee_paise')" :hint="__('courses.manage.fee_paise_hint')" />

        <div class="mb-5">
            <label for="enrolment_duration" class="ui-label">{{ __('courses.manage.enrolment_duration') }}</label>
            <select name="enrolment_duration" id="enrolment_duration" class="ui-input">
                <option value="">{{ __('courses.manage.no_default_duration') }}</option>
                <option value="1_day">1 Day</option>
                <option value="1_week">1 Week</option>
                <option value="1_month">1 Month</option>
                <option value="3_months">3 Months</option>
                <option value="6_months">6 Months</option>
                <option value="session">Session (6 months)</option>
                <option value="lifetime">Lifetime (no expiry)</option>
            </select>
        </div>

        <x-button>{{ __('courses.manage.create') }}</x-button>
    </form>
@endsection
