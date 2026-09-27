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

## Manual UPI flow (pilot, ships first)

The pilot (Stage B, friend is tenant #1) uses a manual flow, not a gateway integration:

1. Student initiates enrollment; sees the tenant's UPI id/QR and the amount (server-derived,
   not client-supplied).
2. Student pays externally via their own UPI app and uploads a payment screenshot.
3. Tenant owner/staff reviews the screenshot and manually approves or rejects the payment
   in the app.
4. Approval is **idempotent** — approving an already-approved payment is a no-op, not a
   double-enrollment or a duplicate notification. This matters because a slow UI or a
   double-click is a realistic failure mode for a manual, human-driven approval step.

Payment screenshots are private storage with signed URLs, same as videos and PDFs (see
[SECURITY.md](SECURITY.md)).
