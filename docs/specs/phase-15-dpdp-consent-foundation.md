# Phase 15: DPDP Consent Foundation (+ Phase 10.1 — student payment status)

**Status:** Complete. `composer test` / `composer test:mysql` / `composer test:js` /
`composer lint` / `composer analyse` all green with zero regressions to Phases 1–14 / P1.
> Gate numbers recorded in `README.md` (they move every phase; the README is re-synced
> each phase, this one included).

---

## Goal

Implement the DPDP consent foundation that `PRIVACY.md` had marked "planned schema
support": guardian contact details on the student record, a `consents` table with the
notice version/method/withdrawal columns the Act asks for, the owner/staff consent and
student-data screens, a JSON export, and an erasure that satisfies the DPDP delete right
without breaking the tenancy or financial rules the rest of the repo depends on. See
[PRIVACY.md](../../PRIVACY.md) for the legal framing (the *needs legal confirmation*
flags there stay untouched — this phase implements, it does not adjudicate).

The phase was written in two steps, exactly like Phase 10: **Step 1** (`fe1f138`) wrote
the whole contract as 44 Pest tests across 7 files under `tests/Feature/Consents/` plus
`tests/Support/ConsentFixtures.php`, and stopped with them all failing (nothing existed
yet). **Step 2** implemented app code until every suite went green. The only Step-2
changes under `tests/` are the Step-1 defects listed in
[Test corrections](#test-corrections-approved-fixes-not-behavioural-changes) — every one
of them was raised and human-approved first, per rule 20.

**Part B** was requested alongside Part A: the student dashboard greets like
owner/staff, shows a three-state payment status card, and a paid lesson page shows
"Payment needed" instead of the player. It is numbered **Phase 10.1** — see
[Numbering decision](#numbering-decision).

---

## The four decisions (human-confirmed, 2026-10-04)

| # | Decision | Why it was needed | How it is implemented |
|---|----------|-------------------|-----------------------|
| D1 | **Erasure email = sentinel `erased-{id}@removed.invalid`, not `NULL`.** Name → `Deleted Student`, phone → `NULL`, guardian columns → `NULL`. | The users table's `users_email_or_phone_check` requires one of email/phone, so a `NULL` email only survives while the phone is also non-null — and nulling both violates the CHECK on MySQL. `.invalid` (RFC 2606) can never resolve or receive mail, and the row id keeps the value unique under `(tenant_id, email)`. | `StudentDataController::erase()` writes `sprintf('erased-%d@removed.invalid', $student->id)`; `StudentErasureTest` asserts the sentinel. The CHECK stays as it was — no schema relaxed. |
| D2 | **Existing `POST /users` test payloads gain the guardian + consent fields.** | Step 1 made `role=student` (and the role-less default) creation require guardian details, all four purposes and a collection method. Thirteen pre-existing payloads in three files create students, so they collided with the contract. | Every payload appends `ConsentFixtures::guardianFields() + ['consents' => ConsentFixtures::PURPOSES, 'consent_method' => ConsentFixtures::IMPORT_METHOD]`. Full before/after table in [Test corrections](#decision-2--existing-payload-changes-beforeafter). |
| D3 | **Bulk import unchanged: `guardian_*` stay `NULL`, method = `guardian_in_person`.** The owner collects guardian details later from the student's consent screen. | A CSV carries name/phone/email only — there is nowhere honest to put guardian data, and inventing it would be worse than recording its absence. | `UserImportController::confirm()` records four consent rows per imported student (`ConsentMethod::GuardianInPerson`, `notice_version = Consent::NOTICE_VERSION`, `recorded_by` = importer, inside the same `DB::transaction` as the student row). The import preview page shows the same DPDP notice the creation form shows. Guardian fields are collected afterwards on the `Record a consent` form, which upserts them onto the student. |
| D4 | **Erasure hard-deletes enrolments and live-class attendance, after snapshotting them (with their payments) as JSON into `consent_audit_logs.metadata`.** | The Step-1 tests expected *soft* delete, but the repo has no `SoftDeletes` anywhere, and the DPDP delete right means the rows are gone — not hidden. The conflict: `payments.enrolment_id` is `cascadeOnDelete()` (`2026_10_08_000001_create_payments_table.php` docblock: "deleting an enrolment takes its payment history with it"), so hard-deleting enrolments would destroy the financial record the brief says to preserve. | Inside one transaction: (1) key fields of the student's enrolments, attendance rows and payments are snapshotted as JSON arrays; (2) a `consent_audit_logs` row is written (`action = student_erased`, `user_id` = student, `actor_id` = acting owner/staff, `metadata` = the snapshot); (3) `lesson_progress` and `user_devices` rows are deleted; (4) `live_class_attendance` rows are deleted; (5) `enrolments` rows are hard-deleted — their payments follow via the composite FK's `ON DELETE CASCADE`; (6) the user row is anonymised. Consent rows survive: they are the audit trail, not personal data. |

---

## Numbering decision (10.1, not 12.2)

Part B is recorded as **Phase 10.1**, a follow-up to Phase 10 (manual UPI payments), for
two reasons:

1. **Substance.** The payment status card, the `payments.state.*` strings, the
   `PaymentStateResolver` and the paid-lesson gate all extend Phase 10's student payment
   surface — "has my payment landed, and what do I still owe". The greeting change rides
   along because it touches the same page.
2. **Thread separation.** Phase 12 / 12.1 is the live-classes thread (12.1 was live-class
   discoverability specifically). Part B does not touch a single live-class file, so a
   "12.2" would falsely couple two unrelated threads.

Recorded in `PROJECT_BRIEF.md` §3 as the 10.1 row.

**Numbering conflict (C13).** Git (`fe1f138`) gives DPDP consent the number 15, which
§3 had pre-assigned to "Queues at scale" (from the C12 renumber: Stage C =
14/15/16). §3 now lists 15 = this phase, queues = 16, catch-all = 17, and the collision
is recorded as conflict **C13**. `ROADMAP.md` still says 14/15/16 with DPDP unnumbered —
it is out of this phase's approved file scope, so that half of C13 stays open as a
§7 drift row rather than being fixed here.

---

## Schema

Three migrations, all `2026_10_10_*`, ordered users → consents → audit log (the
children's composite FKs point back at `users`).

### Columns added to `users`

| Column | Type | Notes |
|--------|------|-------|
| `guardian_name` | varchar(255), nullable | guardian contact block |
| `guardian_relationship` | varchar(100), nullable | e.g. `Mother` |
| `guardian_phone` | varchar(20), nullable | |
| `guardian_email` | varchar(255), nullable | deliberately optional |

All four nullable: a pre-Phase-15 student (or a CSV import, D3) is a valid record. They
are **not** in `User`'s `#[Fillable]`, so request input can never write them directly —
controllers `forceFill` them after validation.

### `consents`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | bigint | FK → `tenants(id)` restrict; part of every composite key |
| `user_id` | bigint | composite FK `(tenant_id, user_id)` → `users(tenant_id, id)` restrict — the consenting student |
| `guardian_id` | bigint, nullable | same composite shape → `users` — *who* gave consent; null until linked |
| `purpose` | varchar(50) | `App\Enums\ConsentPurpose` — `course_delivery` / `progress_tracking` / `communication` / `media_processing` |
| `notice_version` | varchar(50) | the wording shown at grant time; default `Consent::NOTICE_VERSION` (`v1.0-2026-10-04`) |
| `method` | varchar(50) | `App\Enums\ConsentMethod` — `guardian_whatsapp` / `guardian_in_person` / `guardian_signed_form` |
| `granted_at` | timestamp, **not null** | the grant instant |
| `withdrawn_at` | timestamp, nullable | withdrawal never deletes the grant it cancels |
| `withdrawn_reason` | varchar(500), nullable | matches the 500-char validation cap |
| `recorded_by` | bigint | composite FK → `users(tenant_id, id)` restrict — the owner/staff who recorded it |
| `created_at` / `updated_at` | timestamps | |

- **Unique** `(tenant_id, user_id, purpose, granted_at)`
  (`consents_tenant_user_purpose_granted_unique`): the same purpose may be granted again
  at a *later* instant — that is a second, legitimate consent — while the exact same
  instant is a duplicate submission. Plus indexes `(tenant_id, purpose)` and
  `(tenant_id, user_id, purpose)`.
- **Composite FKs everywhere** (TENANCY.md invariant 4): a consent can never name a
  student, guardian or recorder outside its own tenant, even when ids collide. Verified
  on MySQL (SQLite does not enforce composite FKs — same as every other schema test in
  the repo, it only runs under `composer test:mysql`).
- Mass assignment: `#[Fillable(['user_id', 'purpose', 'method'])]` — everything else
  (tenancy, guardian, recorder, grant, withdrawal, notice version) is guarded, proved by
  the positive-control test in `ConsentSchemaTest`.

### `consent_audit_logs`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | bigint | FK → `tenants(id)` restrict |
| `user_id` | bigint | composite FK → `users(tenant_id, id)` restrict — the (anonymised) student |
| `actor_id` | bigint | composite FK → `users(tenant_id, id)` restrict — the acting owner/staff |
| `action` | varchar(50) | `ConsentAuditLog::ACTION_STUDENT_ERASED` (`student_erased`) today |
| `metadata` | json, nullable | the pre-deletion snapshot (D4) |
| `created_at` / `updated_at` | timestamps | |

Index `(tenant_id, user_id, action)`.

---

## Consent notice and recording

### Student creation form (`GET/POST /users`)

`users/create` now opens with the DPDP notice panel (title, body, `Notice version:
v1.0-2026-10-04` — `UserController::create()` passes `Consent::NOTICE_VERSION`), then the
guardian block (name/relationship/phone required, email optional), then one checkbox per
purpose with its plain-language description, then the "How was this consent collected?"
select.

`UserController::store()` validates in this order, deliberately:

1. `Gate::authorize('create', User::class)` — a student actor is 403 before *any*
   validation, exactly as before.
2. basic fields (name/email/phone/role).
3. the contact check (`messages.errors.contact_required` on both fields when neither is
   present) — its error keys keep precedence over any consent error.
4. `Gate::authorize('createWithRole', …)` — staff posting `role=owner` is still 403.
5. **only for `role === student`** (or role-less, which defaults to student): guardian
   name/relationship/phone required, guardian email nullable-email, `consents` required
   array whose items must each be a `ConsentPurpose`, `consent_method` required
   `ConsentMethod`; then the completeness check — any missing purpose throws with the
   single key `consents` and the pinned message `consents.errors.purposes_required`.
   An unknown purpose surfaces as `consents.1` (the `Enum` rule on `consents.*`).

Creation then runs inside one `DB::transaction`: the user row, a `forceFill` of the
guardian columns onto it, and four consent rows (one per purpose, same `granted_at`,
`notice_version`, `method`, `recorded_by` = the acting user). A non-student role skips
step 5 entirely — guardian/consent fields posted alongside a staff/owner creation are
discarded by fillable (positive-control test: they are not stored).

### Bulk import (`POST /users/import/confirm`)

Per D3: after each imported student is saved, four consent rows are written with
`method = guardian_in_person`, `recorded_by` = the importer, inside confirm's existing
`DB::transaction`. `users/import/preview` shows the same notice panel as the creation
form.

### Record screen (`POST /manage/students/{student}/consents`)

Owner/staff can record one more consent later (e.g. the re-grant after a withdrawal): a
purpose select, a method select, and the guardian block — any guardian field posted is
`forceFill`ed onto the student (empty fields change nothing), then the consent row is
created with `Consent::NOTICE_VERSION` and `recorded_by` = actor. Unknown purpose →
`purpose` error; unknown method → `consent_method` error.

---

## Withdrawal (`/manage/students/{student}/consents/{consent}/withdraw`)

A GET renders the form (purpose, grant time, a required reason —
`consents.fields.reason`, 500-char cap); the POST validates the reason first and then
sets **only** `withdrawn_at` + `withdrawn_reason`. The grant row survives with both
sides of the history readable (the Step-1 tests assert the row itself is still there).

Side effects by purpose:

- **`course_delivery` → refused access, checked live.** `ConsentAccess::courseDeliveryWithdrawn(?User)`
  reads the student's *latest* `course_delivery` grant (ordered `granted_at` desc, `id`
  desc) and returns true only when that grant carries a `withdrawn_at`. A withdrawal
  followed by a later re-grant unlocks again (latest wins). A student with **no**
  consent rows at all is *not* blocked — blocking on missing consent is explicitly out
  of scope for this phase, and every pre-Phase-15 student has no rows. The check is
  called from `CourseController::show()` (after the visibility check) and
  `LessonController::show()` (after the login redirect) and aborts **403** — at the
  controller level. `app/Support/LessonAccess.php` is a locked file and stays untouched.
- **`progress_tracking` → hard delete.** `LessonProgress::query()->where('user_id', …)->delete()`
  (tenant-scoped global scope) — this student's rows only, never anybody else's.
- **`communication` / `media_processing` → recorded only.** Nothing else changes; the
  row itself is the machine-readable refusal for future features.

Authorization: `UserPolicy::manageConsents` / `manageStudentData` — owner or staff
**and** a student target; anything else is 403 (a student hitting their own screen is
403, asserted with a positive control first). The `{student}` / `{consent}` params
resolve through `TenantScope`, so another tenant's id is 404, never 403.

---

## Student data screens

- **`GET /manage/students/{student}` (data page)** — export link + the erasure form
  (`erase_heading`, `erase_warning`, confirmation field, `erase_submit`).
- **`GET /manage/students/{student}/data/export`** — JSON attachment
  (`Content-Disposition: attachment; filename="student-{id}-data.json"`,
  `JSON_PRETTY_PRINT`). Exactly six top-level keys, every collection scoped to this one
  student:
  `user` (a hand-built array — id, name, email, phone, role, status, guardian columns,
  created_at — deliberately *not* `$user->toArray()`, so `password`/`remember_token`
  cannot leak), `enrolments`, `progress`, `devices`, `consents`, `payments`.
- **`DELETE /manage/students/{student}`** — erasure, in this order, one transaction:
  1. snapshot the student's `enrolments`, `live_class_attendance` and `payments` key
     fields (payments found through the enrolment ids) as JSON arrays;
  2. write the `consent_audit_logs` row (`student_erased`, `user_id`, `actor_id`,
     `metadata`) — the snapshot lands *before* any delete, because the payments are
     about to leave with their enrolments;
  3. delete `lesson_progress`, `user_devices`, `live_class_attendance`;
  4. hard-delete `enrolments` (payments cascade);
  5. anonymise the user: name `Deleted Student`, email `erased-{id}@removed.invalid`,
     phone/guardian columns null, `remember_token` null;
  6. redirect to the People index. Consent rows are untouched.

The confirmation must equal the student's **exact current name** or the request fails
with the `confirmation` key and `consents.errors.confirmation_mismatch`, having changed
nothing. No `.env`, no locked file and no schema constraint was relaxed to make erasure
work — the contact CHECK is still there, fed a sentinel instead.

---

## Phase 10.1 — student payment status (Part B)

### Greeting

`dashboard/student.blade.php` now uses the exact owner/staff page-header pattern:
`:kicker="__('users.dashboard.welcome')" :title="$user->name"` — "Welcome back" over
the student's name. The course list below keeps its own `nav.my_courses` section
heading, so `PublicPagesUiTest`'s "student dashboard shows My courses" assertion keeps
passing for real rather than vacuously.

### The status card (three states)

`DashboardController` resolves one batched payments query through
`App\Support\Payments\PaymentStateResolver::forEnrolments()` and passes
`paymentStatuses` (only enrolments whose course has `fee_paise > 0`; a free course
renders no card at all). Each entry carries the state, the amount (from
`AmountResolver`, never the browser), the formatted date (`reviewed_at ?? submitted_at`,
`d M Y`) and the payment reference. The card renders one `x-alert` per enrolment:

- **Approved → `<x-alert tone="success">` (green):**
  `Payment received for :course on :date — reference :reference`
- **Pending → `<x-alert tone="warning">` (amber):**
  `Payment under review for :course — submitted :date`
- **Nothing submitted / everything rejected → default `x-alert` (brand accent):**
  `Payment needed for :course — :amount` followed inline by the accent-coloured
  `<x-link variant="primary">Pay now</x-link>` into the existing
  `/enrolments/{id}/payment` page.

Strings live in the new `payments.state` group in `lang/en/payments.php`.

### The lesson gate

`LessonController::show()` computes `paymentNeeded` when the viewer is an active,
enrolled student of a course with a fee and `PaymentStateResolver::forEnrolment()` is
not `approved`. When it is true:

- `$videoPlayback` is never built (no `playbackUrl()`, no watermark call), and
- `lessons/show.blade.php` renders the same `payments.state.needed` text + `Pay now`
  link **instead of** the video/youtube branches, and suppresses the mark-as-complete
  form.

The check is controller-level, after `LessonAccess` has already said `ok` —
`LessonAccess` itself is never modified (locked file). Staff/owner, guests, free-preview
viewers without an enrolment, expired enrolments and fee-less courses are all untouched
by construction (each excluded by a named condition). Positive control: after an
approved payment exists, the same URL renders the player again.

---

## Required tests

**44 Step-1 tests** (`tests/Feature/Consents/`):

| File | Tests | Covers |
|------|-------|--------|
| `ConsentSchemaTest.php` | 6 | table/columns, nullability, composite FKs (positive control → per-column refusals), the unique key, guardian columns on `users`, mass-assignment |
| `ConsentRecordingTest.php` | 15 | record for each purpose (owner + staff), student 403, unknown purpose/method, guardian save-on-post, re-grant as second consent, creation contract (guardian + all four purposes, completeness, unknown purpose/method, staff path, non-student discard), import notice + per-student consent |
| `ConsentUiTest.php` | 6 | creation form (notice, guardian fields, every purpose), consents list, withdrawal form, data screen, Help section, all 42+ strings resolve |
| `ConsentWithdrawalTest.php` | 5 | owner + staff withdraw (row survives), reason required, course/lesson 403 after `course_delivery` withdrawal, progress erase after `progress_tracking` |
| `ConsentPermissionsTest.php` | 3 | owner/staff open the screens (incl. export attachment), student cannot (own record included, positive control first), cross-tenant 404 |
| `StudentDataExportTest.php` | 2 | six top keys + counts + user fields; never contains another student |
| `StudentErasureTest.php` | 7 | anonymise + progress/devices deleted + consents survive; hard-delete + snapshot; payment row gone + financial snapshot survives; audit row names student + actor; name confirmation required; other students untouched; staff erase |

**4 Part-B tests** (`tests/Feature/Payments/StudentPaymentStatusTest.php`): greeting,
three card states with cross-checks (each course in exactly one state), lesson gate +
approved-payment positive control, free-course no-card/no-gate.

---

## Files changed (Step 2)

**New (16):** 3 migrations (`2026_10_10_00000{1,2,3}`), `app/Enums/ConsentPurpose.php`,
`app/Enums/ConsentMethod.php`, `app/Models/Consent.php`, `app/Models/ConsentAuditLog.php`,
`app/Http/Controllers/Manage/StudentConsentsController.php`,
`app/Http/Controllers/Manage/StudentDataController.php`, `app/Support/ConsentAccess.php`,
`app/Support/Payments/PaymentStateResolver.php`, `lang/en/consents.php`,
3 views under `resources/views/manage/students/` (`consents`, `withdraw`, `data`),
`tests/Feature/Payments/StudentPaymentStatusTest.php`, this spec.

**Modified (21):** `routes/web.php` (7 routes), `app/Policies/UserPolicy.php`
(`manageConsents`, `manageStudentData`), `app/Http/Controllers/UserController.php`
(store + create), `app/Http/Controllers/UserImportController.php` (confirm),
`app/Http/Controllers/CourseController.php`, `app/Http/Controllers/LessonController.php`,
`app/Http/Controllers/DashboardController.php`, `lang/en/payments.php` (`state` group),
`resources/views/users/create.blade.php`, `resources/views/users/import/preview.blade.php`,
`resources/views/manage/help/index.blade.php`, `resources/views/dashboard/student.blade.php`,
`resources/views/lessons/show.blade.php`, the 3 Step-1 test files corrected below, the
3 existing Users test files (D2 payloads), plus Part C docs (`PRIVACY.md`,
`SECURITY.md`, `README.md`, `PROJECT_BRIEF.md`, `AGENT_RULES.md`).

**Locked files untouched:** `TenantScope`, `TenantBuilder`, `BelongsToTenant`,
`TenantUserProvider`, `LessonAccess`, auth middleware — none of them needed a change,
which is the whole point of layering this at the controller/model level.

---

## Test corrections (approved fixes, not behavioural changes)

### Decision 1 — `StudentErasureTest` email assertion

`->and($student->email)->toBeNull()` → `->toBe('erased-'.$f['student']->id.'@removed.invalid')`
(the test's own fixture id). Reason: D1 (the CHECK constraint). The rest of the
anonymisation assertions are unchanged.

### Decision 4 — two Step-1 erasure tests rewritten

- *"erasure soft-deletes enrolments and live-class attendance instead of dropping them"*
  → *"erasure hard-deletes enrolments and live-class attendance and snapshots them to
  the erasure log"*: asserts the rows are gone (`count() === 0`, no `withTrashed`)
  **and** that their ids appear inside `consent_audit_logs.metadata` under
  `enrolments` / `live_class_attendance`.
- *"erasure keeps payments as financial records but the link resolves only to the
  anonymised row"* → *"erasure removes the payment row with its enrolment but keeps the
  financial record as a snapshot"*: asserts `Payment::find()` is null (the cascade), the
  snapshot carries the payment's id/`amount_paise`/`status`, and the student behind it
  is the anonymised row.

### Step-1 plumbing bug — `ConsentSchemaTest` save without tenant context

The mass-assignment test called `$consent->save()` outside any tenant context; the
locked `BelongsToTenant` saving hook rejects that with
`Cannot save App\Models\Consent without a tenant context`. The `fill()` + `save()` is
now wrapped in `inTenant($f['tenant'], …)` — exactly the pattern
`LiveClassSchemaTest` documents for the same situation ("the point under test is
`fill()`'s guarding, not context handling"). No assertion changed.

### Decision 2 — existing payload changes (before/after)

Every payload below previously relied on `role = student` (explicit or defaulted) with
just name/email(/phone); each now appends the same three-part consent block
(`+ ConsentFixtures::guardianFields() + ['consents' => ConsentFixtures::PURPOSES,
'consent_method' => ConsentFixtures::IMPORT_METHOD]`):

| File | Test (line of the POST) | Before | After |
|------|------------------------|--------|-------|
| `UserManagementTest.php` | cross-tenant `tenant_id` strip (~:249) | `role=student` only | + guardian + 4 consents + method |
| `UserManagementTest.php` | role-ban positive control (~:275) | `role=student` only | same |
| `UserManagementTest.php` | `status` strip (~:314) | no role (→ student) | same |
| `UserManagementTest.php` | `must_change_password` strip (~:339) | no role | same |
| `UserManagementTest.php` | contact-requirement positive control (~:367) | no role | same |
| `UserManagementTest.php` | temp-password login flow (~:396) | `role=student` only | same |
| `UserManagementTest.php` | temp-password format (~:463) | no role | same |
| `UserPolicyTest.php` | owner creates student (~:41) | `role=student` only | same |
| `UserPolicyTest.php` | staff creates student (~:63) | `role=student` only | same |
| `UserPolicyTest.php` | staff-cannot-create-staff control (~:86) | `role=student` only | same |
| `UserPolicyTest.php` | staff-cannot-create-owner control (~:113) | `role=student` only | same |
| `UserPolicyTest.php` | student-cannot-create control (~:139) | no role | same |
| `PeopleScreenUiTest.php` | temp-password flash (~:15) | `role=student` only | same |

Deliberately **not** changed: the `No Contact` test (its `email`/`phone` error keys fire
at the contact check, before consent validation — still exactly two errors), every
`role=staff`/`role=owner` creation attempt (they 403 at `createWithRole`, before
consent validation), and the student-actor 403 (403 before any validation). Two
positive-control payloads that now pass *vacuously* without the fields (they only
assert a redirect) were updated anyway so the control keeps meaning what it says.

---

## Assumptions

- Notice version `v1.0-2026-10-04` is a constant on `App\Models\Consent`; the fixture
  pins the same string independently (the test is the contract, not the app).
- The four purposes and three methods are a fixed enumeration — adding a fifth purpose
  is a schema-adjacent change (lang key + enum + both tests), not config.
- Erasure is owner/staff-triggered; students cannot self-serve export or erasure yet
  (they 403 on their own screens, by design of the Step-1 permissions tests). Whether
  DPDP requires a self-service path is a legal question parked in `PRIVACY.md`
  under *needs legal confirmation*.
- "Blocked on withdrawal" reads the latest grant only; it does not attempt a
  recency contest between multiple withdrawals.

## Unresolved risks

- **Double-submit edge:** two identical grants in the same second collide on
  `(tenant_id, user_id, purpose, granted_at)` → a 500 rather than a friendly
  validation error. Not tested, not likely (the form posts to a redirect), but it is a
  unique-key-level failure mode.
- **C13 half-open:** `ROADMAP.md` still numbers Stage C 14/15/16 with DPDP unnumbered —
  out of scope for this phase, recorded as a §7 drift row in `PROJECT_BRIEF.md`.
- **Sessions of an erased student are not purged** (there is no `sessions` cleanup in
  erase()): the student's current login stays valid until expiry, though the row behind
  it is anonymised. The brief's erasure list did not include sessions; flagged here
  rather than silently added.
- **`payments` rows are destroyed by the cascade** (their key fields survive only in
  the snapshot). A future phase that needs queryable payment history per erased student
  will have to read `consent_audit_logs.metadata` — the snapshot's column set is not
  frozen by any test beyond id/amount/status/enrolment fields.
