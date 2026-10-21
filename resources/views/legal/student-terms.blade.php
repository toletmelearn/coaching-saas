@extends('layouts.app')

@section('content')
@php
    $institute = $tenant ? $tenant->name : config('app.name', 'Coaching SaaS');
    $platform  = config('app.name', 'Coaching SaaS');
@endphp
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">Student Terms of Use</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 2rem;">
            {{ $institute }}
            &nbsp;·&nbsp; Effective October 2026
            &nbsp;·&nbsp; Governing law: India
        </p>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">What you are accessing</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                These terms govern your use of the online learning platform provided by
                <strong>{{ $institute }}</strong> through {{ $platform }}. By using the platform
                you agree to these terms. These terms apply between you (the student or their
                guardian) and the institute — not the platform operator.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Your account</h2>
            <div style="display: grid; gap: 0.875rem; line-height: 1.7; color: var(--ink-text);">
                <p>
                    <strong>Personal use only.</strong> Your account gives you access to the courses
                    you are enrolled in. You may not share your login credentials or allow anyone
                    else to use your account.
                </p>
                <p>
                    <strong>Device limits.</strong> For security and fair-use reasons, simultaneous
                    logins from too many devices may be restricted. Contact your institute if your
                    access is unexpectedly limited.
                </p>
                <p>
                    <strong>Account security.</strong> You are responsible for keeping your password
                    secure. Notify the institute immediately if you suspect unauthorised access.
                </p>
            </div>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Acceptable use</h2>
            <div style="display: grid; gap: 0.875rem; line-height: 1.7; color: var(--ink-text);">
                <p>
                    <strong>Course content.</strong> You may not download, copy, redistribute, or
                    share course materials beyond what the institute explicitly permits. All course
                    content belongs to the institute.
                </p>
                <p>
                    <strong>Platform conduct.</strong> You must not attempt to exploit technical
                    vulnerabilities, disrupt the service for other users, or use the platform for
                    any unlawful purpose.
                </p>
            </div>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Fees and payments</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Course fees and payment terms are set by the institute. For questions about fees,
                refunds, or payment disputes, contact the institute directly.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                [TO BE CONFIRMED BY OPERATOR: refund policy and fee-dispute process]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Suspension of access</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The institute may suspend or remove your access for breach of these terms,
                non-payment of applicable fees, or at the end of your enrolment period.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Your privacy</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The use of your personal data is governed by the institute's Privacy Notice.
            </p>
            <p style="margin-top: 0.5rem;">
                <a href="{{ url('/privacy') }}" style="color: var(--brand);">
                    {{ __('legal.nav_privacy') }}
                </a>
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Governing law</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                These terms are governed by the laws of India.
                [TO BE CONFIRMED BY OPERATOR: specific court jurisdiction]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Contact</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                For all queries about your account, courses, or these terms, contact
                <strong>{{ $institute }}</strong> directly.
            </p>
        </section>

    </div>
</div>
@endsection
