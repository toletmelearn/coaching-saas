@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.create')" />

    <form method="POST" action="{{ url('/manage/courses') }}">
        @csrf

        <x-field name="title" :label="__('courses.manage.title')" required />
        <x-field name="class_level" :label="__('courses.manage.class_level')" />
        <x-field name="subject" :label="__('courses.manage.subject')" />
        <x-field name="description" type="textarea" :label="__('courses.manage.description')" />

        <x-button>{{ __('courses.manage.create') }}</x-button>
    </form>
@endsection
