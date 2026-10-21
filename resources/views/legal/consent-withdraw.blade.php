@extends('layouts.app')

@section('content')
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 36rem;">

        <h1 class="ui-h1" style="margin-bottom: 1.5rem;">{{ __('legal.withdraw_page_title') }}</h1>

        <div class="ui-card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
            <p style="line-height: 1.75; color: var(--ink-text); margin-bottom: 1rem;">
                You can withdraw consent for any of the four data-processing purposes
                (course delivery, progress tracking, communication, or photo and video processing)
                at any time. This does not affect any processing already carried out lawfully before
                the withdrawal.
            </p>
            <p style="line-height: 1.75; color: var(--ink-text); margin-bottom: 1rem;">
                Consent withdrawal is processed by the institute that manages your account.
                @if ($tenant)
                    Contact <strong>{{ $tenant->name }}</strong> directly and ask them to update
                    your consent record from the student management screen.
                @endif
            </p>
            <p style="line-height: 1.75; color: var(--ink-text);">
                Note: withdrawing the <em>course delivery</em> purpose ends access to enrolled courses.
            </p>
        </div>

        <p style="font-size: 0.875rem; color: var(--ink-subtle);">
            <a href="{{ url('/privacy') }}" style="color: inherit;">← {{ __('legal.nav_privacy') }}</a>
        </p>

    </div>
</div>
@endsection
