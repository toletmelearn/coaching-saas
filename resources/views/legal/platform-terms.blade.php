@extends('layouts.app')

@section('content')
@php
    $platform = config('app.name', 'Coaching SaaS');
@endphp
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">Platform Terms of Service</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 2rem;">
            For coaching institutes using {{ $platform }}
            &nbsp;·&nbsp; Effective October 2026
            &nbsp;·&nbsp; Governing law: India
        </p>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">The service</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                {{ $platform }} is a software platform that coaching institutes use to deliver
                online courses, manage students, and collect fees. Each institute operates its
                own subdomain and is responsible for the content it publishes and the students
                it enrols.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                <em>Operated by:</em> [TO BE CONFIRMED BY OPERATOR: operator name, registered
                address, and company registration number]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Institute responsibilities</h2>
            <div style="display: grid; gap: 0.875rem; line-height: 1.7; color: var(--ink-text);">
                <p>
                    <strong>Data Fiduciary.</strong> Under the DPDP Act 2023, each institute is
                    the Data Fiduciary for its students' personal data. The institute is responsible
                    for collecting valid guardian consent (for students under 18), maintaining
                    consent records, responding to student rights requests, and complying with the
                    DPDP Act and Rules. The platform operates as Data Processor on the institute's
                    instructions.
                </p>
                <p>
                    <strong>Content.</strong> The institute is solely responsible for all course
                    content, materials, and communications it publishes. Content must not infringe
                    third-party intellectual property, be unlawful, or be misleading.
                </p>
                <p>
                    <strong>Acceptable use.</strong> The platform must not be used to distribute
                    malware, conduct phishing, harass users, circumvent payment obligations, or
                    otherwise violate applicable law.
                </p>
                <p>
                    <strong>Security.</strong> The institute is responsible for keeping admin
                    credentials secure and for promptly reporting any suspected breach to the
                    platform operator.
                </p>
            </div>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Subscription and payment</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Access to the platform requires an active subscription. The institute owner is
                responsible for keeping the subscription current. The platform operator may
                suspend access for non-payment or material breach of these terms.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                [TO BE CONFIRMED BY OPERATOR: pricing plans, billing cycle, trial period,
                refund policy, and payment method]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Service availability</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The platform is provided on a best-efforts basis. The operator does not guarantee
                uninterrupted access and may take the service offline briefly for maintenance.
                Reasonable advance notice will be given for planned maintenance where possible.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Intellectual property</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Course content belongs to the institute that published it. Platform software,
                trademarks, and design belong to the platform operator. Nothing in these terms
                transfers ownership of either.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Limitation of liability</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                To the maximum extent permitted by applicable law, the platform operator is not
                liable for indirect, incidental, or consequential damages arising from use of the
                platform.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                [TO BE CONFIRMED BY OPERATOR: liability cap amount or formula]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Termination</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Either party may terminate the subscription on written notice. The institute
                retains the right to export its data before termination. The operator may
                terminate immediately for material breach.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                [TO BE CONFIRMED BY OPERATOR: notice period for standard termination]
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Changes to these terms</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The operator may update these terms. Institute owners will be notified before
                material changes take effect. Continued use after the effective date constitutes
                acceptance.
            </p>
            <p style="margin-top: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                [TO BE CONFIRMED BY OPERATOR: advance notice period for term changes]
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
                [TO BE CONFIRMED BY OPERATOR: operator support email and dispute-resolution
                contact details]
            </p>
        </section>

        <p style="font-size: 0.8125rem; color: var(--ink-subtle);">
            <a href="{{ url('/privacy') }}" style="color: inherit;">{{ __('legal.nav_privacy') }}</a>
        </p>

    </div>
</div>
@endsection
