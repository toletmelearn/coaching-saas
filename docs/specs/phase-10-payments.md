# Phase 10: Manual UPI Payments

**Status:** Complete. `composer test` (814: 813 passed, 1 pre-existing MySQL-only test
skipped on SQLite), `composer test:mysql` (782/782), `composer test:js` (29/29),
`composer lint`, and `composer analyse` all pass with zero regressions to Phases 1–9 / P1.

---

## Goal

Close the one Stage B gap that was still open: a student pays the institute directly over
UPI, uploads proof, and the owner decides. No gateway, no custody of funds, no client
supplied price. See [PAYMENTS.md](../../PAYMENTS.md) for the money-flow rules this
implements and [SECURITY.md](../../SECURITY.md) for the storage/authorisation rules.

The phase was written in two steps: **Step 1** wrote the whole contract as Pest tests and
stopped with them all failing (0 of 52 positive controls passing, because nothing existed
yet), and **Step 2** implemented app code until every suite went green. The only files
Step 2 touched under `tests/` are the two proven Step 1 defects listed in
[Assumptions](#assumptions).

---

## Schema

### `payments`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | bigint | part of `tenantKeys()` → unique `(tenant_id, id)` |
| `enrolment_id` | bigint | composite FK `(tenant_id, enrolment_id)` → `enrolments(tenant_id, id)` **cascadeOnDelete** |
| `amount_paise` | unsigned int | server-derived, see [Amount derivation](#amount-derivation) |
| `upi_reference` | varchar(64), nullable | one of only two mass-assignable columns |
| `screenshot_path` | varchar(255) | the other mass-assignable column; written by the upload service, never taken from input |
| `status` | varchar(20) | `pending` / `approved` / `rejected` (`App\Enums\PaymentStatus`) |
| `rejection_reason` | varchar(500), nullable | matches the 500-character validation cap |
| `submitted_at` | timestamp, default current | |
| `reviewed_at` | timestamp, nullable | set once; a second approval never moves it |
| `reviewed_by` | bigint, nullable | composite FK `(tenant_id, reviewed_by)` → `users(tenant_id, id)` **restrictOnDelete** (`tenantForeign()`) |
| `created_at` / `updated_at` | timestamps | |

Index: `(tenant_id, status, submitted_at)` — exactly the queue query.

### Columns added to existing tables

- `tenants.upi_id` — varchar(100), nullable. Where students are told to send money.
- `courses.fee_paise` — unsigned int, nullable. The only source of an amount.
- `enrolments.approved_payment_id` — bigint, nullable, composite FK
  `(tenant_id, approved_payment_id)` → `payments(tenant_id, id)` **restrictOnDelete**
  (see [Assumptions](#assumptions) for why this is not `SET NULL`).

`payments` is created first (its FKs point at `enrolments` and `users`, both older), then
the three columns — `enrolments.approved_payment_id` points *into* `payments` and so has to
come after.

---

## Amount derivation

`App\Support\Payments\AmountResolver::forEnrolment()` reads `enrolment->course->fee_paise`
and nothing else. A `<= 0` result is not "free" — it is "the institute has not priced this
course yet", and it blocks both the form and the POST (422, `payments.set_fee_first`).

`PaymentController::store()` never reads `amount_paise`, `price` or `fee_paise` from the
request body, and `Payment::$fillable` is exactly
`['upi_reference', 'screenshot_path']` — every other column is written through
`forceFill()` in the controller, so a forged body cannot choose its own price, mark itself
approved, or point `screenshot_path` at a file it uploaded elsewhere.

---

## Upload pipeline

`App\Support\Payments\PaymentScreenshotService` mirrors `LogoUploadService` with its own
bounds:

1. **`mimes:png,jpg,jpeg,webp`** — sniffed from content, not the declared type or the
   filename. A GIF renamed `.png`, an SVG and a PDF are all refused before anything is
   decoded.
2. **`dimensionError()`** — `getimagesize()` on the header *first*, so a decompression bomb
   is rejected without being decoded. Caps: min side 256, max side 4096, max 16,000,000
   pixels (decimal — 4096 × 4096 = 16,777,216 already exceeds it, which is the point).
3. **`store()`** — re-encodes to PNG and writes `tenants/{id}/payments/{32 random chars}.png`
   to the **private** `local` disk. The client filename and declared MIME type never reach
   disk, and the re-encode discards anything that rode along in the original bytes.

Also gated before any file work: **10 uploads per hour per student** (`RateLimiter`,
checked first so attempt 11 is a 429 rather than a validation error), and **one pending
payment per enrolment** (a rejection re-opens the form; an approval closes it).

---

## Student surface

`GET/POST /enrolments/{enrolment}/payment` — behind the same
`auth:tenant` → `active.tenant.user` → `device.limit` → `must.change.password` stack as
`/dashboard`. The enrolment must belong to the caller: somebody else's enrolment in the
same tenant is a **404**, never a 403.

The page always renders the UPI target (or `payments.set_upi_id_prompt` when the institute
has not set one). Below that, when a fee exists: the amount, the instructions, and a form
carrying the optional `upi_reference` field. The `screenshot` file input and an enabled
submit are present only when `$canSubmit` (no payment yet, or the last one was rejected);
otherwise the submit renders disabled and the status banner takes the file input's place.
With no fee at all the form is not rendered at all — not merely hidden.

---

## Review surface

- `GET /manage/payments` — `PaymentPolicy::viewPaymentQueue` (owner **and** staff), pending
  only, oldest `submitted_at` first, 25 per page, count line + empty state.
- `GET /manage/payments/{payment}` — `viewPayment`. The screenshot is embedded via a
  **30-minute temporary signed URL**; the approve/reject forms are inside
  `@can('approvePayment', …)`, so staff see the evidence and not the controls.
- `POST …/approve` — `approvePayment` (owner only). Idempotent: an already-approved payment
  redirects with `payments.review.already_approved` and touches neither `reviewed_at` nor
  the enrolment. Anything else that is not pending is a 422.
- `POST …/reject` — `rejectPayment` (owner only), `rejection_reason` optional and capped at
  500 characters. Approving a rejected payment is also a 422: both decisions are final.

Approval writes `status`, `reviewed_at`, `reviewed_by` and points
`enrolments.approved_payment_id` at the payment as an audit link. It **never enrols
anybody** — the enrolment this payment belongs to already exists.

Flash key is `payment_notice` (`payments.review.approved|rejected|already_approved`).

---

## Screenshot delivery

`GET /payments/{payment}/screenshot`, route name `payments.screenshot`, behind `signed`.

Two independent gates, because either alone is insufficient:

- **Signature** — short-lived and bound to the absolute URL, so unsigned, tampered and
  expired URLs are all 403.
- **Authorisation** — `PaymentPolicy::viewOwnPayment` (the student who submitted it) or
  `viewPayment` (owner/staff). A perfectly valid signature over another student's payment
  is still 403.

Response is `image/png` + `X-Content-Type-Options: nosniff` + `Cache-Control: no-store, private`.

The route sits in the `active.tenant.user` group but **outside** `must.change.password`, so
an `<img>` can never be answered with a redirect to a password form.

---

## Policy

`App\Policies\PaymentPolicy`, registered on `Payment::class` in `AppServiceProvider`:

| Ability | Rule |
|---------|------|
| `viewPaymentQueue`, `viewPayment` | `role->canManageCourses()` (owner + staff) |
| `approvePayment`, `rejectPayment` | `role->isOwner()` |
| `viewOwnPayment` | the payment's enrolment belongs to the caller |

`viewOwnPayment` resolves the enrolment with `withoutGlobalScopes()`. That is deliberate,
not a bypass: a Gate decision can be asked with no tenant context resolved at all (the unit
tests do exactly that, where `TenantScope` would throw), and the composite FK
`(tenant_id, enrolment_id)` already proves the enrolment is in the payment's own tenant.

---

## UI and i18n

Every string — including all validation messages the flow can produce — is in
`lang/en/payments.php` (plus `settings.fields.upi_id*` and `courses.manage.fee_*` for the
two fields this phase adds to forms that already existed). The course fee lives on the
course create form and in a "Course fee" card on the course editor; the UPI id lives in
institute settings next to the other contact fields.

All three pages (student payment page, queue, review) pass the Phase 9 360px mobile guard,
including the queue's `<table>` inside an `overflow-x-auto` wrapper.

---

## Assumptions

- **`approved_payment_id` uses `restrictOnDelete()`, not `SET NULL`.** The brief asked for
  both a *composite* FK and `ON DELETE SET NULL`, and MySQL rejects that combination
  outright — a composite FK whose other half (`tenant_id`) is `NOT NULL` cannot be set to
  null (errno 150, verified directly against this project's MySQL test database); SQLite
  would try to null the `NOT NULL` column when the payment went away. `RESTRICT` keeps the
  cross-tenant guarantee the schema test asserts. Nothing in the application deletes a
  payment, so the restriction never fires; and RESTRICT is arguably the better behaviour
  for an audit pointer anyway.
- **Course-fee UI placement was not specified by the brief.** `fee_paise` is on the course
  create form and in its own card on the course editor (the editor previously had no way to
  edit course fields at all — it only published, archived and managed chapters).
- **Two Step 1 test defects were corrected in Step 2** (both proven by failing suites, both
  in files this phase itself introduced — no pre-existing test was touched):
  - `PaymentFixtures::payment()` built its attribute set as `defaults + $overrides`. Array
    union keeps the *left* operand's value on a shared key, so every `status`,
    `rejection_reason`, `reviewed_at` and `submitted_at` override was silently discarded and
    fixtures came out `pending`. Four tests failed as a result. Now `$overrides + defaults`.
  - `PaymentReviewTest`'s guest case did not call `freshRequestCycle()` after two
    `actingAs()` calls, so the guard still held the student's cached user and the request
    came back 403 instead of redirecting to `/login`. Probed both paths before editing:
    403 without, 302 with.
- **The "already pending" rejection is a redirect with `errors.screenshot`**, matching
  `LogoUploadController`'s convention, rather than a 422 — only the no-fee POST and the
  approve-after-reject case are 422, because that is what the contract asks for.
- **Approve never auto-enrols**, and never notifies — there is no notification channel in
  this codebase yet.

## Unresolved risks

- **No delete test exists for `payments`.** `payments.enrolment_id` is `cascadeOnDelete`
  while `enrolments.approved_payment_id` restricts, so deleting an enrolment that has an
  approved payment has to resolve a two-way constraint. Nothing in the app deletes
  enrolments, payments or users (verified by search), so the path is unreachable today —
  but the brief's required-test list does not include a delete case, so the interaction is
  untested rather than proven safe.
- **The rate limiter is per student, per tenant, per hour, in the cache** — with
  `CACHE_STORE=array` in tests and the configured cache in production. A cache flush resets
  everyone's allowance; that is the same trade-off every other limiter in this codebase
  makes (`DemoRequestController`, the progress heartbeat).
- **`upi_id` is only as trustworthy as the settings form.** It is a free-text field with a
  100-character cap and no format validation, because UPI ids vary (`name@bank`,
  `phone@upi`, `letters-dots@okaxis`) and a regex that rejects a valid one is worse than one
  that lets an odd one through.
- **No QR code** — the brief's chosen shape is a "Copy UPI id" button (Clipboard API, no
  dependency), with the fallback that the id is selectable text if `navigator.clipboard` is
  unavailable. A QR is a straightforward follow-up if the pilot asks for it.
