@extends('layouts.app')

@section('content')
    <x-page-header :title="__('payments.queue.heading')" />

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

    <p class="ui-subtle" style="margin-bottom: 1rem;">
        {{ __('payments.queue.pending', ['count' => $pendingTotal]) }}
    </p>

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('payments.queue.columns.student') }}</th>
                    <th scope="col">{{ __('payments.queue.columns.course') }}</th>
                    <th scope="col">{{ __('payments.queue.columns.amount') }}</th>
                    <th scope="col">{{ __('payments.queue.columns.submitted') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('payments.queue.review') }}</span></th>
                </tr>
            </thead>
            <tbody>
            {{-- Oldest first: whoever waited longest gets looked at first. --}}
            @forelse ($payments as $payment)
                <tr>
                    <td style="font-weight: 650;">{{ $payment->enrolment?->user?->name }}</td>
                    <td>{{ $payment->enrolment?->course?->title }}</td>
                    <td>{{ __('payments.amount_format', ['amount' => number_format($payment->amount_paise / 100, 2)]) }}</td>
                    <td>{{ $payment->submitted_at?->timezone('Asia/Kolkata')->format('d M Y, H:i') }}</td>
                    <td>
                        <x-link href="{{ url('/manage/payments/'.$payment->id) }}" variant="ghost" size="sm">
                            {{ __('payments.queue.review') }}
                        </x-link>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="color: var(--ink-subtle);">{{ __('payments.queue.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $payments->links() }}
@endsection
