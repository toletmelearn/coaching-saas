# Privacy

Governed by India's **Digital Personal Data Protection Act, 2023 (DPDP Act)** and the
**DPDP Rules, 2025**. Several points below are marked *needs legal confirmation* — they are
this project's current working assumption, not a settled legal position, and must not be
treated as fact until confirmed.

## Roles under DPDP

- **Tenant = Data Fiduciary.** Each coaching institute decides why and how its students'
  personal data is processed (what courses to enroll them in, what to communicate), so each
  tenant is the Data Fiduciary for its own students' data. *Needs legal confirmation:*
  whether a tenant could instead be classified as a joint fiduciary with the platform
  depending on how much the platform's own features (e.g. platform-level analytics) use
  tenant data independently of the tenant's instructions.
- **Platform = Data Processor.** The platform processes personal data on each tenant's
  behalf and under their instructions (hosting, storage, delivery), not on its own account.

## Children ("users under 18")

- DPDP treats anyone under 18 as a "child" and requires **verifiable parental/guardian
  consent** before processing a child's personal data.
- Implemented in **Phase 15** (`docs/specs/phase-15-dpdp-consent-foundation.md`), subject
  to the legal confirmations above:
  - Guardian fields on the student record (guardian name, relationship, phone, and an
    optional guardian email), collected on the student-creation form. CSV imports record
    no guardian data at all (`guardian_*` stay null) — the owner is expected to complete
    them later from the student's consent screen.
  - A `consents` table recording: **who** gave consent (guardian or the student, once of
    age), **when** (`granted_at`), **method** (`guardian_whatsapp`, `guardian_in_person`
    or `guardian_signed_form`), **notice version** shown at the time
    (`v1.0-2026-10-04`), and **`withdrawn_at` + reason** if consent is later withdrawn.
    The four purposes are `course_delivery`, `progress_tracking`, `communication` and
    `media_processing`.
  - A student is not created until all four purposes are consented to: the creation form
    and the bulk import each write all four rows in the same transaction as the student
    row, and a missing purpose fails the request with `consents.errors.purposes_required`.
- *Needs legal confirmation:* the exact verification method for guardian consent that DPDP
  Rules 2025 will accept in practice (the Rules describe standards but implementation
  guidance is still evolving), and whether age verification itself requires anything beyond
  self-declared date of birth for this product's risk profile.

## Purpose limitation

- Progress and device data (watch time, quiz results, login device/IP) collected for a
  student is used **only** for delivering and improving their learning experience — course
  recommendations *within* their enrolled tenant's own offerings, progress tracking,
  support. It is never used for cross-tenant marketing, sold to third parties, or used to
  build an advertising profile.

## Student rights: export and delete

- Each student (or their guardian, for a child) can request **export** (a copy of their
  personal data held for them) and **delete** (erasure, subject to legally required
  retention such as financial records). This applies per-tenant, since a student's
  relationship is with a specific tenant.
- **Implemented in Phase 15 — but owner/staff-triggered only.** A student cannot
  self-serve either right from the app yet (their own data screens 403 by design of the
  Phase 15 permissions tests); the institute's owner or staff acts on their behalf at
  `/manage/students/{id}`:
  - *Export:* a JSON attachment with exactly six top-level keys — `user`, `enrolments`,
    `progress`, `devices`, `consents`, `payments` — each scoped to that student. The
    `user` object is built field by field rather than via the model's array, so
    `password`/`remember_token` can never leak into it.
  - *Erasure:* refuses to run until the operator types the student's exact name, then
    (in one transaction) snapshots key fields of the student's enrolments, live-class
    attendance and payments into `consent_audit_logs`, hard-deletes
    `lesson_progress`/`user_devices`/attendance/enrolments (payments cascade with their
    enrolments — the snapshot is their surviving record), and anonymises the user row:
    name `Deleted Student`, email the sentinel `erased-{id}@removed.invalid` (never null
    — see SECURITY.md), phone and guardian columns null. Consent rows survive as the
    audit trail.
- *Needs legal confirmation:* exact retention periods that override a delete request (e.g.
  payment/invoice records for tax purposes) and how long they must be kept before deletion
  is finally applied. **Tension to resolve:** current erasure destroys the queryable
  payment rows immediately (only the JSON snapshot survives), which may conflict with
  tax-retention duties — recorded as an unresolved risk in the Phase 15 spec.

## Breach notification

- A personal data breach affecting a tenant's students must be notified to that tenant
  (as Data Fiduciary) promptly, so they can meet their own DPDP notification obligations to
  the Data Protection Board and affected users. *Needs legal confirmation:* exact
  notification timelines and thresholds under the Rules, and whether the platform (as
  processor) has any direct notification duty of its own versus only a duty to inform the
  fiduciary.

## Partial implementation state (after Phase 15)

The guardian fields, `consents` table, consent-gated creation, withdrawal, JSON export
and erasure all exist now (Phase 15). Still outstanding, by design or by legal pending:

- **Self-service rights** — students cannot export or erase their own data through the
  app; only the institute's owner/staff can act, per tenant. *Needs legal confirmation:*
  whether DPDP requires a direct in-product path for the data principal.
- **The notice text itself** — the DPDP notice rendered at consent time is v1.0, wording
  in `lang/en/consents.php`, awaiting the legal review this file flags.
- **Retention automation** — nothing is deleted on a schedule; erasure is manual,
  immediate, and (per the tension noted above) removes queryable payment rows while
  keeping only a JSON snapshot.
- **Age verification** — still self-declared; no OTP/age gate exists. *Needs legal
  confirmation* as stated above.
- **Withdrawal by the student directly** — withdrawal is also owner/staff-mediated today
  (the student's own consent screens 403); a self-service withdrawal path is part of the
  same open question as self-service export/erasure.

## Phase 16.1 data-minimisation changes

- **Erasure snapshot.** The copy kept in the consent audit log after erasure holds only ids, amounts,
  status, timestamps, course ids, and for live-class attendance the class id and duration. Free-text
  payment notes, UPI references and screenshot paths are not kept.
- **Rate-limit keys.** Login rate-limit cache keys hold an HMAC of the identifier, not the email or
  phone in clear.
- **Log page.** The platform admin log viewer redacts emails, Indian mobile numbers and long digit
  runs before display. The file on disk is unchanged.
- **Admin audit log.** Still records IP addresses for admin actions. Retention for that log needs a
  decision and is not yet set (open).
