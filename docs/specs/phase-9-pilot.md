# Phase 9: Pilot Readiness

**Status:** Complete. `composer test` (761/762, 1 pre-existing MySQL-only test skipped on
SQLite), `composer test:mysql` (730/730), `composer test:js` (29/29), `composer lint`, and
`composer analyse` all pass with zero regressions to Phases 1–8 / P1.
> Test count at time of writing; see README for the current totals.

---

## Goal

A teacher who has just been given the app can, in one sitting on a phone or laptop: add
students from a spreadsheet, hand each one their login over WhatsApp, enrol them in a
course, see who is falling behind, and get answers without calling for help. See
[docs/specs/phase-9-brief.md](phase-9-brief.md) for the full brief this phase implements.

---

## Bulk import

**Routes** (`app/Http/Controllers/UserImportController.php`,
`app/Http/Controllers/UserImportCredentialsController.php`), registered under `/users/import`
**before** `GET/PATCH /users/{user}` in `routes/web.php` — otherwise `/users/import` itself
would be swallowed by the `{user}` wildcard (Laravel would attempt to route-model-bind a
`User` with route key `"import"`), which is exactly the kind of accidental-collision false
positive the Step 1 correction round caught (see git history on
`tests/Feature/Import/PermissionsAndRateLimitTest.php`).

- `GET /users/import/template` — CSV with BOM, `name,phone,email` header, two sample rows.
- `GET /users/import` — upload form.
- `POST /users/import` — preview only, rate limited to 10/hour/user (`throttle:10,60`).
- `POST /users/import/confirm` — creates the OK rows in one transaction.
- `GET /users/import/sheet/{token}`, `.../download`, `POST .../clear` — the credentials sheet.

### Parsing — `App\Support\Import\CsvImportPreviewBuilder`

Strips a UTF-8 BOM if present; if the remaining bytes aren't valid UTF-8, converts from
Windows-1252. Delimiter (comma/semicolon/tab) is auto-detected by counting occurrences in
the header line. Header names are matched case-insensitively and trimmed; accepted aliases:
`mobile`, `mobile number`, `phone number` for phone, `email id`, `e-mail` for email. A
missing `name` column throws (surfaced as a `file` validation error); unknown columns
(including a literal `role` column, tested explicitly) are silently ignored. Blank lines are
skipped before row numbering.

**Row status** (in precedence order): `invalid_phone` (given but not a 10-digit `[6-9]...`
Indian mobile after `User::normalizePhone`) → `invalid_email` (given but fails
`FILTER_VALIDATE_EMAIL`) → `missing_contact` (neither given) → `duplicate_in_file` (phone or
email already seen earlier in this same upload) → `duplicate_in_tenant` (already exists for
a student in this tenant — checked via `User::where(...)`, which is automatically
tenant-scoped through `BelongsToTenant`, so a match in a **different** tenant is never
flagged) → `ok`.

### Row cap — lowered from the brief's default 100

The brief's own instruction: *"measure bcrypt time: if 100 hashes take more than ~20s on the
dev machine, lower the cap and document why."* Measured at the app's actual production
`BCRYPT_ROUNDS` (12, from `.env.example`): **100 `password_hash()` calls took ~44 seconds**
on the dev machine — over double the budget. `CsvImportPreviewBuilder::MAX_ROWS` is
**45** (~15–20s at cost 12), not 100. `tests/Feature/Import/CsvImportParsingTest.php`'s row-
cap tests reference `MAX_ROWS` directly rather than a hardcoded 100, so this stays correct
if the constant is retuned again later.

### Preview and confirm

Nothing is created at preview. The parsed rows plus the chosen `course_id`/`ends_at`/
`payment_note` are kept for 15 minutes in an **encrypted payload in the creator's own
session** (`App\Support\Import\ImportSessionStore`, `Crypt::encryptString` + `session()`),
under an unguessable 40-character token — never in the database. Every read checks the
payload's embedded `tenant_id` and `created_by` against the current request's tenant and
authenticated user (not just PHP session boundaries — see TENANCY.md's "never trust an
ambient scope alone"); a mismatch, a missing token, or an expired one is a 404. A token
already confirmed once is distinguished from a missing/foreign one: a second confirm gets a
friendly `session('error', __('import.already_processed'))` redirect, not a 404.

Confirm runs inside one `DB::transaction()`; a mid-batch failure (e.g. a phone that collides
with a row created between preview and confirm — the `(tenant_id, phone)` unique
constraint) rolls back the whole batch and redirects with a plain error message. On success
the token is marked consumed (kept, not deleted, so a repeat click can show the friendly
message instead of a 404) and a **separate** 15-minute session-encrypted entry is created for
the credentials sheet.

Optional enrolment reuses the same rule as the manual enrolment screen: published course
only (rejected at preview with a `course_id` validation error otherwise), `ends_at`
end-of-day `Asia/Kolkata`, defaulting from `tenants.academic_year_end` when the teacher left
it blank. Every created student is always `role = student`, `status = active`,
`must_change_password = true` — a `role` column in the CSV or a `role` parameter on the
confirm request is always ignored, for both owner- and staff-initiated imports (guarded
fields are never read from request input at all, not merely blocked after the fact).

### Credentials sheet

`GET /users/import/sheet/{token}` — creator only, `Cache-Control: no-store, private`,
`X-Robots-Tag: noindex`. After the 15-minute window, or after "Clear now", the same page
shows a friendly "no longer available" message (200, not 404) **to the rightful owner only**
— `ImportSessionStore::clearKeepingIdentity()` overwrites the payload with just
`tenant_id`/`created_by` (no `created_at`, no passwords), so the ownership check still works
correctly on the next request while the temporary passwords themselves are gone immediately.
WhatsApp link (`https://wa.me/91<phone>?text=<url-encoded message>`) appears only for rows
with a phone; "Copy message" always appears. CSV download and the credentials sheet both run
every cell through `App\Support\Csv\CsvFormulaGuard` (shared with progress export).

### Content-based file check

`extensions:csv` alone isn't "content-based," and Laravel's `mimes:` rule (finfo/MIME
sniffing) turned out too fragile for this: a legitimate CSV row containing markup-like text
in a cell (tested explicitly — a name of `<script>alert(1)</script>`) can make content
sniffers guess `text/html` and wrongly reject a real CSV. Instead, `UserImportController`
checks the **share of raw control bytes** in the first 8KB directly (anything above 1% is
treated as binary) — genuine text/CSV content essentially never has any; random binary data
reliably does.

---

## Shared enrolment logic — `App\Actions\EnrolStudentAction`

Extracted from `Manage\EnrolmentController::store`'s per-user loop (create new / reactivate
existing / no-op if already active) into one `__invoke(Course $course, User $student, Carbon
$startsAt, ?Carbon $endsAt, ?string $paymentNote, int $enrolledBy): Enrolment`, used by both
the manual enrolment screen and confirmed imports. `tests/Feature/Enrolments` (the existing,
protected suite) runs untouched and green — no message or behaviour on that screen changed.

---

## Login page help line

Below the login form: the generic "ask your teacher" line always
(`auth.help.generic`); when `tenants.contact_phone` is set, also a `tel:` link and a
`https://wa.me/91<phone>` link (`auth.help.contact`, `auth.help.whatsapp`) — no credentials
in either link, no query string on the wa.me link beyond the phone number.

---

## Progress export

`GET /manage/courses/{course}/progress/export` — same `viewProgress` authorization, same
`filter`/`sort` query parameters, as the existing progress page (query-building logic shared
via a private method, not paginated for the export). Streamed CSV (UTF-8 BOM), columns
`name, phone, email, enrolled_since, lessons_completed, lessons_total, percent, last_active,
enrolment_status`. Filename `progress-{course-slug}-{YYYY-MM-DD}.csv` (Symfony's
`Content-Disposition` builder only quotes a filename when the raw name needs it — a plain
slug-date `.csv` name doesn't, and is legitimately sent unquoted). Every cell goes through
`CsvFormulaGuard`.

---

## Getting-started checklist and Help page

`App\Support\GettingStartedChecklist::forTenant()` — six cheap, tenant-scoped `exists()`
checks (create a course, add a lesson, add a note or video, add students, enrol a student,
set logo + contact phone), each with its own link. Owner-only; hidden once all six are done
or after "Hide" (`tenants.getting_started_dismissed_at`, guarded, set only via
`DashboardController::dismissGettingStarted`, gated by the existing owner-only
`TenantPolicy::manageSettings`).

`/manage/help` (owner and staff) — ten short plain-language sections, all copy in
`lang/en/help.php`. "Help" link in the header for anyone who can manage users.

---

## People page on phones

Below `sm`: one card per person (name, role/status badges, phone/email, devices line, and
`resources/views/users/_actions.blade.php` — the same partial the table's action column
includes from `sm` up), so the action set can never drift between the two renderings.

---

## Mobile guard tests

`Tests\Support\MobileSafetyChecker` (pure `violations()`/`isSafe()`, no framework
dependency) — unit tested in `tests/Unit/Support/MobileGuards` against synthetic HTML, then
run against every key tenant page in `tests/Feature/MobileGuards/MobileGuardTest.php`.
`docs/PILOT_CHECKLIST.md` is the human 360px walkthrough.

---

## Schema

`database/migrations/2026_10_07_000001_add_getting_started_dismissed_at_to_tenants_table.php`
— `tenants.getting_started_dismissed_at timestamp nullable`, not in `Tenant::$fillable`
(guarded by omission, matching the existing convention on that model).

---

## Assumptions

- **Preview token TTL**: the brief only specifies 15 minutes for the credentials sheet;
  the preview/confirm token uses the same 15-minute window (`ImportSessionStore`'s
  `$ttlMinutes` is a constructor parameter, not hardcoded twice), on the reasoning that a
  preview a teacher hasn't confirmed within 15 minutes should require a fresh upload rather
  than silently staying valid indefinitely in session.
- **Confirm reads `course_id`/`ends_at`/`payment_note` from the stored preview payload**,
  not fresh from the confirm request — set once at upload time. The brief's "the options
  (course to enrol in, end date, payment note)" appearing on the preview page is read here
  as *displaying* the already-chosen options, not re-collecting them at confirm time.
- **`EnrolStudentAction`'s interface** was corrected once during Step 2 from Step 1's
  placeholder (which omitted `startsAt`) after checking that the manual screen's `starts_at`
  is a teacher-chosen date, not "now" — see the Step 1→2 diff on
  `tests/Unit/Actions/EnrolStudentActionTest.php`.
- **Row cap is 45, not the brief's default 100** — see "Row cap" above.
- **DEPLOY.md unchanged** — this phase adds no new external service, environment variable,
  or deploy step; everything (session storage, CSV streaming) uses infrastructure every
  earlier phase already depends on.

## Unresolved risks

- **Bcrypt timing was measured with a synthetic loop** (`password_hash()` in a standalone
  PHP script), not by timing an actual 45-row import through the full HTTP/DB stack — real
  request overhead means the true end-to-end time for a full import is somewhat higher than
  the raw hashing number quoted above, though still bounded by the same cost-12 hash rate.
- **Preview and credentials-sheet tokens live in the PHP session**, so switching browsers or
  clearing cookies mid-flow loses an in-progress preview (must re-upload) or an
  unviewed/undownloaded credentials sheet before it would otherwise have expired — no
  server-side recovery path exists, by design (nothing sensitive is meant to outlive the
  session).
