@extends('layouts.app')

@section('content')
    <x-page-header :title="__('import.heading')" />

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <p class="mb-4">
        <a href="{{ url('/users/import/template') }}" class="text-indigo-600 underline">{{ __('import.download_template') }}</a>
    </p>

    <form method="POST" action="{{ url('/users/import') }}" enctype="multipart/form-data">
        @csrf

        <div class="mb-4">
            <label for="file" class="block text-sm font-medium text-gray-700 mb-1">{{ __('import.choose_file') }}</label>
            <input type="file" name="file" id="file" accept=".csv" required class="block w-full text-base">
        </div>

        @if ($courses->isNotEmpty())
            <div class="mb-4">
                <label for="course_id" class="block text-sm font-medium text-gray-700 mb-1">{{ __('import.preview.course') }}</label>
                <select name="course_id" id="course_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base">
                    <option value="">—</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}">{{ $course->title }}</option>
                    @endforeach
                </select>
            </div>

            <x-field name="ends_at" type="date" :label="__('import.preview.ends_at')" />
            <x-field name="payment_note" :label="__('import.preview.payment_note')" />
        @endif

        <x-button>{{ __('import.submit') }}</x-button>
    </form>
@endsection
