@extends('layouts.app')

@section('content')
    <x-page-header :title="__('payments.page.heading')" :subtitle="$course?->title" />

    @if ($errors->any())
        <x-alert tone="danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alert>
    @endif

    {{-- Where the money goes. Rendered even when the course has no fee yet, so the --}}
    {{-- institute learns it still owes its students a UPI id rather than a blank page. --}}
    <div class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        @if ($upiId)
            <p class="ui-label" style="margin-bottom: 0.375rem;">{{ __('payments.page.upi_id') }}</p>
            <p id="payment-upi-id" class="ui-h2" style="margin-bottom: 0.75rem; word-break: break-all;">{{ $upiId }}</p>
            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm" id="copy-upi">
                {{ __('payments.page.copy_upi') }}
            </button>
        @else
            <x-alert tone="warning">{{ __('payments.set_upi_id_prompt') }}</x-alert>
        @endif

        @if ($amount > 0)
            <p class="ui-label" style="margin: 1.25rem 0 0.375rem;">{{ __('payments.page.amount') }}</p>
            <p class="ui-h2" style="margin: 0;">{{ __('payments.amount_format', ['amount' => number_format($amount / 100, 2)]) }}</p>
            <p class="ui-subtle" style="margin-top: 0.875rem;">{{ __('payments.page.instructions') }}</p>

            @if ($upiString)
                <a href="{{ $upiString }}" class="ui-btn ui-btn-primary" style="display: inline-block; margin-top: 1rem;">
                    Pay via UPI app
                </a>
            @endif

            @if ($qrBase64)
                <div style="margin-top: 1rem;">
                    <img src="data:image/png;base64,{{ $qrBase64 }}" alt="UPI QR code" style="max-width: 200px;">
                </div>
            @endif
        @else
            {{-- Nothing to charge yet: the form is not rendered at all, and there is no --}}
            {{-- client-side amount to fall back on either. --}}
            <x-alert tone="warning" style="margin-top: 1rem;">{{ __('payments.set_fee_first') }}</x-alert>
        @endif
    </div>

    @if ($payment)
        @if ($payment->status === \App\Enums\PaymentStatus::Pending)
            <x-alert tone="info">
                <strong>{{ __('payments.status.pending') }}</strong>
                <span>{{ __('payments.status.waiting') }}</span>
            </x-alert>
        @elseif ($payment->status === \App\Enums\PaymentStatus::Approved)
            <x-alert tone="success">
                <strong>{{ __('payments.status.approved') }}</strong>
                <span>{{ __('payments.status.approved_detail') }}</span>
            </x-alert>
        @else
            <x-alert tone="danger">
                <strong>{{ __('payments.status.rejected') }}</strong>
                @if ($payment->rejection_reason)
                    <span>{{ $payment->rejection_reason }}</span>
                @else
                    <span>{{ __('payments.status.waiting_reason') }}</span>
                @endif
            </x-alert>
        @endif
    @endif

    @if ($amount > 0)
        <div class="ui-card ui-rise" style="padding: 1.25rem;">
            <form method="POST" action="{{ url('/enrolments/'.$enrolment->id.'/payment') }}" enctype="multipart/form-data">
                @csrf

                <div class="mb-5">
                    <label for="upi_reference" class="ui-label">{{ __('payments.page.upi_reference') }}</label>
                    <input
                        type="text"
                        name="upi_reference"
                        id="upi_reference"
                        value="{{ old('upi_reference') }}"
                        maxlength="64"
                        autocomplete="off"
                        class="ui-input"
                    >
                </div>

                @if ($canSubmit)
                    <div class="mb-5">
                        <label for="screenshot" class="ui-label">{{ __('payments.page.screenshot') }}</label>
                        <input
                            type="file"
                            name="screenshot"
                            id="screenshot"
                            accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                            class="ui-input"
                            style="padding-block: 0.5rem;"
                            required
                        >
                        <p class="ui-help">{{ __('payments.page.file_hint') }}</p>
                    </div>

                    <x-button>{{ __('payments.page.submit') }}</x-button>
                @else
                    {{-- One decision at a time: the form stays visible with everything but the --}}
                    {{-- file input, and the submit is disabled so the state is unambiguous. --}}
                    <button type="submit" class="ui-btn ui-btn-primary" disabled>
                        {{ __('payments.page.submit') }}
                    </button>
                @endif
            </form>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            var button = document.getElementById('copy-upi');
            var target = document.getElementById('payment-upi-id');

            if (!button || !target || !navigator.clipboard) {
                return;
            }

            button.addEventListener('click', function () {
                navigator.clipboard.writeText(target.textContent.trim());
            });
        })();
    </script>
@endpush
