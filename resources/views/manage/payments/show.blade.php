@extends('layouts.app')

@section('content')
    <x-page-header :title="__('payments.review.heading')" :subtitle="$payment->enrolment?->course?->title">
        <x-slot:actions>
            <x-link href="{{ url('/manage/payments') }}" variant="ghost">
                <x-icon name="arrow-left" :size="16" />
                {{ __('payments.review.back_to_queue') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    @if (session('payment_notice'))
        <x-alert tone="success">{{ session('payment_notice') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert tone="danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alert>
    @endif

    {{-- The evidence first. Signed, short-lived and only rendered when PaymentPolicy --}}
    {{-- says this viewer may see it — the file itself never sits on a public path. --}}
    <div class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('payments.review.screenshot') }}</h2>
        </div>

        <img
            src="{{ $screenshotUrl }}"
            alt="{{ __('payments.review.screenshot') }}"
            style="display: block; max-width: 100%; height: auto; border-radius: 12px;"
        >
    </div>

    <div class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        <div style="display: grid; gap: 1rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
            <div>
                <span class="ui-label">{{ __('payments.review.student') }}</span>
                <p style="margin: 0.25rem 0 0;">{{ $payment->enrolment?->user?->name }}</p>
            </div>

            <div>
                <span class="ui-label">{{ __('payments.review.course') }}</span>
                <p style="margin: 0.25rem 0 0;">{{ $payment->enrolment?->course?->title }}</p>
            </div>

            <div>
                <span class="ui-label">{{ __('payments.review.amount') }}</span>
                <p style="margin: 0.25rem 0 0;">{{ __('payments.amount_format', ['amount' => number_format($payment->amount_paise / 100, 2)]) }}</p>
            </div>

            <div>
                <span class="ui-label">{{ __('payments.review.submitted') }}</span>
                <p style="margin: 0.25rem 0 0;">{{ $payment->submitted_at?->timezone('Asia/Kolkata')->format('d M Y, H:i') }}</p>
            </div>

            <div>
                <span class="ui-label">{{ __('payments.review.status') }}</span>
                <p style="margin: 0.375rem 0 0;">
                    <x-badge :tone="$payment->status->value === 'approved' ? 'success' : ($payment->status->value === 'rejected' ? 'danger' : 'warning')">
                        {{ __('payments.status.'.$payment->status->value) }}
                    </x-badge>
                </p>
            </div>

            <div>
                <span class="ui-label">{{ __('payments.review.upi_reference') }}</span>
                <p style="margin: 0.25rem 0 0;">{{ $payment->upi_reference ?? __('payments.review.no_upi_reference') }}</p>
            </div>
        </div>

        @if ($payment->rejection_reason)
            <p class="ui-subtle" style="margin: 1rem 0 0;">
                <span class="ui-label">{{ __('payments.review.reason') }}</span>
                <span style="display: block; margin-top: 0.25rem;">{{ $payment->rejection_reason }}</span>
            </p>
        @endif
    </div>

    {{-- Decisions are the owner's. Staff land on this same page and see the same --}}
    {{-- evidence, but neither control below is rendered for them. --}}
    @can('approvePayment', $payment)
        <div class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
            <form method="POST" action="{{ url('/manage/payments/'.$payment->id.'/approve') }}">
                @csrf
                <x-button>{{ __('payments.review.approve') }}</x-button>
            </form>

            <form
                method="POST"
                action="{{ url('/manage/payments/'.$payment->id.'/reject') }}"
                onsubmit="return confirm(@js(__('payments.review.confirm_reject')))"
                style="margin-top: 1.5rem;"
            >
                @csrf

                <x-field
                    name="rejection_reason"
                    type="textarea"
                    :label="__('payments.review.reason')"
                    :hint="__('payments.review.reason_hint')"
                />

                <x-button variant="danger">{{ __('payments.review.reject') }}</x-button>
            </form>
        </div>
    @endcan
@endsection
