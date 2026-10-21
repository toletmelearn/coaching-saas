@extends('layouts.app')

@section('content')
@php
    $fiduciary = $tenant ? $tenant->name : config('app.name', 'Coaching SaaS');
@endphp
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">{{ __('legal.nav_privacy') }}</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 0.5rem;">
            {{ __('legal.version_label', ['version' => $noticeVersion]) }}
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

        {{-- Withdraw consent CTA — tenant domain only --}}
        @if ($tenant)
        <section style="margin-bottom: 2rem; padding: 1.125rem 1.25rem; background: var(--canvas-raised); border-radius: 0.5rem; border: 1px solid var(--brand-line);">
            <h2 class="ui-h2" style="margin-bottom: 0.5rem; font-size: 1rem;">{{ __('legal.withdraw_heading') }}</h2>
            <p style="font-size: 0.9375rem; color: var(--ink-text); margin-bottom: 0.875rem; line-height: 1.6;">
                You can withdraw consent for any of the four data-processing purposes at any time.
                Withdrawal is processed by {{ $fiduciary }}.
            </p>
            <a href="{{ url('/consents/withdraw') }}" class="ui-btn ui-btn-primary" style="font-size: 0.9375rem;">
                {{ __('legal.withdraw_cta') }}
            </a>
        </section>
        @endif

        {{-- Data inventory table (rendered from config/privacy.php) --}}
        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.75rem;">{{ __('legal.data_inv_title') }}</h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem; line-height: 1.5;">
                    <thead>
                        <tr style="background: var(--canvas-raised); text-align: left;">
                            <th style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line);">{{ __('legal.col_category') }}</th>
                            <th style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line);">{{ __('legal.col_fields') }}</th>
                            <th style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line);">{{ __('legal.col_purposes') }}</th>
                            <th style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line);">{{ __('legal.col_retention') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dataCategories as $slug => $cat)
                        <tr>
                            <td style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line); vertical-align: top; font-weight: 600;">
                                {{ __('legal.data_cat.'.$slug) }}
                            </td>
                            <td style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line); vertical-align: top; color: var(--ink-text);">
                                {{ implode(', ', $cat['fields']) }}
                            </td>
                            <td style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line); vertical-align: top; color: var(--ink-text);">
                                {{ implode(', ', array_map(fn($p) => __('consents.purposes.'.$p), $cat['purposes'])) }}
                            </td>
                            <td style="padding: 0.5rem 0.75rem; border: 1px solid var(--brand-line); vertical-align: top; color: var(--ink-text);">
                                {{ $cat['retention'] ?? __('legal.retention_tbco') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Full versioned notice text — authoritative record for Consent::NOTICE_VERSION --}}
        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.375rem;">
                {{ __('legal.notice_text_heading', ['version' => $noticeVersion]) }}
            </h2>
            <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 0.75rem;">
                The text below is the authoritative record for consent rows carrying
                notice_version&nbsp;=&nbsp;{{ $noticeVersion }}.
            </p>
            @if ($noticeText)
                <div class="ui-card" style="padding: 1.25rem;">
                    <pre style="white-space: pre-wrap; font-family: inherit; font-size: 0.875rem; line-height: 1.7; color: var(--ink-text); margin: 0;">{{ $noticeText }}</pre>
                </div>
            @else
                <div class="ui-alert" role="alert">
                    {{ __('legal.notice_file_missing', ['version' => $noticeVersion]) }}
                </div>
            @endif
        </section>

        <p style="font-size: 0.8125rem; color: var(--ink-subtle);">
            <a href="{{ url('/terms') }}" style="color: inherit;">{{ __('legal.nav_terms') }}</a>
            &nbsp;·&nbsp; Version {{ $noticeVersion }}
        </p>

    </div>
</div>
@endsection
