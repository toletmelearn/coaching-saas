@extends('layouts.app')

@section('content')
    <h1>{{ $lesson->title }}</h1>

    @if ($enrolment && $enrolment->ends_at && now()->gte($enrolment->ends_at))
        <p>{{ __('lessons.forbidden.access_ended', ['date' => $enrolment->ends_at->timezone('Asia/Kolkata')->format('d M Y')]) }}</p>
    @else
        <p>{{ __('lessons.forbidden.not_enrolled') }}</p>
    @endif
@endsection
