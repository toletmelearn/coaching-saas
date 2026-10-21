@extends('layouts.app')

@section('content')
@php
    $fiduciary = $tenant ? $tenant->name : config('app.name', 'Coaching SaaS');
    $platform  = config('app.name', 'Coaching SaaS');
@endphp
<div class="ui-shell" style="padding-block: 2.5rem;">
    <div style="max-width: 46rem;">

        <h1 class="ui-h1" style="margin-bottom: 0.25rem;">Privacy Notice</h1>
        <p style="font-size: 0.875rem; color: var(--ink-subtle); margin-bottom: 2rem;">
            Notice version {{ $noticeVersion }}
            &nbsp;·&nbsp; Governed by the Digital Personal Data Protection Act, 2023 (India)
        </p>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Who this notice is for</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                This notice explains how <strong>{{ $fiduciary }}</strong> collects and uses the personal
                data of students enrolled on this platform.
                @if ($tenant)
                    {{ $tenant->name }} is the <em>Data Fiduciary</em> for its students' data under the
                    DPDP Act — it decides what data is collected and why.
                    {{ $platform }} operates the platform as a <em>Data Processor</em> on the institute's
                    behalf.
                @else
                    Each coaching institute that uses {{ $platform }} is the <em>Data Fiduciary</em> for
                    its own students' data. {{ $platform }} operates as a <em>Data Processor</em> — it
                    processes personal data on each institute's behalf and under their instructions.
                @endif
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">What we collect and why</h2>
            <p style="line-height: 1.7; color: var(--ink-text); margin-bottom: 1rem;">
                We collect personal data only for the following four purposes, all of which require
                consent before a student account is created:
            </p>
            <div class="ui-card" style="padding: 1.25rem; display: grid; gap: 1rem;">
                @foreach (['course_delivery', 'progress_tracking', 'communication', 'media_processing'] as $purpose)
                    <div>
                        <strong>{{ __('consents.purposes.'.$purpose) }}</strong>
                        <p style="margin: 0.25rem 0 0; font-size: 0.9375rem; color: var(--ink-text); line-height: 1.6;">
                            {{ __('consents.purpose_descriptions.'.$purpose) }}
                        </p>
                    </div>
                @endforeach
            </div>
            <p style="margin-top: 1rem; line-height: 1.7; color: var(--ink-text);">
                We also collect device information (browser type, IP address, session identifiers) for
                login security and device-limit enforcement, and payment records (amount, date, UPI
                reference) for fee tracking. These are necessary to deliver and protect the service.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">How long we keep it</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Student records are kept for as long as the student is enrolled and for a reasonable
                period afterwards to handle queries and meet any legal retention obligations (such as
                payment records required for tax purposes). If you request erasure, we will anonymise
                the student record and delete lesson-progress and device data. Consent records are
                retained as the audit trail even after erasure.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Who we share it with</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                Student data is not sold, rented, or shared for advertising. It is used only to deliver
                the services described above within the enrolled institute. Cloud infrastructure
                providers (hosting, storage, video delivery) process data on our behalf under
                contractual data-processing terms. No cross-institute sharing occurs.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Guardian consent for students under 18</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                The DPDP Act requires verifiable parental or guardian consent before processing the
                personal data of anyone under 18. A student account cannot be created until the
                institute records that a guardian has consented to all four purposes above. The guardian
                can withdraw any purpose at any time through the institute; withdrawal for
                <em>course delivery</em> effectively ends access to the course.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Your rights under the DPDP Act</h2>
            <div style="display: grid; gap: 0.75rem; line-height: 1.7; color: var(--ink-text);">
                <div>
                    <strong>Right to access</strong> — you can request a copy of the personal data held
                    about you (or your child).
                </div>
                <div>
                    <strong>Right to erasure</strong> — you can request that your personal data be deleted,
                    subject to legally required retention periods.
                </div>
                <div>
                    <strong>Right to withdraw consent</strong> — you can withdraw consent for any of the
                    four purposes at any time. Withdrawal does not affect processing already carried out.
                </div>
                <div>
                    <strong>Right to grievance redressal</strong> — if you believe your rights have not been
                    honoured, you may lodge a complaint with the Data Protection Board of India once it is
                    operational.
                </div>
            </div>
            <p style="margin-top: 0.875rem; line-height: 1.7; color: var(--ink-text);">
                To exercise any of these rights, contact the institute directly. The institute's owner
                or staff can act on your request from the student management screen.
            </p>
        </section>

        <section style="margin-bottom: 2rem;">
            <h2 class="ui-h2" style="margin-bottom: 0.625rem;">Updates to this notice</h2>
            <p style="line-height: 1.7; color: var(--ink-text);">
                When this notice is updated, the version identifier above changes. Continued use after
                a notice update constitutes acceptance of the new version.
            </p>
        </section>

        <p style="font-size: 0.8125rem; color: var(--ink-subtle);">
            <a href="{{ url('/terms') }}" style="color: inherit;">Terms of Service</a>
            &nbsp;·&nbsp; Version {{ $noticeVersion }}
        </p>

    </div>
</div>
@endsection
