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
- Planned schema support (not yet implemented — Phase 0 does not add these fields):
  - Guardian fields on the student record (guardian name, contact, relationship).
  - A `consents` table recording: **who** gave consent (guardian or the student, once of
    age), **when**, **method** (e.g. OTP, signed form, in-app checkbox), **notice version**
    shown at the time, and **`withdrawn_at`** if consent is later withdrawn.
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
- *Needs legal confirmation:* exact retention periods that override a delete request (e.g.
  payment/invoice records for tax purposes) and how long they must be kept before deletion
  is finally applied.

## Breach notification

- A personal data breach affecting a tenant's students must be notified to that tenant
  (as Data Fiduciary) promptly, so they can meet their own DPDP notification obligations to
  the Data Protection Board and affected users. *Needs legal confirmation:* exact
  notification timelines and thresholds under the Rules, and whether the platform (as
  processor) has any direct notification duty of its own versus only a duty to inform the
  fiduciary.

## Not yet implemented

None of the guardian fields, `consents` table, or export/delete tooling described above
exist yet — Phase 0 is documentation only. They are scoped for Stage C
(see [ROADMAP.md](ROADMAP.md)).
