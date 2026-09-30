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
- Access to any of them goes through **signed, expiring URLs**. This applies to PDFs
  (course material, invoices, receipts) exactly as it does to video playback — a PDF is not
  treated as "less sensitive" just because it isn't streamed.
- Signed URLs are generated per-request, scoped to the requesting user's tenant and
  enrollment/ownership, and expire quickly enough to limit the value of a leaked link.
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

- Auth endpoints (login, password reset), payment-sensitive endpoints (manual UPI approval,
  webhook receivers), and any public-facing form are rate limited per IP and, where a tenant
  is already resolved, per tenant.
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
