@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.edit_lesson').': '.$lesson->title" />

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id) }}">
        @csrf
        @method('PATCH')

        <x-field name="title" :label="__('courses.manage.title')" :value="$lesson->title" required />
        <x-field name="description" type="textarea" :label="__('courses.manage.description')" :value="$lesson->description" />
        <x-field name="board_tag" :label="__('courses.manage.board_tag')" :value="$lesson->board_tag" />

        <x-checkbox name="is_free_preview" :label="__('courses.manage.free_preview_label')" :checked="$lesson->is_free_preview" id="is_free_preview_toggle" />

        <div id="youtube_url_field" class="{{ $lesson->is_free_preview ? '' : 'hidden' }}">
            <x-field name="youtube_url" :label="__('courses.manage.youtube_url')" :value="null" />
        </div>

        <x-button>{{ __('users.create.submit') }}</x-button>
    </form>

    <script>
        (function () {
            var checkbox = document.getElementById('is_free_preview_toggle');
            var field = document.getElementById('youtube_url_field');
            if (!checkbox || !field) return;
            checkbox.addEventListener('change', function () {
                field.classList.toggle('hidden', !checkbox.checked);
            });
        })();
    </script>

    <h2 class="text-lg font-semibold mt-8 mb-2">{{ __('courses.manage.notes') }}</h2>

    @if (session('attachment_uploaded'))
        <p class="mb-2 text-green-700">{{ __('courses.manage.attachment_uploaded', ['name' => session('attachment_uploaded')]) }}</p>
    @endif

    <ul class="mb-4 divide-y divide-gray-100">
        @forelse ($lesson->attachments as $attachment)
            <li class="py-2 flex items-center justify-between gap-2">
                <span>{{ $attachment->original_name }}</span>
                <form method="POST" action="{{ url('/manage/attachments/'.$attachment->id) }}" onsubmit="return confirm(@js(__('courses.manage.confirm_delete')))">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger" class="!min-h-[36px] !py-0 !px-2 text-sm">{{ __('courses.manage.delete') }}</x-button>
                </form>
            </li>
        @empty
            <li class="py-2 text-gray-500">{{ __('lessons.no_notes') }}</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/attachments') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
        @csrf
        <input type="file" name="file" accept="application/pdf">
        <x-button>{{ __('courses.manage.upload_note') }}</x-button>
    </form>

    <div class="mt-8">
        <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id) }}" onsubmit="return confirm(@js(__('courses.manage.confirm_delete')))">
            @csrf
            @method('DELETE')
            <x-button variant="danger">{{ __('courses.manage.delete') }}</x-button>
        </form>
    </div>
@endsection
