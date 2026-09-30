# Phase 9 Brief — Pilot Readiness (bulk onboarding, credential sharing, teacher help)

Read CLAUDE.md, AGENT_RULES.md, TENANCY.md, SECURITY.md, docs/specs/phase-3-tenant-auth.md, docs/specs/phase-4-courses.md, docs/specs/phase-7-progress.md and docs/specs/phase-8-devices.md first. Earlier phases are locked: do not modify TenantScope, TenantBuilder, BelongsToTenant, TenantUserProvider, LessonAccess rules, or the existing auth middleware unless a test proves a bug — stop and report first. Work in THIS session, no background agents. Never commit the archive-42JxzH folder. Never use saveQuietly()/withoutEvents() on tenant-owned models. Do not leave debug code, scratch files or background processes behind.

## Goal

A teacher who has just been given the app can, in one sitting on a phone or laptop: add 50 students from a spreadsheet, hand each one their login over WhatsApp, enrol them in a course, see who is falling behind, and get answers without calling you.

Out of scope: payments, self-service password reset, Hindi translation (planned separately, needs a native reviewer), SMS sending, email sending, parent accounts.

## Product decisions (already made)

1. **Bulk import from CSV**, students only, by owner or staff (same permission as creating a student today). Two steps: **preview** (nothing is created) then **confirm**.
2. **Optional enrolment during import:** pick one published course, an optional end date (pre-filled from the institute's academic year end), and a payment note. Reuse the existing enrolment rules.
3. **Credentials are shared through the teacher's own WhatsApp**, not sent by us: after creation, a sheet lists each student with a "WhatsApp" button (a `https://wa.me/91<phone>?text=…` link with a ready message) and a "Copy message" button. Nothing is sent automatically.
4. **The credentials sheet is available for 15 minutes** to the user who created the import (never to anyone else), then it is gone. Temporary passwords are never stored anywhere in plain text: keep them only in the creator's session as an encrypted payload, served with `Cache-Control: no-store`. A "Clear now" button removes it early.
5. **Login page help line:** "Forgot your password or can't log in? Ask your teacher to reset it." plus, when the institute has a contact phone in Settings, a tap-to-call and tap-to-WhatsApp link.
6. **Progress CSV export** of a course (teachers use it for parent meetings).
7. **Getting-started checklist** on the owner dashboard and an **in-app Help page** in plain language — this replaces a separate PDF guide.
8. **People page works on a 360 px phone** (cards instead of a 7-column table).

## Bulk import (routes under `/users/import`, tenant domains, auth:tenant + active.tenant.user + device.limit + must.change.password, same middleware stack as /users)

- **Template:** `GET /users/import/template` returns a CSV (UTF-8 with BOM) with headers `name,phone,email` and two sample rows.
- **Upload:** `POST /users/import` (multipart). Rules: `.csv` only, max 1 MB, max **100 data rows** (measure bcrypt time: if 100 hashes take more than ~20 s on the dev machine, lower the cap and document why). Content-based check (not just the extension). Handle UTF-8 with or without BOM; if the bytes are not valid UTF-8, try Windows-1252; auto-detect comma / semicolon / tab from the header line. Header names are case-insensitive, trimmed; accepted aliases: `mobile`, `mobile number`, `phone number` for phone; `email id`, `e-mail` for email; extra columns are ignored; a missing `name` column is an error.
- **Row validation (no rows are created yet):** name required (max 255, trimmed, collapsed internal whitespace); phone normalised with `User::normalizePhone` and, for imports, must be a valid Indian mobile (10 digits, starting 6–9) when given; email lowercased and valid when given; at least one of phone/email; duplicates inside the file (by phone or email) flagged on the second occurrence; duplicates already in THIS institute flagged (a phone or email that exists in another institute is NOT a duplicate). Blank lines are skipped.
- **Preview page:** counts (ok / problems), a table with row number, values and a plain-language status per row ("Phone number is not valid", "Already exists in your students", "Repeated in this file"), the options (course to enrol in, end date, payment note), and a **Create N students** button that only processes the OK rows. Parsed rows are kept in the creator's session under an unguessable token (encrypted payload); no second upload needed.
- **Confirm:** `POST /users/import/confirm` with the token. The token is consumed atomically (a double click or refresh creates nothing twice: "This import was already processed"). Creation runs in one transaction: each student is role student, status active, must_change_password true, a generated temporary password (existing generator), and — if a course was chosen — enrolled via the shared enrolment logic (published course only; ends_at end-of-day Asia/Kolkata like the enrolment screen). If anything fails the whole batch rolls back with a plain error.
- **Credentials sheet:** `GET /users/import/sheet/{token}` — creator only, 15-minute window, `Cache-Control: no-store`, `X-Robots-Tag: noindex`. Table: name, login (phone or email), temporary password, WhatsApp button (only for rows with a phone), Copy message button. A **Print** button (print CSS hides everything else) and a **Download CSV** button (same window, same rules, formula-safe cells). "Clear now" deletes it. After expiry or clearing: friendly "This sheet is no longer available. You can reset a student's password to get a new one."
- **WhatsApp message** (lang file, editable later): "Hello :name, your login for :institute — website: :url — login: :login — temporary password: :password. You will be asked to choose a new password when you first log in." Built server-side, URL-encoded; the wa.me number is `91` + the 10-digit phone.
- Import endpoints are rate limited (10 imports per hour per user).
- Link "Import from spreadsheet" from the Add person page and the People page.

## Shared enrolment logic

Extract the enrolment creation from `Manage\EnrolmentController::store` into an action class used by both the controller and the import. Behaviour and messages of the existing screen must not change: all existing enrolment tests stay green untouched. If any existing test needs to change, STOP and report which.

## Login page help line

On the tenant login page, below the form: the generic line always; when `tenants.contact_phone` is set, also "Call or WhatsApp :phone" with `tel:` and `https://wa.me/91<phone>` links (no credentials in any link). Text via lang files.

## Progress export

`GET /manage/courses/{course}/progress/export` — owner/staff (same authorization as the progress page), respects the same `filter` and `sort` query parameters but is NOT paginated. CSV (UTF-8 with BOM, streamed), columns: name, phone, email, enrolled_since, lessons_completed, lessons_total, percent, last_active (date, Asia/Kolkata, or empty), enrolment_status. Filename `progress-{course-slug}-{YYYY-MM-DD}.csv`. **Formula injection:** any cell beginning with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with a single quote. Bounded query count regardless of student count.

## Getting-started checklist and Help page

- Owner dashboard card "Getting started" (owner only) with six steps and live completion: create a course; add a lesson to it; add a note or video; add students; enrol a student; set your logo and contact phone. Each step links to the right screen. Hidden once all are done or after "Hide" (`tenants.getting_started_dismissed_at`, new migration). Counts are tenant-scoped and cheap (no N+1).
- `/manage/help` (owner and staff): short plain-language sections — adding one student; adding many students from a spreadsheet; sharing logins on WhatsApp; enrolling; uploading a lecture from a phone (file size and format tips); free preview vs paid lessons; who has gone quiet; resetting a password; what "devices per student" means; what to do when a student cannot log in. All text in a lang file, written for a busy teacher (short sentences, no jargon), print-friendly. "Help" link in the owner/staff header.

## People page on phones

Below the `sm` breakpoint render each person as a card (name, role and status badges, phone/email, devices line, and the action buttons the viewer is allowed). From `sm` up keep the table. Both renderings come from ONE shared partial for the action buttons so they cannot drift; permissions unchanged (buttons appear only when the policy allows).

## Mobile guard tests (Pest, rendered HTML)

On the key tenant pages (login, dashboard, People, import preview, credentials sheet, progress, Help, lesson page): every `<table>` sits inside an `overflow-x-auto` wrapper or is hidden below `sm`; no class attribute contains a fixed width above 340 px; the viewport meta tag is present. Also write `docs/PILOT_CHECKLIST.md`: a step-by-step 360 px walkthrough of every screen for a human tester, with a column to note problems.

## Schema

`tenants.getting_started_dismissed_at` timestamp nullable (new migration). Nothing else. Guarded / never mass-assignable from request input.

## Required tests (positive controls in every negative test; real tenant hosts; freshRequestCycle() where auth must be re-resolved)

Import parsing and validation: header detection (aliases, case, extra columns, missing name), BOM/UTF-8/Windows-1252, comma/semicolon/tab, blank lines, 100-row cap and 1 MB cap, non-CSV/binary rejected by content; every row-status rule (bad phone, 5xxxxxxxxx rejected, +91/0-prefixed accepted and normalised, bad email, neither phone nor email, duplicates in file, duplicates in this tenant, the SAME phone in another tenant is not a duplicate); Devanagari names survive; HTML/script in names is escaped on every page.
Preview and confirm: nothing is created at preview; confirm creates exactly the OK rows with role student, active, must_change_password, hashed passwords; guarded fields cannot be set from the request; token consumed atomically (second confirm creates nothing and shows the message); another user's token or an expired one is refused; another tenant's session cannot see it; whole-batch rollback on a forced failure; optional enrolment (published course only; draft/archived rejected; ends_at end-of-day Kolkata; default from academic year end; already-enrolled handled) and that existing enrolment behaviour is unchanged.
Credentials sheet: available to the creator for 15 minutes (time travel) and then gone; another user 404/403; no-store and noindex headers; passwords never appear in the database, logs or any other page; WhatsApp link only for rows with a phone, number formatted `91XXXXXXXXXX`, message URL-encoded; CSV download formula-safe; Clear now works.
Permissions: owner and staff can import; student 403; guest redirected (each with a positive control); staff cannot create non-students (rows are always students); rate limit at the 11th import.
Login help: generic line always; phone links only when configured; no credentials in links.
Progress export: correct rows and columns; filters/sort applied and not paginated; formula-injection neutralised (a student named `=HYPERLINK(...)`); permissions; tenant isolation; query count bounded with 50 students.
Checklist/help/dashboard: each step's completion logic and links; dismiss persists and is owner-only; Help page permissions and header link; all strings via translation keys.
People page: card and table renderings both present, identical action sets per policy (owner vs staff vs a row the viewer may not manage), shared partial used.
Mobile guard tests as above.

## Process

Step 1: tests only, run them, paste real output. Target: 0 Phase 9 tests passing; every earlier test still passing; name the missing piece per group; state the arithmetic (new tests = total now − previous total; failing + erroring must equal it); list every currently failing (not errored) test with its message. Add new test folders to phpunit.mysql.xml as <directory> entries. Commit "Phase 9: tests (Step 1)". STOP for review.

Step 2 (after approval): implement until all tests pass on BOTH suites plus `composer test:js`. Tests are the contract: do not edit an existing test without asking; if one seems wrong STOP and list every such test together with a proposed fix (before/after). Run npm run build, php artisan route:cache then route:clear, and `php artisan optimize:clear` at the end.

## Report

Raw final lines of composer test / test:mysql / test:js / lint / analyse; `git diff --stat tests/` against the Step 1 commit with each change explained; measured bcrypt time for 100 rows; assumptions; unresolved risks. Spec to docs/specs/phase-9-pilot.md; update README, SECURITY.md (session-held encrypted credentials, 15-minute window, CSV formula injection, import limits) and DEPLOY.md if anything changes. Commit "Phase 9: pilot readiness", push, tag phase-9.
