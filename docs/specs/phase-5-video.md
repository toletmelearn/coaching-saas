# Phase 5: Protected Video for Lessons

**Status:** Complete (including the 5.1 follow-up fixes). `composer test` (420/421, 1
pre-existing MySQL-only test skipped on SQLite), `composer test:mysql` (389/389),
`composer lint`, and `composer analyse` all pass with zero regressions to Phases 1–4.

---

## Correction to the original brief

The Phase 5 brief as originally drafted described the `bunny` driver as "one
platform-owned video library; each tenant gets a Bunny 'collection' for organisation
only — isolation is enforced in our database, never by Bunny," with a single app-level
`BUNNY_STREAM_LIBRARY_ID` / `BUNNY_STREAM_API_KEY` in `.env`. This directly conflicts
with [VIDEO.md](../../VIDEO.md) ("One Bunny Stream library per tenant... Library
credentials (library id, API key) are stored per tenant, encrypted") and
[AGENT_RULES.md](../../AGENT_RULES.md) invariant #13. Per `AGENT_RULES.md` rule 20, the
invariant wins; the user confirmed following the documented per-tenant-library design.

**Final design:**

- The platform owns a single Bunny **account**. Only the account-level API key lives in
  `.env` (`BUNNY_STREAM_ACCOUNT_API_KEY`).
- Each tenant gets its own Bunny Stream **library** (own library id, own library API
  key), created lazily on that tenant's first video upload attempt. Institutes that
  never upload video never get a library.
- The tenant's library API key is stored on the `tenants` row via Laravel's `encrypted`
  cast, hidden from serialization, never logged, never sent to the browser.
- That same library API key is also the embed-token signing key (see "Verified Bunny
  API facts" below) — **there is no separate token security key**. A token generated
  with tenant A's key must never be accepted for tenant B's video (tested).
- The `fake` driver is unaffected and needs no credentials; it is refused in production
  both at deploy time (`app:preflight`) and at runtime (`FakeVideoProvider` itself).
- `app:preflight` (production, `bunny` driver): only the account key is checked at
  deploy time. Per-tenant library keys don't exist yet for tenants with no video, so
  they're validated lazily at first use, surfacing a teacher-facing error if library
  creation fails.

---

## Verified Bunny Stream API facts

- **Create Video Library** — `POST https://api.bunny.net/videolibrary`, header
  `AccessKey: <account-level API key>`, body `{"Name": "..."}`. Response `201` includes
  `Id`, `ApiKey` (the new library's own key).
  https://bunny.net/docs/api-reference/core/stream-video-library/add-video-library
- **Create Video** — `POST https://video.bunnycdn.com/library/{libraryId}/videos`,
  header `AccessKey: <library-level API key>`, body `{title, collectionId?,
  thumbnailTime?}`. Response includes `guid`, `status` (0=Created … 4=Finished,
  5=Error). https://bunny.net/docs/reference/video_createvideo
- **TUS resumable upload** — endpoint `https://video.bunnycdn.com/tusupload`. Required
  headers: `AuthorizationSignature` = hex `SHA256(library_id + api_key + expiration_time
  + video_id)` (library id/key are the tenant's own library, never the account key),
  `AuthorizationExpire` (UNIX seconds), `LibraryId`, `VideoId`.
  https://bunny.net/docs/stream/tus-resumable-uploads
- **Embed view token authentication** — query params `token` = hex
  `SHA256(token_security_key + video_id + expiration)`, `expires` = UNIX seconds.
  Example: `https://player.mediadelivery.net/embed/{libraryId}/{videoGuid}
  ?token={hex}&expires={unix}`. https://bunny.net/docs/stream-embed-token-authentication
- **The token security key is the library API key** — confirmed (Phase 5.1) against
  https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the token security key
  is your Video Library API Key." Resolves what was an open gap in the initial Phase 5
  spec (the Create Video Library response doesn't return a distinct token key); there is
  no such distinct value.

**Embed host note:** the docs example uses `player.mediadelivery.net`; that's what this
implementation uses. Worth reconfirming against a real tenant library's settings before
the `bunny` driver is used with a real tenant, since some Bunny material elsewhere
references `iframe.mediadelivery.net` for embeds.

---

## Schema

### `lesson_videos` (tenant-owned)

```
lesson_videos
- id
- tenant_id
- lesson_id              composite FK -> lessons (tenant_id, id), unique per lesson, cascadeOnDelete
- provider               string ('fake' | 'bunny')
- provider_video_id      string nullable (GUID for bunny, UUID for fake)
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
$table->tenantForeign('uploaded_by', 'users');
```

`status`, `provider`, `provider_video_id`, `tenant_id`, `uploaded_by` are not
mass-assignable (guarded, set only via `forceFill()`). A lesson has **at most one**
`lesson_videos` row (`unique(tenant_id, lesson_id)`) — the DB-level half of "a lesson has
at most one video source, protected video or YouTube, never both." The other half is an
application check: `Manage\LessonController::update()` rejects a `youtube_url` when a
`lesson_videos` row already exists; `Manage\LessonVideoController::startUpload()` rejects
an upload attempt when `youtube_video_id` is already set (tested both directions).

### `tenants` (not tenant-owned — no `tenant_id`, so `BelongsToTenant`/composite-FK rules
don't apply)

```
tenants
+ bunny_library_id          unsigned bigint nullable
+ bunny_library_api_key     text nullable, encrypted cast          -- also the embed-token signing key
+ bunny_library_created_at  timestamp nullable
```

Two migrations: `2026_09_30_000001_create_lesson_videos_table.php`,
`2026_09_30_000002_add_bunny_library_columns_to_tenants_table.php` (originally also added
a `bunny_library_token_key` column, dropped in
`2026_10_01_000001_drop_bunny_library_token_key_from_tenants_table.php` once Phase 5.1
confirmed it was never a distinct value — a new migration, not an edit to the already-
applied one, per CLAUDE.md). None of these three columns are ever in `Tenant::$fillable`.

---

## `VideoProvider` contract and drivers

`App\Contracts\VideoProvider`: `driverName()`, `ensureLibraryProvisioned(Tenant)` (no-op
for fake; race-safe `lockForUpdate()` provisioning for bunny),
`createProviderVideo(Tenant, string $title): string`,
`uploadInstructions(Tenant, LessonVideo): array` (what the browser needs to perform the
actual upload — never a raw API key), `fetchStatus(LessonVideo): array`,
`deleteVideoById(Tenant, LessonVideo $capturedVideo, string $providerVideoId): void`,
`playbackUrl(LessonVideo, ?User $viewer): string`. Bound in `AppServiceProvider` from
`config('coaching.video_driver')` (env `VIDEO_DRIVER`, default `fake`).
`App\Services\Video\{FakeVideoProvider,BunnyVideoProvider}` implement it.

---

## Upload flow

1. Teacher opens the lesson edit page → file input, gated by the same `manageContent`
   ability Phase 4 uses for lesson editing.
2. `POST /manage/lessons/{lesson}/video/start-upload` validates (server-side, never
   trusting the client alone): owner/staff only, mime type in
   `video/{mp4,quicktime,x-matroska,webm}`, size ≤ `config('coaching.max_video_mb')`
   (default 2048), lesson has no `youtube_video_id`. Wraps provider calls in a
   try/catch that reduces any failure to a teacher-facing lang string
   (`lessons.video.upload_failed`) — never a raw provider response or exception message.
3. **fake**: returns a signed upload URL
   (`App\Http\Controllers\LessonVideoUploadController`, route
   `lesson-videos.fake-upload`) the browser then POSTs the file to directly. **bunny**:
   `ensureLibraryProvisioned()` then Create Video, returns the TUS endpoint + signed
   headers (`App\Support\Video\BunnyUploadSignature`).
4. `POST /manage/lessons/{lesson}/video/refresh-status` re-polls the provider (never
   trusts a webhook payload — no webhooks implemented this phase anyway).
5. **Replace**: a second `start-upload` call on a lesson that already has a video
   updates the same `lesson_videos` row in place (keeping "at most one row per lesson"
   trivial) and deletes the *old* provider-side video only *after* that DB write
   commits. **Delete**: `DELETE /manage/lessons/{lesson}/video`, same after-commit-delete
   rule, `@js` confirm dialog on the frontend.

### The fake driver's actual upload endpoint (`LessonVideoUploadController`)

Signed route, `manageContent`-gated, re-validates type (via `UploadedFile::getMimeType()`
— content-detected, never the client-declared `getClientMimeType()`) and size
server-side regardless of what `start-upload` already checked, refuses in production,
and stores at `tenants/{tenant_id}/videos/{provider_video_id}.mp4` — keyed by the
server-generated UUID, never the client-supplied filename. When an upload exceeds PHP's
own `post_max_size`, PHP discards the body before `$_FILES` is populated; this is
detected (`Content-Length` above the configured limit with no file present) and reported
as "file too large" rather than a generic error.

---

## Playback

- The lesson page renders the video player only when `LessonAccess::lessonAccess()`
  returns `'ok'`. The `provider_video_id` is never included in HTML for a viewer who
  can't play it.
- **fake**: `URL::temporarySignedRoute('lesson-videos.stream', …)`, 10-minute expiry.
  The stream controller re-runs `LessonAccess::lessonAccess()` on every request (not
  just at page render), supports `Range` requests (206 via `BinaryFileResponse`),
  `Cache-Control: no-store, private` (Symfony's `ResponseHeaderBag` always
  alphabetically sorts Cache-Control directives — semantically identical to
  "private, no-store"), `X-Content-Type-Options: nosniff`. The `<video>` element also
  carries `controlsList="nofullscreen noremoteplayback"`, `disablePictureInPicture`, and
  `oncontextmenu="return false"` — deterrents against the browser's own built-in
  fullscreen/casting/save affordances, trivially bypassed via devtools, documented as
  such in SECURITY.md rather than oversold.
- **bunny**: embed URL built server-side (`BunnyEmbedTokenSigner`), signed with the
  *lesson's own tenant's* library API key, 10-minute expiry
  (`config('coaching.video_embed_ttl_minutes', 10)`).
- **Watermark**: absolutely positioned, `pointer-events: none` overlay showing the
  viewer's name + last 4 digits of phone (or email local-part) + today's date — escaped
  Blade output (`{{ }}`, never `{!! !!}`), since a student's name is user input. Owner/
  staff preview shows "Preview – {name}". Repositions every 20–40s via a small inline
  script.
- **Fullscreen**: the iframe/`<video>` never gets `allowfullscreen`/`allow="fullscreen"`;
  a custom `.video-fullscreen-button` puts the wrapper (player + watermark) into
  fullscreen via the Fullscreen API.
- `referrerpolicy="strict-origin-when-cross-origin"` on any iframe.

---

## `videos:sync` command

`App\Console\Commands\VideosSyncCommand`, `videos:sync`, scheduled `->everyMinute()`.
Raw `DB::table('lesson_videos')` query across all tenants (an allowed system-level
exception per TENANCY.md — a console command, no ambient tenant), then
`TenantContext::runAs()` per row before touching it, calling `fetchStatus()` and
updating `status`/`error_message`/`duration_seconds`.

---

## CSRF (Phase 5.1)

`layouts/app.blade.php` carries `<meta name="csrf-token" content="...">`. The bundled
upload script (`resources/js/video-upload.js`, see below) sends `X-CSRF-TOKEN` on both
of *our own* routes it calls (`start-upload`, the fake driver's `fake-upload`) — not on
the TUS request to Bunny's own server, which isn't one of our routes and carries the TUS
signature headers instead. Verified with a dedicated test that forces real CSRF
enforcement (the testing environment's automatic CSRF bypass, `PreventRequestForgery::
runningUnitTests()`, keys off `APP_ENV === 'testing'`; switching to `'local'` — not
`'testing'` or `'production'` — makes CSRF actually enforce without also tripping the
fake driver's production guard).

## Client-side bundling (Phase 5.1)

`tus-js-client` is an npm dependency (`package.json`/`package-lock.json`, pinned),
imported by `resources/js/video-upload.js` and bundled via the existing single Vite
entry point (`resources/js/app.js`) — no CDN `<script>` tag. The module self-guards
(`if (!document.getElementById('video-manager')) return;`), so it's a harmless no-op on
every page except the lesson edit page.

---

## Required tests

1. Driver selection by config; `fake` driver refused by `app:preflight` in production
   (deploy time) and by `FakeVideoProvider` itself (runtime). Account key required by
   `app:preflight` when driver is `bunny`.
2. Bunny API calls use `Http::fake()`; assert request shape (library id, the correct key
   for the call) and that no API key ever appears in a rendered page, JSON response, or
   captured log output.
3. Library provisioning: first upload for a tenant creates a library exactly once;
   tenant B's upload never uses tenant A's library id/key; library creation failure
   surfaces a teacher-facing error with no half-created `lesson_videos` row; the stored
   library key round-trips through the `encrypted` cast (never plaintext in the raw DB
   column).
4. Embed token: known input → expected SHA256 hex; expires in the future within the
   configured window; URL contains `token` + `expires`; a token signed with tenant A's
   library key is never accepted for tenant B's video.
5. Start-upload endpoint: owner/staff allowed, student 403, other tenant's lesson 404,
   wrong file type/size rejected with teacher-language messages, lesson with a YouTube
   ID rejected (and vice versa).
6. Playback: enrolled student sees the player + watermark text; non-enrolled
   student/guest never see the provider video id or signed URL; tenant B cannot play
   tenant A's video via any URL (404).
7. Fake stream route: valid signature + access → 200 with range support (206 on Range);
   expired signature → 403; valid signature but access revoked since render → 403;
   response headers.
8. `videos:sync` moves `processing`/`uploading` → `ready`/`failed` using faked provider
   responses; scopes each row to its own tenant; leaves an already-`ready` row untouched
   (no HTTP call at all).
9. Replace/delete removes the old provider video only after the DB change commits; a
   forced DB-delete failure never reaches the provider-delete call.
10. Watermark/fullscreen markup assertions.
11. `LessonVideoUploadController` (Phase 5.1): signed URL required; owner/staff only;
    size limit enforced server-side; type checked by server-detected content, not the
    client-declared mime type; stored path is never derived from the client filename;
    refused in production; another tenant's video id 404s; a PHP `post_max_size`
    overflow (empty `$_FILES`) is reported as "file too large."
12. CSRF (Phase 5.1): the lesson edit page renders the meta tag; the bundled upload
    script sets `X-CSRF-TOKEN` on our own routes; start-upload and fake-upload both
    reject a request with a real-but-mismatched CSRF token when CSRF verification is
    actually enabled, and accept a matching one.

---

## Files changed

**Migrations:** `2026_09_30_000001_create_lesson_videos_table.php`,
`2026_09_30_000002_add_bunny_library_columns_to_tenants_table.php`,
`2026_10_01_000001_drop_bunny_library_token_key_from_tenants_table.php`.

**Backend:** `app/Enums/VideoStatus.php`, `app/Exceptions/VideoProviderException.php`,
`app/Contracts/VideoProvider.php`, `app/Models/LessonVideo.php`,
`database/factories/LessonVideoFactory.php`,
`app/Services/Video/{FakeVideoProvider,BunnyVideoProvider}.php`,
`app/Support/Video/{BunnyEmbedTokenSigner,BunnyUploadSignature}.php`,
`app/Http/Controllers/Manage/LessonVideoController.php`,
`app/Http/Controllers/{LessonVideoStreamController,LessonVideoUploadController}.php`,
`app/Console/Commands/VideosSyncCommand.php`.

**Modified existing:** `app/Models/{Tenant,Lesson,Enrolment}.php` (Tenant: encrypted/
hidden Bunny column; Lesson: `video()` relation; Enrolment: `revoke()` convenience
method), `app/Http/Controllers/{LessonController,Manage/LessonController}.php`,
`app/Console/Commands/AppPreflightCommand.php`, `app/Providers/AppServiceProvider.php`
(`VideoProvider` binding), `routes/{web,console}.php`, `config/{coaching,services}.php`,
`lang/en/lessons.php`, `resources/views/layouts/app.blade.php` (CSRF meta tag),
`resources/views/lessons/show.blade.php`, `resources/views/manage/lessons/edit.blade.php`.

**Frontend build:** `resources/js/{app,video-upload}.js`, `package.json`,
`package-lock.json` (`tus-js-client`, pinned).

**Docs:** `README.md`, `docs/DEPLOY.md`, `SECURITY.md`, `VIDEO.md` (watermarking
resolved), this file.

**Tests:** `tests/Unit/Support/Video/{BunnyEmbedTokenSignerTest,
BunnyUploadSignatureTest}.php`, `tests/Feature/Video/{DriverSelectionTest,
LessonVideoSchemaTest,LibraryProvisioningTest,StartUploadTest,PlaybackAccessTest,
FakeStreamRouteTest,EmbedTokenPlaybackTest,VideosSyncCommandTest,ReplaceDeleteVideoTest,
WatermarkFullscreenMarkupTest,FakeUploadControllerTest,CsrfProtectionTest}.php`,
`tests/Pest.php` (`Http::preventStrayRequests()` for the two video test directories),
`phpunit.mysql.xml` (`Video` suite), plus the one-line addition to the pre-existing
`tests/Feature/Console/AppPreflightCommandTest.php`'s shared baseline fixture (see "Test
corrections" below).

---

## Test corrections (approved-pattern fixes, not behavioral changes)

Same category as Phase 4's own precedent — bugs found once real code existed to run
tests against, each stopped-and-confirmed with the user before touching an
already-committed test file:

1. **`AppPreflightCommandTest`** — `passingPreflightConfig()` (the shared "everything
   passes" baseline used by every test in that file) extended with
   `coaching.video_driver` / `services.bunny.account_api_key` once the new video-driver
   preflight check existed — the same "positive baseline gains a key when a new check
   dimension is added" pattern already used for every prior check there.
2. **`FakeStreamRouteTest`, all 4 tests** — `$lesson->video->id` was dereferenced outside
   `inTenant()`, throwing `MissingTenantContextException`. Fixed by capturing the
   video's id *inside* the `inTenant()` closure.
3. **`FakeStreamRouteTest`, 3 of 4 tests** — `URL::temporarySignedRoute()` called
   directly in the test body signs against `APP_URL`, not the tenant's domain the test
   then requests from — an unconditional signature mismatch. Fixed with a
   `signedStreamUrl()` helper that forces the root URL to the tenant's domain first.
4. **`FakeStreamRouteTest`, Cache-Control assertion** — expected the literal string
   `'private, no-store'`; Symfony always alphabetically sorts Cache-Control directives,
   so that order is never producible — corrected to `'no-store, private'`.
5. **`EmbedTokenPlaybackTest`, log-leak test** — asserted `json_encode([])` equals
   `'{}'`; PHP encodes an empty array as `'[]'`. Corrected.
6. **`Enrolment` model** — `FakeStreamRouteTest`'s revocation test called
   `$enrolment->revoke()`, which didn't exist. Added a small convenience method
   mirroring `Manage\EnrolmentController::revoke()`'s existing logic exactly.
7. **Phase 5.1 — `EmbedTokenPlaybackTest`, `ReplaceDeleteVideoTest`,
   `WatermarkFullscreenMarkupTest`, `VideosSyncCommandTest`** — all set a
   `bunny_library_token_key` fixture value that stopped existing once that column was
   dropped (per the confirmed "token key = library API key" finding); mechanically
   removed from each, with `EmbedTokenPlaybackTest`'s two token-signing tests updated to
   sign with the library key directly instead of a separate token key.

---

## Assumptions

- Lazy per-tenant library creation (on first upload) rather than at tenant provisioning
  — VIDEO.md doesn't specify a trigger.
- `tenants` gets new columns directly rather than a separate one-row-per-tenant table.
- Management route paths (`/manage/lessons/{lesson}/video/...`) follow Phase 4's
  `/manage/...` convention.
- A tenant's currently-configured `VIDEO_DRIVER` is assumed to match the `provider`
  value already stored on that tenant's existing `lesson_videos` rows when resolving
  playback — true in practice (the driver isn't meant to be switched under a tenant with
  existing content) and matches every test, but not defensively re-checked per row.
- Race-safety for concurrent first-uploads (`ensureLibraryProvisioned`'s
  `lockForUpdate()`) is implemented but not exercised by an actual concurrency test —
  Pest runs single-process/single-connection per test, so true concurrent requests
  aren't reproducible in this suite.

## Unresolved risks

- **Embed host** (`player.mediadelivery.net` vs. `iframe.mediadelivery.net`) should be
  reconfirmed against a real tenant library's settings before the `bunny` driver is used
  with a real tenant.
- No webhook handling yet (explicitly out of scope) — `videos:sync` polling (every
  minute) is the only status-update path until a later phase adds webhooks.
- The upload UI's client-side JS (progress bar, `tus-js-client` integration for bunny,
  XHR for fake) has no browser-level test coverage — only the server-side endpoints it
  calls are tested.
