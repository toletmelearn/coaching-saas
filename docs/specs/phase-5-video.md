# Phase 5: Protected Video for Lessons

**Status:** Complete. `composer test` (408/409, 1 pre-existing MySQL-only test skipped on
SQLite), `composer test:mysql` (377/377), `composer lint`, and `composer analyse` all pass
with zero regressions to Phases 1–4.

---

## Correction to the original brief

The Phase 5 brief as originally drafted described the `bunny` driver as "one
platform-owned video library; each tenant gets a Bunny 'collection' for organisation
only — isolation is enforced in our database, never by Bunny," with a single app-level
`BUNNY_STREAM_LIBRARY_ID` / `BUNNY_STREAM_API_KEY` in `.env`.

This directly conflicts with [VIDEO.md](../../VIDEO.md) ("One Bunny Stream library per
tenant... Library credentials (library id, API key) are stored per tenant, encrypted")
and [AGENT_RULES.md](../../AGENT_RULES.md) invariant #13 ("One Bunny Stream library per
tenant. Do not share a library across tenants to save setup effort — it breaks both
isolation and per-tenant usage metering."). Per `AGENT_RULES.md` rule 20, the invariant
wins. The user confirmed: follow the documented per-tenant-library design. Everything
below reflects that correction; the rest of the original brief (schema shape, upload
flow, watermark, fullscreen, sync command) is unchanged.

**Corrected design, per the user's explicit clarification:**

- The platform owns a single Bunny **account**. Only the account-level API key lives in
  `.env` (`BUNNY_STREAM_ACCOUNT_API_KEY`) — distinct from any per-tenant key.
- Each tenant gets its own Bunny Stream **library** (own library id, own library API
  key, own token security key), created lazily on that tenant's first video upload
  attempt (not at tenant provisioning — VIDEO.md doesn't specify a creation trigger, so
  "lazy" is this phase's choice, made explicit here so it isn't mistaken for VIDEO.md's
  own requirement). Institutes that never upload video never get a Bunny library and
  cost nothing.
- Per-tenant library id / API key / token key are stored on the `tenants` row via
  Laravel's `encrypted` cast, never logged, never sent to the browser.
- Each embed is signed with **that tenant's own** token key — a token generated with
  tenant A's key must never be accepted for tenant B's video (new required test, not in
  the original brief).
- The `fake` driver is unaffected and needs no credentials.
- `app:preflight` (production, `bunny` driver): only the account key is checked at
  deploy time. Per-tenant library keys don't exist yet for tenants with no video, so
  they're validated lazily at first use, surfacing a teacher-facing error if library
  creation fails.

---

## Verified Bunny Stream API facts

Cited because the original brief required verification before implementing. Fetched
from the official docs on 2026-09-29:

- **Create Video Library** — `POST https://api.bunny.net/videolibrary`, header
  `AccessKey: <account-level API key>`, body `{"Name": "..."}` (required, min 1 char).
  Response `201` includes `Id`, `ApiKey` (the *new library's own* API key), `PullZoneId`,
  `StorageZoneId`.
  https://bunny.net/docs/api-reference/core/stream-video-library/add-video-library
- **Create Video** — `POST https://video.bunnycdn.com/library/{libraryId}/videos`,
  header `AccessKey: <library-level API key>`, body `{title, collectionId?,
  thumbnailTime?}`. Response includes `guid`, `status` (0=Created … 4=Finished,
  5=Error). https://bunny.net/docs/reference/video_createvideo
- **TUS resumable upload** — endpoint `https://video.bunnycdn.com/tusupload`. Required
  headers: `AuthorizationSignature` = hex `SHA256(library_id + api_key + expiration_time
  + video_id)` (library id/key are the tenant's own library, never the account key),
  `AuthorizationExpire` (UNIX seconds), `LibraryId`, `VideoId` (the GUID from Create
  Video). TUS creation metadata requires `filetype` (MIME) and `title`; optional
  `collection`. Signature/expire are validated at the start of every POST/HEAD/PATCH,
  not mid-stream; an incomplete upload stays resumable until `AuthorizationExpire` or
  ~48h of inactivity, whichever is first.
  https://bunny.net/docs/stream/tus-resumable-uploads
- **Embed view token authentication** — query params `token` = hex
  `SHA256(token_security_key + video_id + expiration)`, `expires` = UNIX seconds (no
  ms/ns). Example: `https://player.mediadelivery.net/embed/{libraryId}/{videoGuid}
  ?token={hex}&expires={unix}`.
  https://bunny.net/docs/stream-embed-token-authentication

**Open gap, flagged rather than guessed:** none of the fetched Create-Video-Library docs
return a token security key in the create response. Bunny libraries have a token
security key (used for token authentication), but this phase could not verify via the
official API reference whether it's returned by `POST /videolibrary`, requires a
separate `GET /videolibrary/{id}` call, or must be set explicitly (e.g. a follow-up
`POST` with a `TokenAuthenticationKey`-shaped field, unverified). **This blocks the
`bunny` driver's library-provisioning code specifically**, not the rest of Phase 5: the
`bunny` driver's tests below stub/fake the token key value with a documented `// TODO:
verify token key provisioning` marker rather than inventing an unverified API call.
Must be resolved (by re-reading Bunny's dashboard/API docs for the exact field, or a
support ticket) before the `bunny` driver ships to production.

**Embed host note:** the docs example uses `player.mediadelivery.net`. Some
Bunny material elsewhere references `iframe.mediadelivery.net` for embeds; only
`player.mediadelivery.net` appeared in the fetched official token-auth doc, so that's
what this spec uses. Flagged as worth reconfirming against the tenant's actual library
settings before Step 2's `bunny` driver ships.

---

## Schema

### `lesson_videos` (tenant-owned)

```
lesson_videos
- id
- tenant_id
- lesson_id              composite FK -> lessons (tenant_id, id), unique per lesson, cascadeOnDelete
- provider               string ('fake' | 'bunny')
- provider_video_id      string nullable (GUID from the provider)
- status                 string, enum VideoStatus {awaiting_upload, uploading, processing, ready, failed}
- original_filename      string(255)
- size_bytes             unsigned bigint nullable
- duration_seconds       unsigned int nullable
- error_message          string nullable (teacher-readable)
- uploaded_by            composite FK -> users (tenant_id, id), nullable, restrictOnDelete
- timestamps

$table->tenantKeys();
$table->unique(['tenant_id', 'lesson_id']);
$table->foreign(['tenant_id', 'lesson_id'])->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
$table->foreign(['tenant_id', 'uploaded_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
```

`status`, `provider`, `provider_video_id`, `tenant_id`, `uploaded_by` are not
mass-assignable (guarded, set only via `forceFill()`), exactly matching the Phase 4
pattern for `courses`/`enrolments`.

A lesson has **at most one** `lesson_videos` row (the `unique(tenant_id, lesson_id)`
constraint) — the DB-level half of "a lesson has at most one video source, protected
video or YouTube, never both." The other half is an application check in
`Lesson`'s `saving` hook (alongside the existing `LessonCourseMismatchException` check):
throw if `youtube_video_id` is being set/kept while a `lesson_videos` row exists for the
lesson, or vice versa (tested both directions).

### `tenants` (not tenant-owned — no `tenant_id`, so `BelongsToTenant`/composite-FK rules
don't apply; new nullable encrypted columns)

```
tenants
+ bunny_library_id          unsigned bigint nullable
+ bunny_library_api_key     text nullable, encrypted cast
+ bunny_library_token_key   text nullable, encrypted cast
+ bunny_library_created_at  timestamp nullable
```

Added via a new migration `database/migrations/2026_09_3x_add_bunny_library_columns_to_tenants_table.php`
in Step 2 (not tenant-owned, so no `tenantKeys()`/composite FK). None of the three
credential-shaped fields are ever in `Tenant::$fillable`.

---

## `VideoProvider` interface and drivers

`App\Contracts\VideoProvider`:

```php
interface VideoProvider
{
    public function driverName(): string;

    // No-op for fake. For bunny: creates the tenant's library on first use if absent.
    public function ensureLibraryProvisioned(Tenant $tenant): void;

    // Creates the provider-side video record, returns the provider video id plus
    // whatever the browser needs to perform the actual upload (a local chunk-upload
    // URL for fake, a TUS endpoint + signed headers for bunny).
    public function startUpload(LessonVideo $video, string $originalFilename, string $mimeType, int $sizeBytes): array;

    public function fetchStatus(LessonVideo $video): VideoStatus;

    public function deleteVideo(LessonVideo $video): void;

    // fake: a temporarySignedRoute URL. bunny: a signed embed URL (player.mediadelivery.net).
    public function playbackUrl(LessonVideo $video, User $viewer): string;
}
```

Bound in a service provider from `config('coaching.video_driver')` (env `VIDEO_DRIVER`,
default `fake`). `App\Services\Video\FakeVideoProvider` and
`App\Services\Video\BunnyVideoProvider` implement it.

---

## Upload flow

Unchanged from the original brief:

1. Teacher opens the lesson edit page → "Upload video" — gated by the same
   `manageContent` ability Phase 4 already uses for lesson editing (`CoursePolicy`).
2. `POST /manage/lessons/{lesson}/video/start-upload` validates: owner/staff only, file
   type in `mp4/mov/mkv/webm`, size ≤ `config('coaching.max_video_mb')` (default 2048,
   env `COACHING_MAX_VIDEO_MB`), lesson belongs to the tenant (route-model-binding
   scoping — Phase 4 convention), lesson has no `youtube_video_id`.
3. **fake**: creates a `lesson_videos` row (`status = awaiting_upload`), returns a local
   chunk-upload URL. **bunny**: calls `ensureLibraryProvisioned()` (creates the tenant's
   library via the verified Create Video Library call if this is the tenant's first
   video), calls Create Video to get a `guid`, returns the TUS endpoint + signed
   `AuthorizationSignature`/`AuthorizationExpire`/`LibraryId`/`VideoId` headers per the
   verified formula above.
4. Status: `awaiting_upload` → `uploading` → `processing` → `ready`/`failed`.
   `POST /manage/lessons/{lesson}/video/refresh-status` re-polls the provider (never
   trusts a webhook payload directly — AGENT_RULES invariant #11/VIDEO.md webhooks
   section, even though Phase 5 doesn't implement webhooks yet per the brief's
   out-of-scope list).
5. **Replace**: `POST /manage/lessons/{lesson}/video/start-upload` on a lesson that
   already has a video deletes the old provider-side video *after* the DB row for the
   new upload commits, never before. **Delete**: `DELETE
   /manage/lessons/{lesson}/video` — same after-commit-delete rule, confirm dialog via
   `@js` on the frontend (not separately tested at the HTTP layer beyond the delete
   itself).

---

## Playback

- The lesson page (`resources/views/lessons/show.blade.php`) renders the video player
  only when `LessonAccess::lessonAccess()` returns `'ok'` for that lesson (identical gate
  Phase 4 already uses for YouTube/attachments — no new access rule). Otherwise the
  existing locked/403/login behaviour is unchanged. The `provider_video_id` is never
  included in the HTML sent to a viewer who can't play it — it's only ever read
  server-side to build the signed/embed URL for an authorized viewer.
- **fake**: `URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10),
  ['lessonVideo' => $video->id])`. The stream controller re-runs
  `LessonAccess::lessonAccess()` on every request (not just at page render — access could
  have been revoked between render and playback), supports `Range` requests (206),
  `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`.
- **bunny**: embed URL built server-side per the verified formula, signed with the
  *lesson's own tenant's* library token key, 10-minute expiry
  (`config('coaching.video_embed_ttl_minutes', 10)`).
- **Watermark**: absolutely positioned, `pointer-events: none`, semi-transparent overlay
  showing the viewer's name + last 4 digits of phone (or the local part of email before
  `@` if no phone) + today's date; owner/staff preview shows "Preview – {name}"; moves to
  a random position every 20–40s via a small inline script.
- **Fullscreen**: the iframe/`<video>` never gets `allowfullscreen`/`allow="fullscreen"`;
  a custom control puts the *wrapper* (player + watermark) into fullscreen via the
  Fullscreen API.
- `referrerpolicy="strict-origin-when-cross-origin"` on any iframe, matching Phase 4's
  YouTube embed.

---

## `videos:sync` command

`App\Console\Commands\VideosSyncCommand`, signature `videos:sync`, scheduled
`->everyMinute()` in `routes/console.php` alongside the existing backup schedule.
Iterates `lesson_videos` where `status` in `[uploading, processing]`, calls
`fetchStatus()` per video (tenant context re-set explicitly per-row, per TENANCY.md's
"jobs/commands never rely on an ambient tenant" rule — this is a console command
iterating across tenants, so each row's `tenant_id` must be used to scope the update,
never an ambient `TenantContext`), updates `status`/`error_message`/`duration_seconds`.

---

## Required tests

Restated from the brief, corrected for the per-tenant-library design (changes in
**bold**):

1. Driver selection by config; `fake` driver refused by `app:preflight` in production.
   Account key required by `app:preflight` when driver is `bunny`.
2. Bunny API calls use `Http::fake()`; assert request shape (library id, **the correct
   key for the call — account key for Create Video Library, that tenant's own library
   key for everything else**) and that **no API key (account or any tenant's library
   key) or token key** ever appears in a rendered page or JSON sent to the browser.
3. **Library provisioning**: first upload for a tenant creates a library (one
   `Http::fake()` call to `/videolibrary`); a second upload for the same tenant does not
   create a second library; tenant B's upload never uses tenant A's library id/key.
4. Embed token: known input → expected SHA256 hex; expires in the future within the
   configured window; URL contains `token` + `expires`. **A token generated with tenant
   A's library token key must be rejected/never accepted for tenant B's video** (new,
   per the user's requirement).
5. Start-upload endpoint: owner/staff allowed, student 403, other tenant's lesson 404,
   wrong file type/size rejected with teacher-language messages, lesson with a YouTube
   ID rejected.
6. A lesson cannot have both a YouTube ID and a protected video (both directions).
7. Playback: enrolled student sees the player + watermark text; non-enrolled student and
   guest never see the provider video id or signed URL; tenant B cannot play tenant A's
   video via any URL (404).
8. Fake stream route: valid signature + access → 200 with range support (206 on a Range
   request); expired signature → 403; valid signature but access revoked since render →
   403.
9. `videos:sync` moves `processing` → `ready`/`failed` using faked provider responses.
10. Replace/delete removes the old provider video (asserted via `Http::fake`) only after
    the DB change commits.
11. Watermark/fullscreen markup assertions as above.

---

## Files changed

### Step 1 — tests only

- `docs/specs/phase-5-video.md` (this file)
- `tests/Unit/Support/Video/BunnyEmbedTokenSignerTest.php`
- `tests/Unit/Support/Video/BunnyUploadSignatureTest.php`
- `tests/Feature/Video/DriverSelectionTest.php`
- `tests/Feature/Video/LessonVideoSchemaTest.php`
- `tests/Feature/Video/LibraryProvisioningTest.php`
- `tests/Feature/Video/StartUploadTest.php`
- `tests/Feature/Video/PlaybackAccessTest.php`
- `tests/Feature/Video/FakeStreamRouteTest.php`
- `tests/Feature/Video/EmbedTokenPlaybackTest.php`
- `tests/Feature/Video/VideosSyncCommandTest.php`
- `tests/Feature/Video/ReplaceDeleteVideoTest.php`
- `tests/Feature/Video/WatermarkFullscreenMarkupTest.php`
- `tests/Pest.php`: `Http::preventStrayRequests()` for `Feature/Video` and
  `Unit/Support/Video`.
- `phpunit.mysql.xml`: `Video` test suite (both directories above).

### Step 1 correction pass (approved, before Step 2 began)

- `tests/Feature/Console/AppPreflightCommandTest.php`: `passingPreflightConfig()`
  extended with `coaching.video_driver` / `services.bunny.account_api_key` — same
  "positive baseline gains a key when a new check dimension is added" pattern already
  used by every other check in that file; without it, adding the new video-driver
  preflight check would have broken that file's own pre-existing positive control.

### Step 2 — implementation

- Migrations: `2026_09_30_000001_create_lesson_videos_table.php`,
  `2026_09_30_000002_add_bunny_library_columns_to_tenants_table.php`.
- `app/Enums/VideoStatus.php`
- `app/Exceptions/VideoProviderException.php`
- `app/Contracts/VideoProvider.php`
- `app/Models/{LessonVideo,Tenant,Lesson,Enrolment}.php` (Tenant: encrypted/hidden Bunny
  columns; Lesson: `video()` relation; Enrolment: `revoke()` convenience method — see
  "Test corrections" below for why)
- `database/factories/LessonVideoFactory.php`
- `app/Services/Video/{FakeVideoProvider,BunnyVideoProvider}.php`
- `app/Support/Video/{BunnyEmbedTokenSigner,BunnyUploadSignature}.php`
- `app/Http/Controllers/Manage/LessonVideoController.php`
- `app/Http/Controllers/{LessonVideoStreamController,LessonVideoUploadController,LessonController}.php`
- `app/Http/Controllers/Manage/LessonController.php` (loads `video`; rejects a
  `youtube_url` update when a protected video already exists)
- `app/Console/Commands/{VideosSyncCommand,AppPreflightCommand}.php`
- `app/Providers/AppServiceProvider.php` (`VideoProvider` binding)
- `routes/web.php` (start-upload/refresh-status/destroy, the two signed routes),
  `routes/console.php` (`videos:sync` every minute)
- `config/coaching.php` (`video_driver`, `max_video_mb`, `video_embed_ttl_minutes`,
  `video_upload_signature_ttl_minutes`), `config/services.php` (`bunny.account_api_key`)
- `lang/en/lessons.php` (`video.*` teacher-facing strings and status labels)
- `resources/views/lessons/show.blade.php` (protected-video player, watermark overlay,
  fullscreen control), `resources/views/manage/lessons/edit.blade.php` (upload UI:
  file input, progress bar, status badge, Refresh status, Replace, Delete)
- `README.md`, `docs/DEPLOY.md`, `SECURITY.md`, `VIDEO.md` (see those files' own diffs)

---

## Test corrections (Step 1 → Step 2, approved-pattern fixes, not behavioral changes)

Same category as Phase 4's own precedent (phase-4-courses.md) — bugs in my own Step 1
tests, found once real code existed to run them against, each stopped-and-confirmed with
the user before touching an already-committed test file:

1. **`FakeStreamRouteTest`, all 4 tests** — `$lesson->video->id` was dereferenced outside
   `inTenant()`, throwing `MissingTenantContextException` (an undefined-variable-shaped
   bug, not a design question). Fixed by capturing the video's id *inside* the
   `inTenant()` closure and returning it directly.
2. **`FakeStreamRouteTest`, 3 of 4 tests** — `URL::temporarySignedRoute()` called directly
   in the test body (not during an actual HTTP request) has no request to infer the host
   from, so it signed against `APP_URL` (`localhost:8000`) while the test then requested
   the URL from `tenant-a.coaching.test` — an unconditional signature mismatch (403)
   regardless of expiry, masked in the 4th test because it *expected* 403 anyway (for the
   wrong reason: host mismatch, not the expiry it claimed to test). Fixed with a
   `signedStreamUrl()` helper that forces the root URL to the tenant's domain before
   generating, matching how the real controller flow works (there, Laravel infers the
   root from the actual incoming request automatically).
3. **`FakeStreamRouteTest`, Cache-Control assertion** — expected the literal string
   `'private, no-store'`, but Symfony's `ResponseHeaderBag` always alphabetically sorts
   Cache-Control directives (`ksort` in `computeCacheControlValue()`), so that literal
   order is never producible via the normal directive API — semantically identical,
   corrected to `'no-store, private'`.
4. **`EmbedTokenPlaybackTest`, log-leak test** — its positive control asserted
   `json_encode([])` (an empty `Log::info()` context array) equals `'{}'`; PHP encodes an
   empty array as `'[]'`, not `'{}'` (arrays don't distinguish empty-object from
   empty-list) — the control could never pass regardless of implementation. Corrected to
   `'[]'`.
5. **`Enrolment` model** — `FakeStreamRouteTest`'s revocation test called
   `$enrolment->revoke()`, which didn't exist (only `Manage\EnrolmentController::revoke()`
   had this logic, inline). Added a small `Enrolment::revoke(?int $revokedBy = null): void`
   convenience method mirroring the controller's existing `forceFill()` exactly — doesn't
   touch any locked invariant, and the controller itself was left untouched (not
   refactored to use it, per "don't perform broad refactors").

`git diff --stat` against the Step 1 commit (`1de8410`) is provided in the Step 2 report;
every other Step 1 test file is byte-for-byte what was committed there.

---

## Assumptions

- Lazy per-tenant library creation (on first upload) rather than at tenant provisioning
  — VIDEO.md doesn't specify a trigger; chosen per the user's explicit instruction.
- `tenants` gets new columns directly rather than a separate one-row-per-tenant table —
  simplest fit, since `tenants` has no existing per-tenant-secret precedent to follow or
  diverge from in this codebase (Phase 4/PAYMENTS.md's gateway-credentials encryption
  pattern isn't implemented yet).
- Management route paths (`/manage/lessons/{lesson}/video/...`) follow Phase 4's
  `/manage/...` convention; not fixed by the brief.
- `videos:sync` runs every minute per the brief; exact command class name/location is
  this phase's choice (Phase 4 precedent: `App\Console\Commands\*`).
- The embed host `player.mediadelivery.net` is used because it's the only one the
  fetched official doc showed; see the "Embed host note" above.
- A tenant's currently-configured `VIDEO_DRIVER` is assumed to match the `provider` value
  already stored on that tenant's existing `lesson_videos` rows when resolving playback —
  true in practice (the driver isn't meant to be switched under a tenant with existing
  video content) and matches every test, but not defensively re-checked per row.
- `LessonVideoUploadController` (the fake driver's actual bytes-receiving endpoint) is
  implemented per requirement #3 (server-side size/type re-validation, id-keyed storage
  path, production refusal) but has no dedicated Step-1 test — the brief's Step 1 test
  list didn't include one, and Step 2 doesn't add tests beyond what's already the
  contract (see "Tests are the contract" in the Step 2 instructions).
- Race-safety for concurrent first-uploads (`ensureLibraryProvisioned`'s
  `lockForUpdate()`) is implemented but not exercised by an actual concurrency test — Pest
  runs single-process/single-connection per test, so true concurrent requests aren't
  reproducible in this suite; noted as "not feasible to test here" rather than skipped
  silently.

## Unresolved risks

- **Token security key provisioning is unverified** (see "Open gap" above) — a freshly
  provisioned tenant's `bunny_library_token_key` is left `null`; `BunnyVideoProvider::
  playbackUrl()` throws a clear, teacher-facing `VideoProviderException` rather than
  signing with a guessed value. Must be resolved (Bunny dashboard/API/support) before the
  `bunny` driver is used with a real tenant.
- **Embed host** (`player.mediadelivery.net` vs. `iframe.mediadelivery.net`) should be
  reconfirmed against a real tenant library's settings before the `bunny` driver ships.
- No webhook handling yet (explicitly out of scope, per the brief) — `videos:sync`
  polling (every minute) is the only status-update path until a later phase adds
  webhooks.
- The upload UI's client-side JS (progress bar, tus-js-client integration for the bunny
  driver, XHR for the fake driver) has no browser-level test coverage — only the
  server-side endpoints it calls are tested.
