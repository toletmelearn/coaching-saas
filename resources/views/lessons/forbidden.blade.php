@extends('layouts.app')

@section('content')
    <div class="ui-rise" style="max-width: 36rem;">
        <x-page-header :title="$lesson->title" />

        <div class="ui-alert ui-alert-warning">
            <x-icon name="shield" :size="18" style="margin-top: 2px; flex: none;" />
            <div>
                @if ($enrolment && $enrolment->ends_at && now()->gte($enrolment->ends_at))
                    <p style="margin: 0;">{{ __('lessons.forbidden.access_ended', ['date' => $enrolment->ends_at->timezone('Asia/Kolkata')->format('d M Y')]) }}</p>
                @else
                    <p style="margin: 0;">{{ __('lessons.forbidden.not_enrolled') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
