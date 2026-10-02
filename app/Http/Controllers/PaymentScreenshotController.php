<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Serves a payment screenshot to an authorised viewer.
 *
 * The file lives on the private disk and is reachable only through this route, so
 * the URL is both signed (short-lived, tamper-evident) and authorised for the
 * caller: a valid signature over somebody else's payment is still refused. The
 * bytes are sent as image/png with nosniff and no-store, because a proof of
 * payment is neither HTML nor something a shared cache should hold on to.
 */
class PaymentScreenshotController extends Controller
{
    public function show(Payment $payment): Response
    {
        $user = auth('tenant')->user();

        $allowed = Gate::forUser($user)->allows('viewOwnPayment', $payment)
            || Gate::forUser($user)->allows('viewPayment', $payment);

        abort_unless($allowed, 403);

        $contents = Storage::disk('local')->get($payment->screenshot_path);

        abort_unless($contents !== null, 404);

        return response($contents, 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
