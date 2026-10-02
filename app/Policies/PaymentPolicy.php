<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Anyone who can manage courses can see what is waiting — the queue is the
     * operational side of selling a course, not just an owner's privilege.
     */
    public function viewPaymentQueue(User $actor): bool
    {
        return $actor->role->canManageCourses();
    }

    public function viewPayment(User $actor, Payment $payment): bool
    {
        return $actor->role->canManageCourses();
    }

    /**
     * Money decisions are the owner's alone; staff may look but not decide.
     */
    public function approvePayment(User $actor, Payment $payment): bool
    {
        return $actor->role->isOwner();
    }

    public function rejectPayment(User $actor, Payment $payment): bool
    {
        return $actor->role->isOwner();
    }

    public function viewOwnPayment(User $actor, Payment $payment): bool
    {
        // Resolved without the global tenant scope: this method is a pure decision
        // that may be asked with no tenant context resolved at all (the Gate unit
        // tests), where TenantScope would throw. It cannot cross tenants regardless —
        // payments.enrolment_id is a composite foreign key on (tenant_id, id), so the
        // enrolment reached from a payment is always in that payment's own tenant.
        $enrolment = $payment->enrolment()->withoutGlobalScopes()->first();

        return $enrolment !== null && (int) $enrolment->user_id === (int) $actor->id;
    }
}
