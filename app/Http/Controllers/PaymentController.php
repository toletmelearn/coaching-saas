<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Enrolment;
use App\Models\Payment;
use App\Support\Payments\AmountResolver;
use App\Support\Payments\PaymentScreenshotService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The student's side of the manual UPI flow: see what is owed, upload proof of
 * payment, then track whether an owner has reviewed it.
 *
 * The enrolment is always the one in the route, and always the student's own —
 * another student's enrolment is a 404 rather than a 403, so the endpoint never
 * confirms that an id exists to somebody who may not see it.
 */
class PaymentController extends Controller
{
    /**
     * Ten screenshots per hour per student. Floods of uploads are worth blocking
     * before any file is decoded, and the limit is checked ahead of every other
     * branch so that being over it is always a 429 rather than a validation error.
     */
    private const UPLOAD_LIMIT = 10;

    private const UPLOAD_DECAY_SECONDS = 3600;

    public function show(Request $request, Enrolment $enrolment): View
    {
        $this->authorizeOwner($request, $enrolment);

        $amount = AmountResolver::forEnrolment($enrolment);
        $payment = $this->latestPayment($enrolment);

        return view('payments.show', [
            'enrolment' => $enrolment,
            'course' => $enrolment->course,
            'amount' => $amount,
            'upiId' => app(TenantContext::class)->get()->upi_id,
            'payment' => $payment,
            // One submission may wait for review at a time; a rejection re-opens the
            // form, an approval closes it for good.
            'canSubmit' => $amount > 0 && ($payment === null || $payment->status === PaymentStatus::Rejected),
        ]);
    }

    public function store(Request $request, Enrolment $enrolment, PaymentScreenshotService $screenshots): RedirectResponse
    {
        $this->authorizeOwner($request, $enrolment);

        $user = $request->user('tenant');
        $key = sprintf('payment-screenshot:%d:%d', (int) $enrolment->tenant_id, (int) $user->id);

        if (RateLimiter::tooManyAttempts($key, self::UPLOAD_LIMIT)) {
            abort(429);
        }

        RateLimiter::hit($key, self::UPLOAD_DECAY_SECONDS);

        $amount = AmountResolver::forEnrolment($enrolment);

        // Nothing can be priced until the course carries a fee, and this must never
        // fall through to "charge whatever the client sent".
        if ($amount <= 0) {
            abort(422, __('payments.set_fee_first'));
        }

        if ($this->hasPendingPayment($enrolment)) {
            return $this->back($enrolment, ['screenshot' => __('payments.errors.already_pending')]);
        }

        $validator = Validator::make($request->all(), [
            'screenshot' => ['required', 'file', 'mimes:png,jpg,jpeg,webp'],
            'upi_reference' => ['nullable', 'string', 'max:64'],
        ], [
            'screenshot.required' => __('payments.upload.invalid_image'),
            'screenshot.mimes' => __('payments.upload.invalid_image'),
        ]);

        if ($validator->fails()) {
            return $this->back($enrolment, $validator->errors()->toArray());
        }

        $file = $validator->validated()['screenshot'];

        // Checked before GD ever touches the bytes — see PaymentScreenshotService.
        if ($dimensionError = $screenshots->dimensionError($file)) {
            return $this->back($enrolment, ['screenshot' => $dimensionError]);
        }

        $payment = new Payment;

        // amount_paise, status, submitted_at, tenant_id and enrolment_id are all
        // derived here, never read from the body: a client that posts amount_paise=1
        // gets charged the course fee regardless.
        $payment->forceFill([
            'enrolment_id' => $enrolment->id,
            'amount_paise' => $amount,
            'screenshot_path' => $screenshots->store((int) $enrolment->tenant_id, $file),
            'upi_reference' => $request->input('upi_reference'),
            'status' => PaymentStatus::Pending,
            'submitted_at' => now(),
        ]);

        $payment->save();

        return redirect("/enrolments/{$enrolment->id}/payment");
    }

    /**
     * 404 (not 403) for an enrolment that belongs to somebody else, so the endpoint
     * says nothing about ids the caller has no business probing for.
     */
    private function authorizeOwner(Request $request, Enrolment $enrolment): void
    {
        $user = $request->user('tenant');

        abort_unless($user !== null && (int) $enrolment->user_id === (int) $user->id, 404);
    }

    private function latestPayment(Enrolment $enrolment): ?Payment
    {
        return Payment::where('enrolment_id', $enrolment->id)
            ->orderByDesc('id')
            ->first();
    }

    private function hasPendingPayment(Enrolment $enrolment): bool
    {
        return Payment::where('enrolment_id', $enrolment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->exists();
    }

    /**
     * @param  array<string, string|list<string>>  $errors
     */
    private function back(Enrolment $enrolment, array $errors): RedirectResponse
    {
        return redirect("/enrolments/{$enrolment->id}/payment")->withErrors($errors);
    }
}
