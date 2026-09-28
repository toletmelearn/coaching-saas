# Phase 4: Courses, Lessons, Notes and Manual Enrolment

**Status:** Complete
**Phase Goal:** Tenant-owned courses with chapters/lessons, free-preview YouTube video,
private PDF notes, and manual (cash/UPI) enrolment — all access-controlled through a single
`LessonAccess` service and enforced by composite foreign keys, exactly as TENANCY.md requires.

---

## Overview

Phase 4 is the first content domain built on top of Phases 1–3 (`TenantContext`,
`TenantScope`, `BelongsToTenant`, tenant-scoped auth). It adds:

- `courses` → `chapters` → `lessons` → `lesson_attachments`, all tenant-owned with composite
  foreign keys per TENANCY.md.
- `enrolments`, linking a student to a course with a start/end date, status, and a payment
  note (manual UPI/cash — no gateway integration this phase).
- A single access-control service (`App\Support\LessonAccess`) that decides, for any viewer
  (guest, student, owner, staff), whether a course/lesson is visible, forbidden, or should
  redirect to login — used identically by the course page, lesson page, and attachment
  download, so the rule is defined exactly once.
- Public catalogue and lesson routes (no login required for free-preview content).
- An owner/staff management area (`/manage/...`) for courses, chapters, lessons, notes, and
  enrolments.
- YouTube URL parsing restricted to free-preview lessons only, embedded via
  `youtube-nocookie.com`.
- Private PDF notes served only through an authorised controller, never a public path.
- Seeder content extending the Phase 3 demo tenant (local/testing only).

---

## Schema

Migrations: `database/migrations/2026_09_29_00000{1..5}_create_{courses,chapters,lessons,
lesson_attachments,enrolments}_table.php`.

All five tables use `$table->tenantKeys()` (unique `(tenant_id, id)`) and every reference to
another tenant-owned table is a composite FK `(tenant_id, x_id) → parent(tenant_id, id)`, via
either the `tenantForeign()` macro (Phase 2) or an explicit `$table->foreign([...])
->references([...])->on(...)` call when the delete rule isn't the macro's default
`restrictOnDelete()`:

- `courses.tenant_id` → `tenants`, `restrictOnDelete()`. `created_by` → `users`, nullable
  (see "Mass assignment" below), `restrictOnDelete()` via `tenantForeign()`.
- `chapters.course_id` → `courses`, **`cascadeOnDelete()`** (explicit FK, not the macro,
  since the macro hardcodes `restrictOnDelete()`) — moot in practice since courses are never
  hard-deleted (archived instead), but matches the brief's schema exactly.
- `lessons.course_id` → `courses` via `tenantForeign()` (`restrictOnDelete()`) — denormalised
  onto every lesson for fast access checks and scoped route bindings, without a join through
  `chapters`. `lessons.chapter_id` → `chapters`, `restrictOnDelete()` (explicit FK).
- `lesson_attachments.lesson_id` → `lessons`, **`cascadeOnDelete()`** (explicit FK) — belt and
  braces alongside the application-level "delete a lesson removes its files from disk" logic
  (`Manage\LessonController::destroy()`); the DB cascade guarantees the *rows* are gone even
  if a lesson is ever deleted through a path other than that controller action.
- `enrolments.course_id` and `.user_id` → `courses`/`users` via `tenantForeign()`.
  `enrolled_by`/`revoked_by` → `users`, nullable, explicit FK (`restrictOnDelete()`) — the
  `tenantForeign()` macro assumes an `..._id`-suffixed column name convention that doesn't
  fit these two.

`courses.slug`: `unique(tenant_id, slug)`, auto-generated from `title` via `Str::slug()` on
create if not supplied (`Course::uniqueSlug()`), with `-2`, `-3`, … appended on collision.
Editable afterwards (`Manage\CourseController::update()` accepts a `slug` field), still
enforced unique per tenant by the same DB constraint.

### Mass assignment

Per the brief: `status`, `published_at`, `created_by` (courses/lessons), `enrolled_by`,
`revoked_at`, `revoked_by` (enrolments), `disk`, `path` (attachments), and `tenant_id`
(everywhere) are **not** in any model's `$fillable` — they're set only via `forceFill()` in
controllers/factories, exactly as Phase 3 established for `User`. `created_by` and
`enrolled_by` are nullable columns specifically so the "guarded field mass-assignment falls
back to null" test pattern (established in Phase 3's `UserSchemaTest`) holds: there's no
natural model-level default for "who created this", unlike `role`/`status` which do have one.

### `lesson.course_id` must equal its chapter's course

Not a single-table DB constraint (no CHECK can compare across a foreign key), so it's an
application-level invariant: `Lesson`'s `saving` hook looks up the chapter
(`tenant_id`-scoped, bypassing the global scope since we already know the tenant) and throws
`App\Exceptions\LessonCourseMismatchException` if `chapter->course_id` disagrees with the
lesson's own `course_id`. Registered in `Lesson::booted()`, which Eloquent calls *after*
`BelongsToTenant`'s own `saving` hook (registered during `boot()` via `bootTraits()`), so
`tenant_id` is already auto-filled by the time this check runs.

---

## `LessonAccess` — the single access-control source of truth

`app/Support/LessonAccess.php`. Three entry points, used identically by the public
`CourseController`, `LessonController`, and `LessonAttachmentController` — no controller
re-implements any part of the rule:

- `courseIsVisible(?User $user, Course $course): bool` — owner/staff always `true`.
  Published → `true` for everyone. Draft → `false` for everyone else. Archived → `true` only
  if the viewer has a currently-valid enrolment.
- `lessonAccess(?User $user, Lesson $lesson): string` — returns one of `'ok'`,
  `'redirect_login'`, `'forbidden'`, `'not_found'`. Owner/staff always `'ok'`. A non-published
  lesson, or a lesson whose course is draft, is always `'not_found'` (never `'forbidden'` —
  hidden things 404, per the brief's rule 10). An archived course requires a valid enrolment
  or it's `'not_found'`. A published course + free-preview lesson is `'ok'` for anyone
  (including guests). A published course + paid lesson: guest → `'redirect_login'`; student →
  `'ok'` if validly enrolled, else `'forbidden'`.
- `hasValidEnrolment(User $user, Course $course): bool` / `findEnrolment(...)` — "valid" per
  the brief's exact definition: `status === Active AND starts_at <= now AND (ends_at is null
  OR now < ends_at)`, implemented as `Enrolment::isValidNow()`.

The same `lessonAccess()` call gates `LessonAttachmentController::show()` — an attachment is
never reachable unless its lesson would itself be viewable, so there is no separate
"attachment access" rule to keep in sync with the lesson rule.

---

## Routing

`routes/web.php`:

- **Public** (inside `require.tenant`, outside `auth:tenant` — guests allowed):
  `GET /courses`, `GET /courses/{course:slug}`,
  `GET /courses/{course:slug}/lessons/{lesson}` (`->scopeBindings()`),
  `GET /courses/{course:slug}/lessons/{lesson}/attachments/{attachment}` (`->scopeBindings()`).
  `scopeBindings()` resolves `{lesson}` through `$course->lessons()` and `{attachment}`
  through `$lesson->attachments()`, so a lesson/attachment id that belongs to a *different*
  course under the *same* tenant 404s (Laravel's route-model-binding scoping convention:
  child parameter name → pluralised relation method on the parent). Cross-tenant 404s are a
  separate, already-covered mechanism: `Course`/`Lesson`/`LessonAttachment` all use
  `BelongsToTenant`, so implicit route-model binding queries through the tenant global scope
  regardless of nesting (TENANCY.md, "Tenant-aware route model binding").
- **Management** (`/manage/...`, nested inside the same `auth:tenant` → `active.tenant.user`
  → `must.change.password` stack Phase 3 already established, alongside `/users`): courses
  (index/create/store/edit/update/publish/unpublish/archive), chapters
  (store/move-up/move-down/destroy), lessons (store/update/publish/unpublish/move-up/
  move-down/destroy), attachments (store/destroy), enrolments
  (index/store/revoke/reenrol). Chosen prefix — the brief fixes exact paths only for the
  public routes; `/manage` avoids colliding with the public `/courses` catalogue while
  keeping every management URL flat and predictable.

---

## Authorization (`CoursePolicy`, `EnrolmentPolicy`)

Reuses `UserRole`'s Phase-3 helper methods rather than inventing new ones:
`canManageCourses()` (owner or staff — the brief's "who can reach the management area /
touch chapters, lessons, notes" boundary) and `isOwner()` (course-level actions: create,
publish, archive, and all enrolment actions).

| Ability | Rule |
|---|---|
| `viewAny` / `view` (Course) | owner or staff |
| `create`, `publish`, `archive` (Course) | owner only |
| `manageContent` (Course) | owner or staff — gates every chapter/lesson/attachment action |
| `manageEnrolments` (Course) | owner only — gates the enrolments screen and bulk-enrol |
| `revoke`, `reenrol` (Enrolment) | owner only |

Students never pass any of these gates (`UserRole::Student->canManageCourses()` is `false`),
so every management route 403s for a student regardless of which specific ability is checked
— matching the brief's "student cannot reach any management route" requirement without a
separate blanket check.

---

## YouTube

`App\Support\YoutubeUrlParser::parseVideoId(string $url): ?string` — pure, DB-free, unit
tested directly (`tests/Unit/Support/YoutubeUrlParserTest.php`). Accepts (with or without
`https://`/`www.`): `youtube.com/watch?v=ID` (extra query params ignored),
`youtu.be/ID`, `youtube.com/shorts/ID`, `youtube.com/embed/ID`, `m.youtube.com/...`. Returns
`null` for anything else, including a syntactically-plausible non-YouTube host or a
malformed/short id.

Enforcement lives in `Manage\LessonController`:

- `resolveYoutubeVideoId()` throws a `ValidationException` on `youtube_url` if a URL is
  supplied while `is_free_preview` is false (or parses to nothing), and on `store()`/
  `update()` never persists a `youtube_video_id` for a non-free lesson.
- `update()` separately checks: if the lesson already has a `youtube_video_id` and the
  request would turn `is_free_preview` off *without* also clearing/replacing the video URL,
  it throws on `is_free_preview` telling the teacher to remove the video first — the video is
  never silently dropped.

The lesson view (`resources/views/lessons/show.blade.php`) embeds via
`https://www.youtube-nocookie.com/embed/{id}`, never the tracking `youtube.com/embed/` host.

---

## Attachments

`Manage\LessonAttachmentController::store()`: `mimes:pdf` (Laravel's `mimes` rule inspects
the file's actual content via `finfo`, not just the extension) + `max:` computed from
`config('coaching.max_attachment_mb', 20)` in KB. Stored on the `local` disk (private —
`storage/app/private`, **not** the `public` disk, and nothing symlinks it into the public
webroot) at `tenants/{tenant_id}/lessons/{lesson_id}/{random(20)}.pdf`; `original_name` kept
separately for display. `LessonAttachmentController::show()` (public) streams the file with
`Content-Type: application/pdf`, `Content-Disposition: inline`, `X-Content-Type-Options:
nosniff` — after running the exact same `LessonAccess::lessonAccess()` check the lesson page
itself uses.

Deleting a lesson (`Manage\LessonController::destroy()`) collects its attachments, deletes
the DB row inside a transaction, then deletes the physical files from disk *after* the
transaction commits — so a rolled-back delete never leaves an orphaned physical file deleted
out from under a still-existing DB row.

---

## Enrolment

`Manage\EnrolmentController::store()` (bulk enrol): validates `user_ids` (each must resolve,
tenant-scoped, to a `role === Student` + `status === Active` user — staff/owner/disabled ids
fail validation under the `user_ids` key, added via a `Validator::after()` hook rather than a
per-item `user_ids.*` rule, specifically so the error lands on the field the UI actually
shows one message for, not a numerically-indexed sub-key), `starts_at` required,
`ends_at` nullable + `after:starts_at`. Enrolling into a non-published course is a **403**
(`abort_if`), not a validation error — matches the brief's "cannot enrol into a draft or
archived course" being a permission boundary, not a form mistake.

Per user id: if no enrolment row exists yet, one is created (`enrolled_by` forceFilled). If
an active row already exists, it's skipped and counted toward the "already enrolled" total —
never a duplicate insert (also backstopped by the DB's `unique(tenant_id, course_id,
user_id)`). If a **revoked** row exists, it's reactivated in place (status → active,
`revoked_at`/`revoked_by` cleared, dates/payment note updated) — the same "re-enrolling a
revoked student reactivates the same row" rule the brief states for the dedicated
`reenrol()` action applies here too, since the unique constraint makes a second row
impossible regardless of which endpoint is used. A flashed `enrolment_summary` session string
reports "`X` enrolled, `Y` already enrolled".

`revoke()`/`reenrol()` are single-row, owner-only, straightforward `forceFill()`s of the
guarded status/revoked_* fields.

---

## Ordering

Chapters and lessons each auto-append (`position = max(position for the same parent) + 1`)
on create when no explicit `position` is supplied, via a `creating` hook (`Chapter::booted()`
/ `Lesson::booted()`/`LessonAttachment::booted()`). Move up/down (`Manage\ChapterController`/
`Manage\LessonController`) find the adjacent sibling by position and swap the two rows'
`position` values inside a `DB::transaction()` — a plain two-row update, no unique constraint
on `(parent, position)` to conflict with mid-swap.

---

## Seeding

`database/seeders/TenantSeeder.php` (same local/testing-only guard as Phase 3): published
course "Physics – Class 11" with 2 chapters ("Mechanics", "Optics") and 4 lessons — one
free-preview lesson with a YouTube id, one left as a draft, and two paid lessons each with a
sample PDF attachment (real bytes written to the `local` disk, not just a DB row) — plus the
demo student enrolled with `ends_at = null` (no expiry). A second course, "Chemistry – Class
11", is seeded as a draft (exercises "draft course is invisible to everyone but owner/staff"
in a real dev environment, not just in tests). See README "Local credentials".

---

## Files changed

**Enums:** `app/Enums/{CourseStatus,LessonStatus,EnrolmentStatus}.php`.

**Exception:** `app/Exceptions/LessonCourseMismatchException.php`.

**Models:** `app/Models/{Course,Chapter,Lesson,LessonAttachment,Enrolment}.php`.

**Factories:** `database/factories/{Course,Chapter,Lesson,LessonAttachment,Enrolment}Factory.php`.

**Migrations:** `database/migrations/2026_09_29_00000{1..5}_*.php`.

**Support:** `app/Support/{LessonAccess,YoutubeUrlParser}.php`.

**Policies:** `app/Policies/{CoursePolicy,EnrolmentPolicy}.php` (registered in
`AppServiceProvider`).

**Controllers:** `app/Http/Controllers/{CourseController,LessonController,
LessonAttachmentController}.php` (public), `app/Http/Controllers/Manage/{CourseController,
ChapterController,LessonController,LessonAttachmentController,EnrolmentController}.php`
(management).

**Routes:** `routes/web.php`.

**Views:** `resources/views/courses/{index,show}.blade.php`,
`resources/views/lessons/{show,forbidden}.blade.php`,
`resources/views/manage/courses/{index,create,edit}.blade.php`,
`resources/views/manage/enrolments/index.blade.php`.

**Lang:** `lang/en/{courses,lessons}.php`.

**Config:** `config/coaching.php` (`max_attachment_mb`).

**Seeder:** `database/seeders/TenantSeeder.php`.

**Test config:** `phpunit.mysql.xml` (added `Courses`/`Enrolments` suites).

**Documentation:** `README.md` (demo course content), this file.

---

## Test corrections (Step 1 → Step 2, both approved-pattern fixes, not behavioral changes)

Two one-line bugs in my own Step 1 tests, found once real code existed to run them against —
same category as Phase 3's approved corrections, not a disagreement about desired behavior:

1. **`CourseSchemaTest`, mass-assignment test** — a nested closure referenced `$tenant` (for
   `$viaMassAssignment->tenant_id` assertion) without capturing it via `use ($tenant, ...)`,
   an undefined-variable bug, not a design question.
2. **`CourseSeederTest`** — `paidLessons` counted "not free-preview" lessons, which
   accidentally included the draft lesson too (3, not 2). Redefined as "not free-preview AND
   has an attachment", matching the brief's actual "2 paid lessons with a sample PDF".

`git diff --stat` against the Step 1 commit (`a92ad25`) confirms only these two files changed
by 2 lines total; every other Step 1 test file is byte-for-byte what was committed before any
implementation code existed.

---

## Assumptions

- **Management route prefix `/manage/...`** — not specified by the brief (which only fixes
  the public catalogue/lesson paths); chosen to avoid colliding with `/courses`.
- **`enrolment_summary` session key and its message format** — UI detail not specified by the
  brief.
- **Slug generation** uses `Str::slug()` plus a numeric collision suffix; the brief doesn't
  specify the exact slugification algorithm.
- **PDF viewer** is just a link to the attachment URL (browser's native inline PDF viewer via
  `Content-Disposition: inline`), not an embedded `<iframe>`/`<embed>` — simplest choice that
  satisfies "viewable in the browser" without adding a JS PDF-rendering dependency.

## Unresolved risks

- **No progress tracking or watch-time is recorded** — explicitly out of scope per the brief,
  flagging only so it isn't mistaken for an oversight later.
- **`lesson_attachments` cascade-deletes with `lessons`, but `chapters` cascade-deletes with
  `courses`** even though courses are never hard-deleted in practice — the cascade is inert
  today but would silently wipe chapters/lessons if a future phase ever did add course
  deletion without revisiting this. Flagged for whoever touches course deletion later.
- **Attachment content is not virus-scanned** — `mimes:pdf` checks the file signature, not
  file safety. Acceptable for a teacher-only upload path in this phase; would need
  reconsidering if upload access ever widened.
