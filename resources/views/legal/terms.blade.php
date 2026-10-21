@extends('layouts.app')

@section('content')
@php
    $platform = config('app.name', 'Coaching SaaS');
@endphp
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">Terms of Service</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 2rem;">
            Effective October 2026 &nbsp;·&nbsp; Governing law: India
        </p>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">What {{ $platform }} is</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                {{ $platform }} is a software platform that coaching institutes use to deliver online
                courses, manage students, and collect fees. Each institute operates its own
                subdomain and is responsible for the content it publishes and the students it enrols.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">For coaching institutes</h2>
            <div style="display: grid; gap: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                <p>
                    <strong>Subscription.</strong> Access to the platform requires an active
                    subscription. The institute owner is responsible for keeping the subscription
                    current. The platform operator may suspend access for non-payment or for breach
                    of these terms.
                </p>
                <p>
                    <strong>Data responsibility.</strong> The institute is the Data Fiduciary for its
                    students' personal data. It is responsible for collecting valid consent, responding
                    to student rights requests, and complying with the DPDP Act 2023. The platform
                    processes data only on the institute's instructions.
                </p>
                <p>
                    <strong>Content.</strong> The institute is solely responsible for all course
                    content, materials, and communications it publishes. Content must not infringe
                    third-party intellectual property, be unlawful, or be misleading to students.
                </p>
                <p>
                    <strong>Acceptable use.</strong> The platform must not be used to distribute
                    malware, conduct phishing, harass users, circumvent payment obligations, or
                    otherwise violate applicable law.
                </p>
            </div>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">For students</h2>
            <div style="display: grid; gap: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                <p>
                    <strong>Access.</strong> Your account gives you access to the courses you are
                    enrolled in by your institute. Access is personal — you may not share login
                    credentials or allow others to use your account.
                </p>
                <p>
                    <strong>Device limits.</strong> For security and fair-use reasons, concurrent
                    logins may be limited. If your device access is unexpectedly restricted, contact
                    your institute.
                </p>
                <p>
                    <strong>Conduct.</strong> You must not attempt to download, copy, or redistribute
                    course content beyond what is explicitly permitted, exploit technical
                    vulnerabilities, or disrupt the service for other users.
                </p>
            </div>
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
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Service availability</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The platform is provided on a best-efforts basis. We do not guarantee uninterrupted
                access. We may take the service offline briefly for maintenance and will give
                reasonable notice where possible. We are not liable for losses arising from
                downtime.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Limitation of liability</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                To the maximum extent permitted by applicable law, the platform operator is not
                liable for indirect, incidental, or consequential damages arising from use of the
                platform. The operator's total liability for any claim is limited to the fees paid
                in the three months preceding the event giving rise to the claim.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Governing law</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                These terms are governed by the laws of India. Any disputes shall be subject to the
                exclusive jurisdiction of the courts of India.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Changes to these terms</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                We may update these terms from time to time. Material changes will be communicated to
                institute owners at least 14 days before they take effect. Continued use after the
                effective date constitutes acceptance.
            </p>
        </section>

        <p style="font-size: 0.8125rem; color: var(--ink-subtle);">
            <a href="{{ url('/privacy') }}" style="color: inherit;">Privacy Notice</a>
        </p>

    </div>
</div>
@endsection
