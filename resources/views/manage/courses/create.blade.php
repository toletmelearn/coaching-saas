@extends('layouts.app')

@section('content')
    <h1>{{ __('courses.manage.create') }}</h1>

    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/manage/courses') }}">
        @csrf
        <label for="title">{{ __('courses.manage.title') }}</label>
        <input type="text" name="title" id="title" value="{{ old('title') }}">

        <label for="class_level">{{ __('courses.manage.class_level') }}</label>
        <input type="text" name="class_level" id="class_level" value="{{ old('class_level') }}">

        <label for="subject">{{ __('courses.manage.subject') }}</label>
        <input type="text" name="subject" id="subject" value="{{ old('subject') }}">

        <label for="description">{{ __('courses.manage.description') }}</label>
        <textarea name="description" id="description">{{ old('description') }}</textarea>

        <button type="submit">{{ __('courses.manage.create') }}</button>
    </form>
@endsection
