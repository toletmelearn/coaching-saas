@extends('layouts.app')

@section('content')
    <h1>{{ __('courses.manage.enrolments') }}: {{ $course->title }}</h1>

    @if (session('enrolment_summary'))
        <p>{{ session('enrolment_summary') }}</p>
    @endif

    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table>
        <tbody>
        @forelse ($enrolments as $enrolment)
            <tr>
                <td>{{ $enrolment->user->name }}</td>
                <td>{{ $enrolment->status->value }}</td>
                <td>{{ $enrolment->starts_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                <td>{{ $enrolment->ends_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                <td>{{ $enrolment->payment_note }}</td>
                <td>
                    @if ($enrolment->status->value === 'active')
                        <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/revoke') }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
                            @csrf
                            <button type="submit">{{ __('courses.manage.revoke') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/reenrol') }}">
                            @csrf
                            <button type="submit">{{ __('courses.manage.reenrol') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td>{{ __('courses.index.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $enrolments->links() }}

    <h2>{{ __('courses.manage.enrol_students') }}</h2>
    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/enrolments') }}">
        @csrf
        @foreach ($students as $student)
            <label>
                <input type="checkbox" name="user_ids[]" value="{{ $student->id }}">
                {{ $student->name }} ({{ $student->email ?? $student->phone }})
            </label>
        @endforeach

        <label>{{ __('courses.manage.starts_at') }}</label>
        <input type="date" name="starts_at" value="{{ old('starts_at', now()->toDateString()) }}">

        <label>{{ __('courses.manage.ends_at') }}</label>
        <input type="date" name="ends_at" value="{{ old('ends_at') }}">

        <label>{{ __('courses.manage.payment_note') }}</label>
        <input type="text" name="payment_note" value="{{ old('payment_note') }}">

        <button type="submit">{{ __('courses.manage.enrol_students') }}</button>
    </form>
@endsection
