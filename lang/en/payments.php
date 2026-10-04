<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Manual UPI payments (Phase 10)
    |--------------------------------------------------------------------------
    |
    | Every string rendered on the student payment page, the owner/staff review
    | queue and the payment review screen lives here, so the flow can be
    | re-langued without touching a single blade file.
    |
    */

    // Shown instead of the upload form when the course has no fee configured.
    'set_fee_first' => 'Set a course fee first',

    // Shown instead of the UPI id when the institute has not configured one.
    'set_upi_id_prompt' => 'This institute has not set a UPI id yet. Please ask the owner to add one in institute settings before you pay.',

    // Owner/staff dashboard badge. Singular on purpose — it is never a rounded figure.
    'badge' => ':count payment awaiting review',

    // Money, always rendered in rupees with two decimals.
    'amount_format' => '₹:amount',

    'page' => [
        'heading' => 'Pay by UPI',
        'amount' => 'Amount to pay',
        'upi_id' => 'UPI id',
        'copy_upi' => 'Copy UPI id',
        'copied' => 'UPI id copied',
        'upi_reference' => 'UPI reference (optional)',
        'screenshot' => 'Payment screenshot',
        'submit' => 'Submit for review',
        'instructions' => 'Pay the amount to the UPI id above, then upload a screenshot of the confirmation. Add the UPI reference from your banking app if you have one.',
        'file_hint' => 'PNG, JPG or WebP up to 4096px on a side.',
    ],

    'status' => [
        'pending' => 'Pending review',
        'waiting' => 'Your payment is waiting for review.',
        'approved' => 'Payment approved',
        'approved_detail' => 'Your payment has been approved. Your access to this course continues as normal.',
        'rejected' => 'Payment rejected',
        'waiting_reason' => 'The institute will review it and tell you if anything needs redoing.',
    ],

    // Phase 15 (Part B) — the three-state payment card on the student dashboard
    // and the same "Payment needed" state on a paid lesson page.
    'state' => [
        'heading' => 'Payment status',
        'received' => 'Payment received for :course on :date — reference :reference',
        'under_review' => 'Payment under review for :course — submitted :date',
        'needed' => 'Payment needed for :course — :amount',
        'pay_now' => 'Pay now',
    ],

    'errors' => [
        'already_pending' => 'You already have a payment awaiting review for this course. Wait for it to be reviewed before sending another.',
    ],

    'upload' => [
        'invalid_image' => 'That file is not an image we can read. Upload a PNG, JPG or WebP screenshot.',
        'too_small' => 'That image is too small to read. It must be at least :min pixels on each side.',
        'too_large' => 'That image is too large. It must be no more than :max pixels on each side.',
        'too_many_pixels' => 'That image has too many pixels in total. Keep it under 16 million pixels.',
    ],

    'queue' => [
        'heading' => 'Payment queue',
        'pending' => 'Payment queue (:count pending)',
        'empty' => 'No payments are waiting for review.',
        'review' => 'Review',
        'columns' => [
            'student' => 'Student',
            'course' => 'Course',
            'amount' => 'Amount',
            'submitted' => 'Submitted',
        ],
    ],

    'review' => [
        'heading' => 'Review payment',
        'screenshot' => 'Payment screenshot',
        'upi_reference' => 'UPI reference',
        'no_upi_reference' => 'No UPI reference was given.',
        'student' => 'Student',
        'course' => 'Course',
        'amount' => 'Amount',
        'submitted' => 'Submitted',
        'status' => 'Status',
        'reason' => 'Rejection reason',
        'reason_hint' => 'Optional. At most 500 characters. The student sees whatever you type here.',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'confirm_reject' => 'Reject this payment? The student will be able to upload a new screenshot.',
        'approved' => 'Payment approved.',
        'rejected' => 'Payment rejected.',
        'already_approved' => 'This payment was already approved. Nothing changed.',
        'back_to_queue' => 'Back to the payment queue',
    ],

];
