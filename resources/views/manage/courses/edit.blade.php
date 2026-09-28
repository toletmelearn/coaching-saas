@extends('layouts.app')

@section('content')
    <h1>{{ $course->title }}</h1>
    <p>{{ __('courses.manage.status') }}: {{ $course->status->value }}</p>

    @can('publish', $course)
        <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/publish') }}">
            @csrf
            <button type="submit">{{ __('courses.manage.publish') }}</button>
        </form>
        <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/unpublish') }}">
            @csrf
            <button type="submit">{{ __('courses.manage.unpublish') }}</button>
        </form>
    @endcan

    @can('archive', $course)
        <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/archive') }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
            @csrf
            <button type="submit">{{ __('courses.manage.archive') }}</button>
        </form>
    @endcan

    @can('manageEnrolments', $course)
        <p><a href="{{ url('/manage/courses/'.$course->id.'/enrolments') }}">{{ __('courses.manage.enrolments') }}</a></p>
    @endcan

    @forelse ($course->chapters as $chapter)
        <section>
            <h2>{{ $chapter->title }}</h2>

            <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/move-up') }}">
                @csrf
                <button type="submit">↑</button>
            </form>
            <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/move-down') }}">
                @csrf
                <button type="submit">↓</button>
            </form>
            <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id) }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
                @csrf
                @method('DELETE')
                <button type="submit">{{ __('courses.manage.delete') }}</button>
            </form>

            <ul>
                @foreach ($chapter->lessons as $lesson)
                    <li>
                        <strong>{{ $lesson->title }}</strong> ({{ $lesson->status->value }})
                        @if ($lesson->board_tag) — {{ $lesson->board_tag }} @endif

                        <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/move-up') }}">
                            @csrf
                            <button type="submit">↑</button>
                        </form>
                        <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/move-down') }}">
                            @csrf
                            <button type="submit">↓</button>
                        </form>

                        @if ($lesson->status->value === 'published')
                            <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/unpublish') }}">
                                @csrf
                                <button type="submit">{{ __('courses.manage.unpublish') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/publish') }}">
                                @csrf
                                <button type="submit">{{ __('courses.manage.publish') }}</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id) }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit">{{ __('courses.manage.delete') }}</button>
                        </form>

                        <h4>{{ __('lessons.notes') }}</h4>
                        <ul>
                            @foreach ($lesson->attachments as $attachment)
                                <li>
                                    {{ $attachment->original_name }}
                                    <form method="POST" action="{{ url('/manage/attachments/'.$attachment->id) }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit">{{ __('courses.manage.delete') }}</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                        <form method="POST" action="{{ url('/manage/lessons/'.$lesson->id.'/attachments') }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept="application/pdf">
                            <button type="submit">{{ __('lessons.open_note') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ url('/manage/chapters/'.$chapter->id.'/lessons') }}">
                @csrf
                <label>{{ __('courses.manage.title') }}</label>
                <input type="text" name="title">
                <label>
                    <input type="checkbox" name="is_free_preview" value="1"> {{ __('lessons.video_coming_soon') }}
                </label>
                <label>YouTube URL</label>
                <input type="text" name="youtube_url">
                <label>Board tag</label>
                <input type="text" name="board_tag">
                <button type="submit">{{ __('courses.manage.add_lesson') }}</button>
            </form>
        </section>
    @empty
        <p>{{ __('courses.manage.no_chapters_yet') }}</p>
    @endforelse

    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/chapters') }}">
        @csrf
        <label>{{ __('courses.manage.title') }}</label>
        <input type="text" name="title">
        <button type="submit">{{ __('courses.manage.add_chapter') }}</button>
    </form>
@endsection
