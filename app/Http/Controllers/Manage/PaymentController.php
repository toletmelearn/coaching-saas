<?php

namespace App\Http\Controllers\Manage;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * The owner/staff side of the manual UPI flow.
 *
 * Viewing the queue is open to anyone who manages courses; the decisions are the
 * owner's alone, so a staff member opening the review page sees the evidence but
 * not the buttons. Every decision is final: approving twice is a no-op that leaves
 * the original reviewed_at untouched, and approving something already rejected is
 * refused outright rather than quietly re-reviewed.
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): View
    {
        Gate::authorize('viewPaymentQueue', Payment::class);

        $payments = Payment::with(['enrolment.user', 'enrolment.course'])
            ->where('status', PaymentStatus::Pending->value)
            ->orderBy('submitted_at')
            ->paginate(self::PER_PAGE);

        return view('manage.payments.index', [
            'payments' => $payments,
            'pendingTotal' => $payments->total(),
        ]);
    }

    public function show(Payment $payment): View
    {
        Gate::authorize('viewPayment', $payment);

        $payment->load(['enrolment.course', 'enrolment.user', 'reviewer']);

        return view('manage.payments.show', [
            'payment' => $payment,
            // Short-lived and bound to this payment id: the image is proof of a
            // payment, so its URL is worthless to anyone who cannot already open
            // this page, and expires long before it could be passed around.
            'screenshotUrl' => URL::temporarySignedRoute(
                'payments.screenshot',
                now()->addMinutes(30),
                ['payment' => $payment->id],
            ),
        ]);
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('approvePayment', $payment);

        if ($payment->status === PaymentStatus::Approved) {
            // Idempotent: a double-click must not move reviewed_at or re-point the
            // enrolment, it just reports that the decision already stands.
            return $this->notified(__('payments.review.already_approved'));
        }

        if ($payment->status !== PaymentStatus::Pending) {
            abort(422);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Approved,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user('tenant')->id,
        ]);

        $payment->save();

        // The audit link only — approving never enrols anybody, because the enrolment
        // this payment belongs to already exists.
        $payment->enrolment->forceFill(['approved_payment_id' => $payment->id])->save();

        return $this->notified(__('payments.review.approved'));
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('rejectPayment', $payment);

        $data = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($payment->status !== PaymentStatus::Pending) {
            abort(422);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Rejected,
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user('tenant')->id,
        ]);

        $payment->save();

        return $this->notified(__('payments.review.rejected'));
    }

    private function notified(string $notice): RedirectResponse
    {
        return redirect('/manage/payments')->with('payment_notice', $notice);
    }
}
