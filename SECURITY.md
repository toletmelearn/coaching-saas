# Security

## Sessions and cookies

- `SESSION_DOMAIN` must stay `null`. With a `null` domain, Laravel/the browser scopes the
  session cookie to the exact hostname that issued it (e.g. `friend.coaching.test`), not to
  a parent domain like `.coaching.test`. If `SESSION_DOMAIN` were set to `.coaching.test`,
  every tenant subdomain would share the same cookie jar in the browser, and a session
  cookie issued by one tenant would be sent by the browser to every other tenant subdomain —
  defeating tenant isolation before any application code runs. Per-subdomain cookies are the
  first line of defense; the [tenant-aware user provider](TENANCY.md) is the second, in case
  a cookie is replayed anyway (see below).
- **Session replay across tenants must fail.** Even if a session id from tenant A were
  presented against tenant B's hostname (cookie scoping bypassed, stolen cookie, manual
  replay), the tenant-aware user provider must fail to resolve a valid user for that
  session, because user lookup is scoped to the tenant resolved from the request hostname,
  not to a global users table. A successful replay is treated as a critical bug, not an edge
  case.

## Trusted proxies (Cloudflare)

- `bootstrap/app.php`'s `trustProxies()` only honours `X-Forwarded-For` and
  `X-Forwarded-Proto` from Cloudflare's published IPs (`config/cloudflare.php`) — it
  deliberately does **not** trust `X-Forwarded-Host` (or `-Port`): tenant resolution is
  host-based (`ResolveTenant` uses `$request->getHost()`), so honouring a forwarded host
  would let a forged `X-Forwarded-Host` header resolve a different tenant than the one the
  request actually reached.

## Response security headers

Decided in the Phase 11 mini security pass ([docs/SECURITY_PASS.md](docs/SECURITY_PASS.md)):
**the edge (Cloudflare) supplies the cross-origin defence headers; the application does
not.** What the app itself sets:

| Header | Scope | Where |
|---|---|---|
| `Referrer-Policy: strict-origin-when-cross-origin` | every response | `App\Http\Middleware\SetReferrerPolicy`, appended to the `web` group in `bootstrap/app.php` |
| `X-Content-Type-Options: nosniff` | byte-emitting responses only: lesson attachments, the fake video stream, payment screenshots, PWA icons | the four controllers that emit those bytes |
| `Cache-Control: no-store` / `no-store, private` | private byte responses | same |

Not set by the application, and **required from Cloudflare** in production (config steps in
docs/DEPLOY.md §1 — the exact dashboard path is [UNVERIFIED], confirm at deploy time):

- `X-Frame-Options: SAMEORIGIN` (and/or CSP `frame-ancestors`) — clickjacking.
- `X-Content-Type-Options: nosniff` on HTML responses too, as belt-and-braces.
- `Strict-Transport-Security` — HSTS is a TLS property and Cloudflare terminates TLS, so
  the edge is the right place; a header emitted by the origin over plain HTTP would be
  ignored by browsers anyway.
- `Content-Security-Policy` — deliberately **not** set at the application level. The view
  layer has 17 inline event handlers (13 `onsubmit`, 3 `onclick`, 1 `oncontextmenu`), 3
  inline `<script>` blocks and 201 inline `style="…"` attributes across 29 templates, so a
  CSP worth having would need nonces threaded through every template — a refactor, not a
  security patch, and a strict one would break the Phase 4 YouTube free preview. Handing
  CSP to Cloudflare is the cheap approximation; making it real is a Stage C decision.

## Contact-required CHECK constraint: minimum database version

- `users.email IS NOT NULL OR users.phone IS NOT NULL` is enforced by a native `CHECK`
  constraint on MySQL/MariaDB (a trigger-based equivalent is used on SQLite — see the
  migration for details). This is only actually *enforced* on:
  - **MySQL 8.0.16+** — earlier 8.0.x releases and all of MySQL 5.7 parse `CHECK` syntax
    without error but silently ignore it (a documented MySQL behavior prior to 8.0.16), so a
    row violating the constraint would be accepted with no error.
  - **MariaDB 10.2.1+** — MariaDB has enforced `CHECK` constraints since 10.2.1.
  - Local development and the MySQL test suite (`composer test:mysql`) run against
    **MariaDB 10.4.32** (bundled with XAMPP), which enforces the constraint correctly.
  - Before deploying to any MySQL host, confirm the server version is 8.0.16 or later —
    otherwise the CHECK constraint becomes a no-op and only the HTTP-layer validation in
    `UserController::store()` (see [docs/specs/phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md))
    protects the invariant.

## password_reset_tokens is not yet tenant-scoped

- Phase 3 does not implement self-service ("forgot password") reset — password changes
  are either the user's own first-login `must_change_password` flow, or an owner/staff
  explicitly resetting another user's password through the app (both scoped to the
  authenticated tenant session already). The stock `password_reset_tokens` table (primary
  key: `email`, no `tenant_id`) is present but unused.
- **This is a flagged risk for whoever implements self-service reset later.** Since Phase 3
  made `email` unique only per-tenant (not globally — see "same email can exist in two
  different tenants" above), a `password_reset_tokens` row keyed only by `email` cannot
  distinguish which tenant's user the token belongs to. A token generated for
  `student@example.com` in tenant A would be indistinguishable from one for
  `student@example.com` in tenant B. Before adding self-service reset, this table needs a
  `tenant_id` column and a composite key (or a separate token store keyed by
  `(tenant_id, email)`), following the same pattern as every other tenant-owned table in
  [TENANCY.md](TENANCY.md).
- **Phase 11 decision — deferred to Stage C.** Verified in the mini security pass
  ([docs/SECURITY_PASS.md](docs/SECURITY_PASS.md)): no route is wired to it and no code
  writes to it. `password_reset_tokens` appears only in the stock framework migration and
  in `config/auth.php`'s broker config; there is no `Password::` call, no `password.email`
  route, and no view anywhere in `app/`, `routes/`, `resources/views/` or `config/` besides
  that broker entry. The risk is therefore *latent*, not live. Re-keying the table is a
  schema change (`AGENT_RULES` #7) and is exactly the work self-service reset needs anyway,
  so doing it now and again when the feature lands is worse than doing it once then. Revisit
  before any "forgot password" flow is built.

## Never trust client-supplied ownership or pricing

- `tenant_id`, price, and course ownership are never taken from the request body, query
  string, or a hidden form field. They are always derived server-side: `tenant_id` from
  `TenantContext`, price from the course/plan record looked up by id within that tenant's
  scope, ownership from the authenticated user's tenant and role.
- Any endpoint that accepts an id (course, lesson, enrollment, invoice) must load that
  record through the tenant-scoped query (see [TENANCY.md](TENANCY.md) — route model
  binding respects the tenant scope), so a request for another tenant's record 404s instead
  of leaking data or letting price be manipulated.

## Credentials and secrets

- Per-tenant payment gateway credentials are stored encrypted at rest (Laravel's encrypted
  casts), never in plaintext columns, logs, or queue payloads.
- The Bunny Stream **account**-level API key is an application-level secret (`.env`,
  `BUNNY_STREAM_ACCOUNT_API_KEY`), used only to create a tenant's library. Each tenant's own
  **library** API key is a per-tenant secret — stored on `tenants` via Laravel's `encrypted`
  cast (never a plaintext column), in `Tenant::$hidden` (never serialized to an array/JSON
  response), and never logged: any exception raised while calling Bunny is constructed with
  a teacher-facing message only (`App\Exceptions\VideoProviderException`), never the raw
  provider response, so there is nothing key-shaped to accidentally `report()` into the logs
  in the first place. That same library API key doubles as the embed-token signing key
  (verified against https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the
  token security key is your Video Library API Key") — there is no separate token key.

## Private storage and signed URLs

- Videos, PDFs, and payment screenshots are stored on a **private** disk — never on a
  publicly readable path or bucket.
- Access to video and payment screenshots goes through **signed, expiring URLs**
  (`lesson-videos.stream`, `payments/{payment}/screenshot` and the `fake` driver's upload
  endpoint all sit behind the `signed` middleware). A PDF is never treated as "less
  sensitive" just because it isn't streamed — but there is one documented exception:
- **Lesson-attachment PDFs are not signed** (found by the Phase 11 mini security pass —
  see [docs/SECURITY_PASS.md](docs/SECURITY_PASS.md)). Their URL is a plain
  `/courses/{slug}/lessons/{lesson}/attachments/{attachment}` link with no signature and no
  expiry; instead the access decision is re-run from scratch on **every** request by
  `LessonAccess::lessonAccess()` — guest → login redirect, not enrolled → 403, enrolment
  revoked since page render → 403. That is an equivalent authorisation check, but a weaker
  *link*: nothing expires it, and it holds for as long as the holder keeps an eligible
  session. Adding `signed` there is a view-layer change in two templates plus edits to
  `tests/Feature/Courses/AttachmentsTest.php`, so it is recorded as an open item rather
  than made a Phase 11 change.
- Signed URLs that do exist are generated per-request and cover the absolute URL, so a
  signature minted for one tenant's host does not validate on another's; ownership is
  re-checked in the controller regardless (a valid signature over someone else's asset is
  still refused), and expiry is short enough to limit the value of a leaked link.
- **Payment screenshots specifically** (Phase 10, docs/specs/phase-10-payments.md): the
  signature is necessary but not sufficient. `GET /payments/{payment}/screenshot` sits
  behind `signed` *and* re-checks `PaymentPolicy` on every request — the student who
  submitted that payment, or an owner/staff reviewer — so a perfectly valid signature over
  another student's payment is a 403 rather than a leak (tested unsigned, tampered, expired,
  and valid-but-someone-else's). The response is `image/png` with
  `X-Content-Type-Options: nosniff` and `Cache-Control: no-store, private`: a proof of
  payment is neither HTML nor something a shared cache should hold on to.
- Screenshot bytes are never the client's bytes. The upload is re-encoded to PNG by
  `PaymentScreenshotService` before it is written (so any payload or metadata riding along
  in the original is discarded) under a server-generated random filename on the private
  disk — the client's filename and declared MIME type are never used, and there is no
  public route that could serve the path by guesswork.
- **Protected video specifically** (Phase 5, docs/specs/phase-5-video.md): the `fake` driver
  (local/testing only) streams from the private `local` disk through a 10-minute
  `URL::temporarySignedRoute()` that re-runs `LessonAccess::lessonAccess()` on **every**
  request — not just when the signed URL was first generated — so access revoked between
  page render and playback (e.g. an enrolment revoked mid-session) is caught immediately, not
  only on the next page load. The `bunny` driver uses Bunny's own embed view token
  authentication (10-minute expiry) instead, signed with that specific tenant's own library
  API key — never the platform account key, and never another tenant's key (tested: a token
  computed with tenant A's key is never accepted for tenant B's video).

### Watermark is a deterrent, not a guarantee

The moving on-screen watermark (viewer's name, last 4 digits of phone or email local-part,
and today's date) makes casual screen-recording and simple frame sharing traceable back to
the student whose session produced it — it does **not** prevent recording, and a determined
viewer can still capture the video (screen recording, camera-on-screen, or removing the
watermark element via browser devtools before recording). Treat it as raising the cost and
traceability of leaking, not as DRM; do not describe it to institutes as such. Likewise the
`fake` driver's `<video>` element carries `controlsList="nofullscreen noremoteplayback"`,
`disablePictureInPicture`, and `oncontextmenu="return false"` — these discourage the
browser's own built-in fullscreen/casting/right-click-save affordances and are trivially
bypassed by anyone using devtools or a non-default browser; they are deterrents alongside the
watermark, not a security boundary. Per VIDEO.md's
former "Watermarking — OPEN DECISION", this phase resolves that decision in favour of the
dynamic per-viewer overlay approach (not Bunny's static library watermark), specifically so
recordings are traceable to an individual viewer rather than only proving *a* leak occurred.

## Rate limiting

- Auth endpoints (login, password reset), payment-sensitive endpoints, and any
  public-facing form are rate limited per IP and, where a tenant is already resolved, per
  tenant. Concretely today: the manual UPI **screenshot upload** allows 10 per hour per
  student (checked *first*, before any validation or file decoding, so an over-limit attempt
  is a 429 rather than an ordinary validation error), and the approve/reject endpoints are
  owner-only behind `PaymentPolicy` with an idempotent, no-op second approval — the human
  decision itself is not throttled, because a slow reviewer should never be locked out of
  their own queue.
- **Video upload (Phase 11, closed gap).** Both ends of the video-upload flow are limited
  to **10 requests per 60 seconds per IP**: `POST /manage/lessons/{lesson}/video/start-upload`
  (which registers the lesson video and, for the `bunny` driver, creates a real provider
  video — an unthrottled loop there burns Bunny quota on somebody else's bill) and `POST
  /lesson-videos/{lessonVideo}/upload` (the `fake` driver's byte-upload endpoint, which
  already had `signed` + `auth:tenant` + `manageContent` + a production 404). Proven both
  ways in `tests/Feature/Video/VideoUploadRateLimitTest.php`: the 10th request inside the
  budget still succeeds, the 11th is 429. The complete inventory of every public form and
  its limit is in [docs/SECURITY_PASS.md](docs/SECURITY_PASS.md) §7.
- **Closed gap (Phase 3.2): distributed login brute-forcing.** Tenant login was rate limited
  only by `tenant + identifier + IP` (5/minute). An attacker spreading failed attempts across
  many IPs (a botnet, rotating proxies, or a simple retry-with-a-new-IP loop) never tripped
  that limiter, since each individual IP stayed under the threshold — the identifier itself
  was never actually protected against a distributed attack. Fixed by adding a second limiter
  keyed by `tenant + identifier` alone, regardless of IP (20 failed attempts/60 minutes),
  checked and incremented alongside the existing per-IP one. Both return the same generic
  "too many attempts" response, so neither limiter leaks which one tripped or whether the
  identifier exists (`tests/Feature/Auth/RateLimitTest.php`).

## Institute branding and the installable PWA (Phase 6)

- **Uploaded logos are never trusted as-is.** No SVG is accepted (script/XSS risk from
  embedded `<script>`/event-handler attributes in SVG markup) — only PNG, JPG and WebP.
  Dimensions are checked via `getimagesize()` *before* the file is decoded, to reject an
  oversized or decompression-bomb-style image without ever handing it to GD. The accepted
  file is then decoded and **always re-encoded to a fresh PNG** — this strips any embedded
  metadata or payload from the original file (a GIF or arbitrary binary renamed to `.png`
  is rejected by content-based MIME sniffing before it ever reaches the decode step, but
  the re-encode is a second, independent line of defence). The stored file always gets a
  random filename under `tenants/{id}/branding/` on the **private** disk — the client's
  original filename is never used for anything, including the stored path.
- **The service worker (`resources/js/sw.js`, served at `GET /sw.js`) never stores
  anything user-specific.** It precaches only the generic offline page, and its fetch
  handler only ever caches static, non-personalized responses: built assets under
  `/build/…`, PWA icons, and the header logo. It explicitly never intercepts
  `/lesson-videos/…`, any `/attachments/…` URL, `/manage`, `/users`, `/admin`, `/auth`,
  `/login`, `/logout`, `/dashboard` or `/courses` pages, any request carrying a `Range`
  header, or a non-2xx/non-`basic` response — and it never caches a navigation response
  (an HTML page) at all, even the offline page's own network-first attempt. Students
  routinely share phones; nothing that could belong to one student's session may ever land
  in another user's Cache Storage on the same device.

## Lesson progress endpoints (Phase 7)

- `POST /lessons/{lesson}/progress` (heartbeat) and `PUT /lessons/{lesson}/completion`
  (manual mark/unmark) sit behind the same `auth:tenant` -> `active.tenant.user` ->
  `must.change.password` stack as `/dashboard` — a guest gets the framework's normal
  401 (JSON) / redirect (HTML), never a bespoke check in the controller.
- **Progress is recorded only for a student with a genuinely valid enrolment** —
  `App\Http\Controllers\LessonProgressController` calls `LessonAccess::lessonAccess()`
  **and** `LessonAccess::hasValidEnrolment()`, exactly mirroring (never re-deriving) the
  rule the lesson page itself uses. This matters specifically for a free-preview lesson:
  `lessonAccess()` returns `'ok'` for anyone (that's the point of a preview), but a
  non-enrolled viewer still gets a 403 from these two endpoints and nothing is stored —
  owner/staff previewing get a `204` and nothing is stored either.
- **All server-side capping, never trusting the client's numbers.**
  `App\Support\ProgressRecorder` is a pure class (plain arrays in, plain arrays out, no
  Eloquent, no ambient clock) that: accepts a `duration` only within 10% of the
  previously-stored value once one is set; caps accepted watched-time per heartbeat at
  `min(played, secondsSinceLastHeartbeat + 5, 60)` (20s on the very first heartbeat);
  never lets `watched_seconds` decrease or exceed `duration`; and completion (auto at
  85% watched, or manual) is sticky — no heartbeat, duplicate, or out-of-order request
  can ever un-complete a lesson. `watched_seconds`, `completed_at`, `completed_manually`,
  `last_heartbeat_at`, and `last_activity_at` are guarded (never mass-assignable) on
  `LessonProgress`, matching this: they're set only via `ProgressRecorder`'s computed
  output through `forceFill()`, never from raw request input.
- **Row-locked read-modify-write.** The controller loads (creating if needed) and
  `lockForUpdate()`s the student's `lesson_progress` row inside a single
  `DB::transaction()` that also does the save — two concurrent heartbeats for the same
  `(tenant, lesson, user)` (two tabs/devices) can't race each other's update.
- **Throttled** to `config('coaching.progress_heartbeat_max_attempts')` (12) requests per
  minute, keyed by tenant + user + lesson id, independently for the heartbeat and
  completion endpoints. `abort(429)` — the friendly `errors/429.blade.php` page for an
  HTML request, plain JSON otherwise (Laravel's default `expectsJson()` negotiation).
- **No third-party scripts anywhere on the lesson page.** `resources/js/progress-
  tracker.js` (the pure playback-accounting logic) and `resources/js/lesson-
  progress.js` (the DOM/fetch wiring) are both bundled through Vite — no Google/YouTube
  IFrame API script, no analytics, no CDN `<script>` tag. `player.js` (Bunny's embed
  protocol library) is an npm dependency, pinned, and only ever `import()`-ed
  dynamically when `data-driver="bunny"` — a `fake`-driver or non-video lesson page
  never loads it at all.
- **A stopped-access tab stops sending heartbeats.** If a heartbeat/completion request
  comes back 401, 403, or redirected (a session that's been logged out, a disabled
  student, or a revoked enrolment mid-session), the tracker calls `.stop()` and never
  sends another request for the rest of that page view — a student whose access just
  ended doesn't keep hammering the login page every 15 seconds of "playback".
- The service worker (`resources/js/sw.js`) only ever intercepts `GET` requests (see
  "Institute branding and the installable PWA" below) — the heartbeat/completion `POST`/
  `PUT` requests are never touched by it, and `sw.js` itself was not changed for this
  phase.

## One device per student (Phase 8)

- **`user_devices` is tenant-owned** (`tenant_id`, `BelongsToTenant`, composite FK on
  `(tenant_id, user_id)` → `users(tenant_id, id)`, `cascadeOnDelete`) and holds, per
  student device: `device_id` (a 32-hex-char random cookie value), a human-readable
  `label` ("Chrome on Android", parsed from the user agent by `App\Support\Devices\
  DeviceLabelParser` — no third-party service, no network), the truncated (255-char)
  user agent, first/last seen timestamps, and `revoked_at`/`revoked_reason`/
  `notified_at`. **No IP address is stored anywhere** — not on this table, not derived
  from the request elsewhere in the device-tracking path
  (`tests/Feature/Devices/PrivacyAndCleanupTest.php` asserts this directly, including
  that a login from a given IP never leaves that IP recoverable from the stored row).
- Limit applies to `role = student` only; owner/staff are never tracked as devices or
  limited. `tenants.max_devices_per_student` (1–3, default 1) is owner-editable on
  `/manage/settings`.
- Registration happens via `App\Listeners\RegisterUserDevice`, listening on Laravel's
  own `Illuminate\Auth\Events\Login` for the `tenant` guard — never by editing the login
  controller. Laravel fires this same event on a remember-me cookie restore, so a
  returning "remembered" session reuses its existing device row rather than minting a
  new one.
- Enforcement is the `device.limit` route middleware (`App\Http\Middleware\
  EnforceDeviceLimit`), applied wherever `active.tenant.user` already is: a no-op for a
  guest or for owner/staff (no device-table query at all in that case); for an
  authenticated student it resolves the device by cookie, registers one on the fly for
  a pre-existing session with no cookie yet, and signs the student out (guard logout,
  session invalidated, token regenerated) the moment their device's row is found
  revoked — HTML gets a redirect to `/login` with the message flashed, JSON gets `401
  {"code":"device_revoked"}`. `last_seen_at` is updated via a single atomic conditional
  `UPDATE ... WHERE last_seen_at < now() - 60s` (never read-then-write), bounding the
  middleware to at most one `SELECT` and one `UPDATE` against `user_devices` per
  request.
- **Anything that changes a student's credentials revokes devices**: an owner/staff
  password reset revokes *all* of that student's devices (`password_reset`); the
  student changing their own password (`ChangePasswordController`) revokes every
  *other* device but keeps the current one (`password_changed`); disabling a student
  revokes all of their devices (`disabled`) in addition to the existing
  `active.tenant.user` block. A normal logout revokes just that one device
  (`logout`), freeing the slot — logging back in on it reactivates the same row rather
  than counting as a new device.
- **Known limit #1**: a Bunny embed URL issued before a revocation stays valid until
  its own expiry (max 10 minutes) — Bunny's token authentication has no server-side
  revocation hook. The local `fake` driver's stream re-checks access on every request
  (see "Protected video specifically" above) and stops immediately; a `bunny`-driver
  deployment does not get that same immediacy for an already-issued embed token.
- **Known limit #2**: two people sharing one login can "ping-pong" the device slot —
  each login evicts the other's device, so both can keep using the account by
  re-logging-in after being kicked, indefinitely. This is by design (a login never
  *fails* due to the limit — the newest login always wins) rather than a bug; the
  owner-visible "Switching devices often" flag (`/users/{user}/devices`, ≥5
  replacements in the last 7 days, `config('coaching.device_switch_flag')`) is the
  intended detection mechanism for this pattern, not an automatic block.

## Bulk student import (Phase 9)

- **Temporary passwords are never stored in plain text anywhere** — not the database, not
  logs, not a queue payload. Between preview and confirm, and between confirm and the
  credentials sheet being viewed/downloaded/cleared, the only place a plaintext temporary
  password exists is an **encrypted payload in the creator's own PHP session**
  (`App\Support\Import\ImportSessionStore`, `Crypt::encryptString`), never readable by
  anyone else — every read re-checks the payload's own `tenant_id` and `created_by` against
  the current request's tenant and authenticated user, not just PHP session boundaries (see
  TENANCY.md: an ambient scope alone is never trusted as the isolation boundary).
- **Both the import token and the credentials sheet expire after 15 minutes.** The
  credentials sheet is additionally served with `Cache-Control: no-store, private` and
  `X-Robots-Tag: noindex`. "Clear now" removes the plaintext rows immediately by
  overwriting the session payload with only its identity fields (`tenant_id`,
  `created_by`, no `created_at`) — the ownership check on the next request still works
  correctly (a 404 for anyone but the creator), but there is nothing left to show, and the
  same "no longer available" page is shown as a genuine 15-minute expiry.
- **CSV formula injection** (both the credentials-sheet download and the progress export):
  any cell whose value begins with `=`, `+`, `-`, `@`, a tab, or a carriage return is
  prefixed with a single quote before being written (`App\Support\Csv\CsvFormulaGuard`),
  the standard mitigation — a student or CSV row can never get a formula to execute when
  the file is opened in Excel/Sheets.
- **Import limits**: `.csv` extension required, 1 MB max file size, and a data-row cap
  (`CsvImportPreviewBuilder::MAX_ROWS`, **45** — see docs/specs/phase-9-pilot.md for why
  this was lowered from the brief's default 100 after measuring real bcrypt cost) enforced
  before any row is parsed. The upload is rate limited to 10 attempts/hour/user
  (`throttle:10,60` on `POST /users/import`).
- **Content-based file check, deliberately not MIME-sniffing-based**: Laravel's `mimes:`
  rule (finfo content sniffing) can misclassify a legitimate CSV row containing
  markup-like text (e.g. a student named with an HTML tag) as `text/html` and wrongly
  reject it. The upload is instead checked for a high share of raw control bytes in its
  first 8KB — a reliable binary/text signal that a real CSV never trips, regardless of
  what a cell's text happens to contain.

## Live classes (Phase 12)

- **The Jitsi room name and join URL never reach a rendered page.** They exist server-side
  only, and leave it exclusively inside the 302 `Location` of the Join redirect
  (`App\Support\LiveClasses\JitsiJoinUrl`), so a page, a log line or a referrer can never
  leak a room. The room name itself is generated in `LiveClass`'s `creating` hook — 32
  random letters plus a tenant-derived `[a-f]` suffix, digit-free so no numeric id can
  appear inside one — and is deliberately absent from `$fillable`; a posted
  `jitsi_room_name` is ignored and `UNIQUE(tenant_id, jitsi_room_name)` makes a forced
  duplicate loud.
- **The JaaS app secret is a signing key, nothing else.** `JITSI_APP_SECRET` is read from
  config, used by `JitsiJwt` to sign an HS256 token (`iss`/`aud` = app id, `exp` = +120
  minutes, display name/email under `context.user`) inside the join request, and is never
  rendered, logged, or accepted from a client — asserted by
  `tests/Feature/LiveClasses/LiveClassSecurityTest.php`. The token is minted and consumed
  in one request: there is no token endpoint on our app to replay against.
- **Entry is re-derived server-side on every verb.** `LiveClassPolicy` requires
  staff/owner or a valid enrolment for *view*, and *join* additionally requires the class
  to be open (`live`, or scheduled inside the `LIVE_CLASSES_JOIN_WINDOW_MINUTES` window,
  default 15). The join page answers refusals with a friendly flash; the heartbeat API
  answers every refusal with a flat **403** — and the policy runs **before** the
  rate-limit throttle, so an unauthorised caller can never consume an enrolled student's
  heartbeat budget.
- **Attendance columns are server-derived, full stop.** The heartbeat request body is
  ignored outright: credited time is the wall-clock delta since the row's own
  server-written `last_seen_at`, capped at **60 seconds per beat** (2 beats/minute/user/
  class, third → 429), and the stale sweep backdates `left_at` to that same
  `last_seen_at`, never to "now". Forged timestamps can neither open, close, nor inflate a
  stay.
- **The report and its CSV export are staff-only** (`manageLiveClasses` on the course) and
  formula-guarded like every other CSV in the app (`CsvFormulaGuard`), with a UTF-8 BOM so
  Excel doesn't mangle names.
- **Everything is 404 while `LIVE_CLASSES_ENABLED=false`** (the default) — the feature's
  mere existence isn't visible, and `app:preflight` fails production if the flag is on
  without both JaaS keys.

## Platform admin control panel (Phase 13)

- **Every control-panel route sits behind `PlatformAdminAuth:platform_admin`**
  (`app/Http/Middleware/PlatformAdminAuth.php`) — a subclass of Laravel's `Authenticate`
  that checks tenant identity *first*. An authenticated tenant session on any Phase-13
  route gets a **flat 403**, never the framework's redirect to `/admin/login`, and guests
  keep the redirect they have always had (both pinned in `AdminRouteGuardTest` /
  `PlatformAdminGuardTest`). The tenant check lives *inside* the auth middleware rather
  than in a separate middleware listed before `auth` because the router sorts resolved
  middleware against the priority list (`Illuminate\Foundation\Configuration\Middleware::$defaultPriority`,
  extended by `prependToPriorityList` in `bootstrap/app.php`) **after** resolving names:
  `Authenticate` matches that list through its `AuthenticatesRequests` interface and gets
  spliced ahead of any standalone middleware sitting after the `web` group, so a
  pre-auth 403 middleware would run second — after auth had already redirected. Being the
  auth middleware means the check travels to whatever slot sorting picks, which is after
  `StartSession` (a real tenant session cookie is readable there) and before
  `SubstituteBindings`. `RejectTenantSession` + its alias were deleted once this landed.
- **Login-as-owner is a signed, single-use, 60-second URL bound to the tenant's own
  host.** `InstituteController::loginAsOwner` mints it on the **tenant host**
  (`URL::temporarySignedRoute('admin.impersonate', +60s, [user, admin])` under
  `URL::forceRootUrl`, restored in a `finally`) so the HMAC covers
  `https://<tenant-host>/admin/impersonate?…` including host and expiry: a link minted for
  tenant A cannot verify on tenant B (403), and an expired link cannot verify anywhere
  (403). Consumption is gated `signed` → `Cache::add` (single-use burn, replay → 403) →
  TenantScope-resolved user (a user id from another tenant is a 404) → **owner-role check**
  (a staff/student id signed by an attacker with a valid key is still 403) → audit row
  **written before** `Auth::guard('tenant')->login` + session regeneration → redirect to
  `/dashboard`. Every state (mint, consume, refusal) is recorded in `admin_audit_logs`
  with the admin id carried in the signed query.
- **The `.env` editor is an allowlist, not a file editor.** `SystemEnvController::ALLOWED`
  (15 operational keys: `APP_NAME`, `APP_URL`, `MAIL_*`, `SESSION_*`, `VIDEO_DRIVER`,
  `PLATFORM_DOMAIN`, `TENANT_BASE_DOMAIN`, `CENTRAL_DOMAINS`) — `APP_KEY`, `DB_*`,
  `APP_ENV` and `APP_DEBUG` are deliberately absent (only the APP_DEBUG-gated health Fix
  can touch `APP_DEBUG`). A non-allowlisted key is a `ValidationException` (422), a
  newline inside a value is rejected as env-injection, a blank value means "keep the
  current entry" (nothing written, nothing audited), writes go through the `EnvFile`
  seam as offset splices (never `preg_replace` replacement strings, which corrupt `\` and
  `$`), and the real `.env` is never touched in tests. Every written key gets an
  `update_setting` / `env:KEY` audit row.
- **Service secrets are encrypted at rest and never round-trip to the client.** Service
  settings are stored encrypted via `ServiceSettings` (DB-first, `config/services.php`
  fallback); the settings form shows only `MAIL_PASSWORD` masked; Bunny usage reporting
  calls the account-API endpoint with the key in the `AccessKey` header and never reads
  the key back out. Test Connection buttons re-verify from scratch: Bunny does a live
  `GET https://api.bunny.net/videolibrary` (throttled 10/min), Jitsi does a local
  `JitsiJwt::verify()` round-trip — neither echoes the secret.
- **The health Fix is double-gated and server-sourced.** The fix buttons render only when
  `APP_DEBUG` is on, and the endpoint re-checks `config('app.debug')` and 403s when it is
  off. The value written comes exclusively from `PreflightChecks`' server-side fix map —
  any `key`/`value` fields posted by the client are ignored — and the run is audit-logged
  as `update_setting` / `health_fix:KEY`.
- **The audit log is append-only and survives deletion.** `admin_audit_logs` has
  `UPDATED_AT = null`, no foreign keys (a deleted admin renders as a raw id, never a
  crash), and records attempts as well as successes (`backup_run` rows exist even when the
  backup failed; failed platform-admin logins are *not* recorded — they are not an action
  by that admin).

## DPDP consent and student erasure (Phase 15)

- **Consent is validated server-side and written in the same transaction as the
  student.** `POST /users` (and the CSV import's confirm step) refuses to create a
  student unless all four purposes (`course_delivery`, `progress_tracking`,
  `communication`, `media_processing`) are present, each value is a `ConsentPurpose`
  enum case, the collection method is a `ConsentMethod` case, and the guardian block is
  complete — then writes the rows alongside the user, stamped with `notice_version`
  (`Consent::NOTICE_VERSION`) and `recorded_by` (the acting owner/staff). A missing
  purpose fails with the single `consents` key and `consents.errors.purposes_required`.
  The guardian columns are deliberately **not** in the `User` fillable array, so request
  input can never write them directly; controllers `forceFill` them after validation.
- **Consent rows are guarded, not fillable.** Only `user_id`, `purpose` and `method` are
  fillable — `tenant_id`, `guardian_id`, `recorded_by`, `granted_at`, `withdrawn_at`,
  `withdrawn_reason` and `notice_version` cannot be set through mass assignment
  (`ConsentSchemaTest` proves it with a positive control). Every FK is composite
  `(tenant_id, …)` → `users(tenant_id, id)`, so no consent can name a user outside its
  tenant even when ids collide.
- **Withdrawal is enforced at the controller layer; `LessonAccess` stays untouched.**
  `ConsentAccess::courseDeliveryWithdrawn()` reads the student's *latest*
  `course_delivery` grant (a later re-grant unlocks again) and `CourseController::show()`
  / `LessonController::show()` abort 403 before anything renders. Students with no grant
  are not blocked (blocking on missing consent is a documented scope-out, so pre-Phase-15
  students keep working). Withdrawing `progress_tracking` hard-deletes only that
  student's `lesson_progress` rows through the tenant-scoped query builder.
- **Erasure is a transaction that snapshots before it deletes**
  (`StudentDataController::erase`, owner/staff only via `UserPolicy::manageStudentData`,
  cross-tenant ids 404 through `TenantScope`):
  1. the typed confirmation must equal the student's **exact current name** or the
     request fails with `consents.errors.confirmation_mismatch` and changes nothing;
  2. key fields of the student's enrolments, live-class attendance and payments are
     snapshotted as JSON into `consent_audit_logs.metadata` (`action = student_erased`,
     `user_id` = student, `actor_id` = acting owner/staff) **before any delete** —
     `payments.enrolment_id` is `ON DELETE CASCADE`, so the snapshot is the only
     financial record that survives the next step;
  3. `lesson_progress`, `user_devices`, `live_class_attendance` and `enrolments` rows are
     hard-deleted (this repo has no soft deletes — "delete" means gone), taking their
     payment rows with them;
  4. the user row is **anonymised, never dropped**: name `Deleted Student`, email
     `erased-{id}@removed.invalid` — a sentinel under RFC 2606 that can never resolve or
     receive mail, kept non-null because `users_email_or_phone_check` still requires a
     contact value (the CHECK is not relaxed) — phone and guardian columns null,
     `remember_token` null;
  5. consent rows survive: they are the audit trail, not personal data.
- **Known gap (flagged, not hidden):** an erased student's live session is **not** purged
  — the cookie stays valid until expiry even though the row behind it is anonymised.
  The Phase 15 spec's unresolved-risks section records this; session purge on erasure
  would belong with any future "sessions cleanup" work.

## Custom domains and TLS

- Tenants may eventually bring a custom domain instead of `*.coaching.test`
  (see [ROADMAP.md](ROADMAP.md), Stage C).
- TLS for custom domains is issued via **Caddy's on-demand TLS**, which calls back to a
  Laravel "ask" endpoint before issuing a certificate for any hostname it doesn't already
  know about.
- That "ask" endpoint only approves hostnames that already exist as a **verified** row in
  `tenant_domains` (ownership/DNS verification completed). It must never approve an
  arbitrary hostname on request — an unrestricted "ask" endpoint would let anyone obtain a
  TLS certificate for any domain by simply requesting it through Caddy, which is why domain
  verification must happen and be recorded *before* the ask endpoint will say yes.
