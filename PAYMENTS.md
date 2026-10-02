# Payments

## Platform never holds student funds

The platform is not a payment intermediary. Student payments go to the tenant's own
gateway account or the tenant owner's own UPI id — the platform never sits in the money
path, never custodies funds, and never issues refunds on a tenant's behalf. This keeps the
platform out of PCI/PA-DSS-style custodial obligations for money it never holds.

## Per-tenant gateway accounts

Each tenant that uses an automated gateway (Stage C, see [ROADMAP.md](ROADMAP.md)) connects
their **own** gateway account. Credentials are stored encrypted per tenant (see
[SECURITY.md](SECURITY.md)). There is no shared/platform-level gateway account that
multiplexes charges across tenants.

## Server-side pricing

Price is never accepted from the client. Checkout/approval always re-derives the amount
from the course/plan record, looked up within the current tenant's scope, at the moment of
charge or approval — never from a hidden field, query param, or client-computed total (see
[SECURITY.md](SECURITY.md)).

## Idempotent webhooks

Any gateway webhook handler (Stage C) is idempotent: processing the same webhook delivery
twice (gateway retries, duplicate delivery) must not double-credit an enrollment or
double-send a receipt. This is typically enforced by recording the gateway's event/delivery
id and short-circuiting on a second delivery of the same id.

## Manual UPI flow (pilot) — built in Phase 10

The pilot (Stage B, friend is tenant #1) uses a manual flow, not a gateway integration. It
is implemented as of [Phase 10](docs/specs/phase-10-payments.md); every rule below has a
test behind it.

1. Student opens `GET /enrolments/{id}/payment`; sees the tenant's UPI id and the amount.
   The amount is `courses.fee_paise`, read server-side by `App\Support\Payments\AmountResolver`
   — never a field in the request body. An `amount_paise` posted by the client is ignored
   outright. No fee configured means the form is not rendered at all and a crafted POST is
   refused with 422 (`payments.set_fee_first`).
2. Student pays externally via their own UPI app and uploads a PNG/JPG/WebP screenshot
   (content-sniffed, dimensions checked on the header before decoding, re-encoded to PNG
   under a random name on the **private** disk — see [SECURITY.md](SECURITY.md)). Optional
   `upi_reference` up to 64 characters. **10 uploads per hour per student**, and **one
   pending payment per enrolment**: a rejection re-opens the form, an approval closes it.
3. Owner/staff review it at `GET /manage/payments` (pending only, oldest first, 25 per
   page). Seeing the queue is open to anyone who manages courses; **deciding is owner-only**
   (`PaymentPolicy::approvePayment` / `rejectPayment`), so staff see the evidence and not the
   buttons.
4. Approval is **idempotent** — approving an already-approved payment is a no-op that leaves
   `reviewed_at` untouched and the enrolment's `approved_payment_id` pointing where it was,
   not a double-enrollment or a duplicate notification. This matters because a slow UI or a
   double-click is a realistic failure mode for a manual, human-driven approval step.
   Approving a *rejected* payment is refused (422): both decisions are final.
5. Approval writes an audit link (`enrolments.approved_payment_id`, a composite tenant FK)
   and **never enrolls anybody** — enrollment stays a separate, owner-initiated action.

Payment screenshots are private storage served through a signed URL **plus** a per-viewer
policy check, same standard as videos and PDFs but stricter: a valid signature over another
student's payment still 403s (see [SECURITY.md](SECURITY.md)).

Money itself never touches the platform — the UPI transfer goes student → tenant owner
directly, and the app only records and reviews the evidence.
