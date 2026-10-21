@extends('layouts.app')

@section('content')
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        {{-- Archive banner --}}
        <div class="ui-alert" role="note" style="margin-bottom: 1.5rem;">
            {{ __('legal.archive_banner', [
                'version' => $version,
                'link'    => '',
            ]) }}
            <a href="{{ url('/privacy') }}">{{ __('legal.archive_link') }}</a>
        </div>

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">{{ __('legal.nav_privacy') }}</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 0.5rem;">
            {{ __('legal.version_label', ['version' => $version]) }}
            &nbsp;·&nbsp; {{ __('legal.dpdp_label') }}
        </p>

        {{-- Lang toggle --}}
        @if ($hasHindi)
        <p style="font-size: 0.875rem; margin-bottom: 2rem;">
            @if ($locale === 'hi')
                <a href="{{ url()->current() }}?lang=en">{{ __('legal.lang_en') }}</a>
                &nbsp;·&nbsp; <strong>{{ __('legal.lang_hi') }}</strong>
            @else
                <strong>{{ __('legal.lang_en') }}</strong>
                &nbsp;·&nbsp; <a href="{{ url()->current() }}?lang=hi">{{ __('legal.lang_hi') }}</a>
            @endif
        </p>
        @else
        <div style="margin-bottom: 2rem;"></div>
        @endif

        <section style="margin-bottom: 2rem;">
            <div class="ui-card" style="padding: 1.25rem;">
                <pre style="white-space: pre-wrap; font-family: inherit; font-size: 0.875rem; line-height: 1.7; color: var(--ink-text); margin: 0;">{{ $noticeText }}</pre>
            </div>
        </section>

        <p style="font-size: 0.8125rem; color: var(--ink-subtle);">
            <a href="{{ url('/privacy') }}" style="color: inherit;">{{ __('legal.archive_link') }}</a>
            &nbsp;·&nbsp;
            <a href="{{ url('/terms') }}" style="color: inherit;">{{ __('legal.nav_terms') }}</a>
        </p>

    </div>
</div>
@endsection
