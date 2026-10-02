# Mini security pass — Stage B (Phase 11)

## 1. Date and HEAD commit

- **Date:** 2026-10-02
- **HEAD at the start of this pass:** `f8d5b6a` ("Phase 10: manual UPI payment flow", tag `phase-10`)
- **Result:** committed as *"Phase 11: mini security pass"* and tagged **`phase-11`**.
  (This file cannot contain its own commit SHA — use the `phase-11` tag to identify it.)
- **Gates:** all five green — raw final lines are in the chat report that accompanied this document.

---

## 2. Summary

This pass read `SECURITY.md`, `AGENT_RULES.md`, `TENANCY.md`, `CLAUDE.md`, the README's
"Strengths" and "Weaknesses and known gaps", and `PROJECT_BRIEF.md` §6/§7 first, then walked
eight ordered checks over the live codebase: app-level security headers, session/cookie
policy, secrets hygiene, rate limits, signed-URL discipline, the `password_reset_tokens`
risk, `app:preflight` completeness against `docs/DEPLOY.md`, and a fresh-eyes read of
`bootstrap/app.php`. **Ten findings** were recorded — 5 medium, 5 low. **Three were fixed in
code, each with a test**: a `SESSION_DOMAIN` preflight check that `docs/DEPLOY.md §3` had
always claimed existed but never had; three missing failing-branch tests for `app:preflight`
(`VIDEO_DRIVER=fake`, `VIDEO_DRIVER=bunny` with no account key, GD without FreeType); and a
missing rate limit on both video-upload endpoints (10/60s each). **Two more were fixed as
documentation** — `SECURITY.md` claimed a signed-URL control that lesson attachments do not
have, and nothing anywhere told anyone to configure CSP/`X-Frame-Options`/HSTS — with their
code sides deferred as open items. **Five remain open.** One further §3 requirement was met
without being a finding: the Phase 5 "raw secret never reaches HTML" pattern now also covers
Phase 10 payment screenshots. What came back clean: nothing in `app/` logs request input
(`Log::` appears zero times), all 12 `{!! !!}` occurrences in Blade are one helper returning
a constant string, `SESSION_DOMAIN` is never widened by any code path, trusted proxies are
pinned to Cloudflare's IP ranges with `X-Forwarded-Host` deliberately untrusted,
`RequireTenant` is pinned ahead of `SubstituteBindings`, `password_reset_tokens` has no route
and no writer, all nine public forms are rate limited, and video/payment-screenshot assets
re-run their access check on every request rather than only at render time.

---

## 3. Findings table

| # | Severity | Location | Description | Fixed? | Evidence |
|---|---|---|---|---|---|
| 1 | medium | `app/Console/Commands/AppPreflightCommand.php` | `docs/DEPLOY.md §3` states that `SESSION_DOMAIN` staying unset "is checked by `php artisan app:preflight`". It never was. A production `.env` containing `SESSION_DOMAIN=.coaching.test` — which would make the browser send one tenant's session cookie to every other tenant subdomain — would pass preflight green. | **Yes** | `checkSessionDomain()` added; `AppPreflightCommandTest` "…empty string docs/DEPLOY.md tells you to use" (passes) and "…widens the session cookie to a shared parent domain" (fails) |
| 2 | medium | `tests/Feature/Console/AppPreflightCommandTest.php` | Three `app:preflight` checks had code but no failing-branch test: `VIDEO_DRIVER=fake` in production (explicitly flagged as missing by `PROJECT_BRIEF` §6), `VIDEO_DRIVER=bunny` with an empty `BUNNY_STREAM_ACCOUNT_API_KEY`, and GD loaded without FreeType. The `fake` driver's production refusal is the guard that keeps locally-stored video out of production. | **Yes** | 3 new `assertFailed()` tests added alongside the existing positive control |
| 3 | medium | `routes/web.php` | Neither video-upload endpoint was rate limited. `POST /manage/lessons/{lesson}/video/start-upload` creates a real provider video for the `bunny` driver, so a stolen teacher session could loop it and burn the platform's Bunny quota; `POST /lesson-videos/{lessonVideo}/upload` (the `fake` driver's byte sink) had `signed` + `auth` + a production 404 but no limit. | **Yes** | `throttle:10,60` on both; `tests/Feature/Video/VideoUploadRateLimitTest.php` — request 10 → 200/204, request 11 → 429, for each endpoint |
| 4 | medium | `SECURITY.md` "Private storage and signed URLs" vs `routes/web.php` | The doc claimed *all* private PDFs go behind signed, expiring URLs "exactly as it does for video playback". Lesson attachments are not signed at all — the route has no `signed` middleware, so the link never expires and is not tamper-evident. Access **is** re-derived from scratch on every request by `LessonAccess::lessonAccess()`, so this is a documented-control-that-doesn't-exist plus a weaker-than-claimed link, not an open door. | **Doc fixed; code deferred** | `routes/web.php` attachment route carries no `signed`; `LessonAttachmentController::show:20-32` runs `lessonAccess()` per request. Correction is in `SECURITY.md`; the code change is open item **B** |
| 5 | medium | `app/` (absent) + `docs/DEPLOY.md` §1 | No CSP, `X-Frame-Options` or HSTS anywhere in the application. README weakness #8 asserted `docs/DEPLOY.md` "assumes Cloudflare supplies them" — it did not mention security headers at all, so in production nothing was set and nothing told anyone to set it. | **Documentation fixed; app-level CSP deferred** | New "Response security headers" section in `SECURITY.md` (what the app sets vs what the edge must supply) + new `docs/DEPLOY.md` §1.6 with the Cloudflare steps. App-level CSP is open item **A** |
| 6 | low | `app/Http/Controllers/Manage/LessonVideoController.php:89` and `:114` | The only two error-logging calls in `app/` are `report($e)` on the upload and status-refresh paths. Laravel's `QueryException::formatMessage()` interpolates bindings into the SQL text (`vendor/laravel/framework/src/Illuminate/Database/QueryException.php:89`), so a failed write carrying a client-supplied `filename` would put that value into `storage/logs/laravel.log`. | No | `grep -rn "Log::" app/` → **0 matches**; `report(` → exactly the two lines above; `QueryException.php:85-92` shown in-vendor |
| 7 | low | `app/Console/Commands/AppPreflightCommand.php:21` | `handle()` returns `SUCCESS` immediately unless `APP_ENV=production`, so a deploy that forgets `APP_ENV=production` gets a green preflight while `APP_DEBUG=true`. It is not silent — it prints "Not running in production…" — and `docs/DEPLOY.md §5` documents the behaviour, but nothing forces the operator to read that line. | No | Code + `docs/DEPLOY.md §5` ("Outside production it's a no-op"); test `is a no-op outside production` asserts the success path |
| 8 | low | `.env.example` | `BUNNY_STREAM_ACCOUNT_API_KEY=` has no placeholder line, although `docs/DEPLOY.md §3` says to "base it on `.env.example`, then set at minimum" that very key. A production `.env` built from the template alone ships without it (finding 2's new test proves preflight would then catch it, but only in production). | No | `grep -in bunny .env.example` → no match; `config/services.php` reads `BUNNY_STREAM_ACCOUNT_API_KEY` |
| 9 | low | `app/Console/Commands/AppPreflightCommand.php:124` `checkMysqlVersion()` | The only preflight check with no failing-branch test. Unlike `gd_extension_loaded` it has no seam in `config/preflight.php` to mock against, so the wiring (not just `DatabaseVersionCheck`) is untested. | No | `grep -rln mysql_version tests/` → only `tests/Unit/Support/DatabaseVersionCheckTest.php` (the pure function) |
| 10 | low | local `.env` | Carries `SESSION_LIFETIME=120` while `config/session.php` defaults to `43200` and `.env.example` ships `43200`. Local only, gitignored, already recorded as README weakness #5. | No | `php -r` boot → `config('session.lifetime') === 120`; `config/session.php:35` default `43200`; `.env.example:42` = `43200` |

### Checked and clean (no finding)

- `SESSION_DOMAIN` is never widened by code — `grep` over `app/`, `config/`, `routes/`,
  `bootstrap/` finds only `config/session.php:159`. `Cookie::queue()` for the device cookie
  inherits the same host-only domain, so it cannot escape the tenant either.
- `.env.example` has `SESSION_DOMAIN=null` (line 45), `SESSION_SECURE_COOKIE=false` (line 48,
  with a production warning) and `SESSION_LIFETIME=43200` (line 42); `config/session.php`
  defaults to `43200`. `SESSION_DOMAIN=null` resolves to PHP `null` and `SESSION_DOMAIN=`
  (the `docs/DEPLOY.md` form) resolves to `''` — both verified by booting the app, and both
  accepted by the new check.
- **Zero `Log::` calls in `app/`.** No code path logs request input, and no Blade view
  serialises a session or credential.
- **All 12 `{!! !!}` occurrences** are `layouts/app.blade.php` using `$isCurrent()`, which
  returns either `' aria-current="page"'` or `''` — a constant, with no user data in it.
- `password_reset_tokens`: no route, no writer, no broker usage (see §6).
- Trusted proxies: Cloudflare's published ranges only, `X-Forwarded-For` + `X-Forwarded-Proto`
  only, `X-Forwarded-Host`/`-Port` deliberately excluded and commented as to why.
- `RequireTenant` is pinned ahead of `SubstituteBindings` in the middleware priority list;
  `CheckTenantSuspended` and `SetReferrerPolicy` are appended to the `web` group, i.e. after
  `StartSession`.
- 500s cannot leak a stack trace in production: `config/app.php:42` defaults `APP_DEBUG` to
  `false`, `docs/DEPLOY.md §3` requires it, and preflight enforces it (there is no custom
  `errors/500.blade.php`, and the custom 403/404/429 views are static).
- Bunny keys never reach HTML: `EmbedTokenPlaybackTest.php:66,170` assert the account key and
  the freshly-issued library key are absent from rendered output.
- Payment screenshots (Phase 10) already had signed/tampered/expired/another-student's URL
  tests, private-disk-only assertions and a 10/hour upload limit — this pass **added** the
  missing piece of the Phase 5 pattern: the private storage path itself never appears in HTML.

---

## 4. Fixed in this pass

| Change | Files | Test that proves it |
|---|---|---|
| `checkSessionDomain()` — refuses any `SESSION_DOMAIN` other than `null`/`''`, registered in `handle()` | `app/Console/Commands/AppPreflightCommand.php` | `AppPreflightCommandTest`: *"passes when SESSION_DOMAIN is the empty string docs/DEPLOY.md tells you to use"* and *"fails when SESSION_DOMAIN widens the session cookie to a shared parent domain"* (baseline `passingPreflightConfig()` now pins `session.domain => null`) |
| Failing-branch tests for `app:preflight` | `tests/Feature/Console/AppPreflightCommandTest.php` | *"fails when VIDEO_DRIVER is fake"*, *"fails when VIDEO_DRIVER is bunny but the account API key is empty"*, *"fails when GD is loaded but has no FreeType support"* — each `assertFailed()`, against the existing `"passes when everything is configured correctly"` positive control |
| `throttle:10,60` on both video-upload endpoints | `routes/web.php` (2 routes) | `tests/Feature/Video/VideoUploadRateLimitTest.php` — 2 tests, 11 requests each: requests 1–10 succeed (positive control), request 11 → 429 |
| Phase 5 secret-hygiene pattern extended to Phase 10 payment screenshots: the private-disk `screenshot_path` must never appear in rendered HTML, while the signed URL must | `tests/Feature/Payments/PaymentScreenshotTest.php` | *"the private storage path of a screenshot never appears in rendered HTML"* — owner review page (asserts the signed `/payments/{id}/screenshot` URL **is** present, the path **is not**) + student payment page (path is not) |
| Corrected `SECURITY.md`'s signed-URL claim, added the video-upload rate-limit bullet, added the `password_reset_tokens` deferral decision, added the "Response security headers" matrix | `SECURITY.md` | Covered by the tests named above; the matrix rows cite the code that emits each header |
| Cloudflare security-header config steps; `SESSION_DOMAIN` added to the preflight inventory | `docs/DEPLOY.md` §1.6, §5 | — (documentation) |
| README weakness #8 rewritten to record the headers decision and where it is configured | `README.md` | — (documentation) |

No application logic outside the list above was touched. `TenantScope`, `TenantBuilder`,
`BelongsToTenant`, `TenantUserProvider` and the `LessonAccess` rules were **not** modified.

---

## 5. Open items

| ID | Finding | Recommendation |
|---|---|---|
| **A** | 5 — no CSP at the app level | **This is the one decision that needs a human.** See §5a below. Default taken in this pass: delegate to Cloudflare. |
| **B** | 4 — lesson attachments are not signed | Defer to Stage C. Adding `signed` means `URL::temporarySignedRoute()` in `resources/views/lessons/show.blade.php` and `resources/views/manage/lessons/edit.blade.php`, plus edits to `tests/Feature/Courses/AttachmentsTest.php` (a Phase 4 file with ~10 URL-built assertions). Do it when invoices/receipts arrive, so the whole private-PDF surface gets one consistent treatment. Until then `SECURITY.md` now describes what actually happens. |
| **C** | §6 — `password_reset_tokens` | Defer to Stage C; decided in §6 below. |
| **D** | 6 — `report($e)` can log client-supplied `filename` via `QueryException` bindings | Catch `Illuminate\Database\QueryException` separately in `LessonVideoController` and report a redacted message (connection name + error code only), or move `original_filename` out of the statement that can fail. Low impact: `storage/logs` is outside the web root and never served. Suggest bundling with any future logging work — there is currently *no* structured logging anywhere, so introducing a policy is a bigger conversation than one call site. |
| **E** | 8 — `.env.example` lacks `BUNNY_STREAM_ACCOUNT_API_KEY=` | Add one commented placeholder line next to the session block. Not done here: `.env.example` is not in the sanctioned fix list and the requirement is already stated in `docs/DEPLOY.md §3`. |
| **F** | 9 — `checkMysqlVersion()` untestable | Add `preflight.forced_database_version` to `config/preflight.php` (mirroring `gd_extension_loaded`) and a failing-branch test. |
| **G** | — `PROJECT_BRIEF.md` §6 is now stale in two rows | Its "SESSION_DOMAIN preflight check is missing" and "no failing test for the VIDEO_DRIVER branches" weaknesses are closed by this pass. Correcting `PROJECT_BRIEF.md` was not in scope; noted here so the next reader isn't misled. |

### 5a. The decision: app-level CSP, or Cloudflare?

**Recommendation: keep it at Cloudflare for now (what this pass implemented), and treat an
app-level CSP as a Stage C item.**

- An app-level CSP worth having needs nonces. The view layer has **17 inline event handlers**
  (13 `onsubmit`, 3 `onclick`, 1 `oncontextmenu`), **3 inline `<script>` blocks** and **201
  inline `style="…"` attributes across 29 templates**. Threading a per-request nonce through
  all of that is a template refactor, not a security patch — explicitly out of bounds here.
- A permissive CSP (`script-src 'unsafe-inline'`) would be close to decorative while still
  carrying real breakage risk.
- A strict CSP would break the Phase 4 YouTube free preview: `lessons/show.blade.php` iframes
  `https://www.youtube-nocookie.com` and a Bunny player URL built at runtime, and
  `tests/Feature/Courses/YoutubeTest.php` is the canary for exactly this.
- `X-Frame-Options` and HSTS are the cheap ones, but Cloudflare terminates TLS and answers
  before the origin, so the edge is the more natural home for HSTS regardless. Bundling them
  with CSP at the edge keeps one owner for all three.

If you would rather own the headers in the application, say so and a follow-up phase can add
a `SetSecurityHeaders` middleware (`X-Frame-Options`, `X-Content-Type-Options`, HSTS) with
tests now, and a nonced CSP as the larger second step.

---

## 6. The `password_reset_tokens` decision

**Deferred to Stage C.** Not fixed now.

- **Verified:** the table is created by the stock framework migration
  (`0000_01_01_000000_create_users_table.php`) and referenced only by `config/auth.php:119`
  (`AUTH_PASSWORD_RESET_TOKEN_TABLE`). `grep` over `app/`, `routes/`, `config/`,
  `resources/views/` finds no `Password::` call, no `password.email` route, no broker usage,
  and no view. No code writes a token to it.
- **Explicitly stated:** `password_reset_tokens` is **not tenant-scoped** — primary key
  `email`, no `tenant_id` column — and **no self-service reset route is wired** to it.
- **Why defer:** the exposure is *latent*, not live — nothing can be triggered today. Re-keying
  the table to `(tenant_id, email)` is a schema change, and `AGENT_RULES` #7 says not to change
  schema casually; it is also precisely the work self-service reset will need, so doing it now
  and again when the feature lands is worse than doing it once then. A new tenant-aware token
  table is a Stage C feature, out of scope for the pilot.
- **The current flow works and is tested.** Owner/staff-initiated reset
  (`UserController::resetPassword` → `must_change_password`) and the user's own
  change-password flow are both wired, tenant-scoped and covered by tests, so nothing is
  blocked on deferring this.
- **Recorded in the doc only** (no `TODO` marker in code, per `AGENT_RULES`): the note lives in
  `SECURITY.md` under "password_reset_tokens is not yet tenant-scoped" → "Phase 11 decision —
  deferred to Stage C".
- **Trigger for revisiting:** before any "forgot password" flow is designed.

---

## 7. Rate-limit inventory

Every public form named in the brief, plus everything else reachable without (or with cheap)
privilege. All nine are rate limited.

| # | Public form / endpoint | Route | Limit | Where enforced | Test |
|---|---|---|---|---|---|
| 1 | Tenant login | `POST /login` | **5 / 60s** per `(tenant, identifier, IP)` **and** **20 / 60min** per `(tenant, identifier)` regardless of IP | `App\Support\LoginRateLimiter` called from `TenantLoginController::store` (checked before the user lookup; both limiters return the same generic 429) | `tests/Feature/Auth/RateLimitTest.php` — 7 tests incl. distributed-20-IPs and "does not reveal identifier existence" |
| 2 | Platform admin login | `POST /admin/login` (central domain) | **5 / 60s** per `(email, IP)`, cleared on success | `RateLimiter` in `PlatformAdminLoginController::store` | `tests/Feature/Auth/PlatformAdminGuardTest.php:272,293` |
| 3 | Demo request | `POST /demo-requests` (central domain) | **5 / 3600s** per IP, plus a honeypot field that silently swallows bots | `RateLimiter` in `DemoRequestController::store` | `tests/Feature/Platform/DemoRequestTest.php:79` |
| 4 | Bulk student import | `POST /users/import` | `throttle:10,60` | route middleware | `tests/Feature/Import/PermissionsAndRateLimitTest.php:112,128` (11th → 429, 10th → 200) |
| 5 | **Video upload — start** | `POST /manage/lessons/{lesson}/video/start-upload` | `throttle:10,60` | route middleware — **added in this pass** | `tests/Feature/Video/VideoUploadRateLimitTest.php` |
| 6 | **Video upload — bytes** | `POST /lesson-videos/{lessonVideo}/upload` | `throttle:10,60` (on top of `signed` + `auth:tenant` + `manageContent` + 404 in production) | route middleware — **added in this pass** | `tests/Feature/Video/VideoUploadRateLimitTest.php` |
| 7 | Lesson progress heartbeat | `POST /lessons/{lesson}/progress` | **12 / 60s** per `(tenant, lesson, user)` | `LessonProgressController::throttle()` (`config/coaching.php:42-43`) | `tests/Feature/Progress/HeartbeatEndpointTest.php:289,305` |
| 8 | Lesson completion | `PUT /lessons/{lesson}/completion` | same **12 / 60s** bucket as #7 | same | same |
| 9 | Payment screenshot upload | `POST /enrolments/{enrolment}/payment` | **10 / hour** per student, checked **first** (before validation or file decoding) so attempt 11 is a 429 | `PaymentController` | `tests/Feature/Payments/PaymentUploadTest.php:212` |

Deliberately **not** throttled: `POST /manage/payments/{payment}/approve|reject` (the owner's
own review queue — locking a reviewer out of their own work is worse than a slow approve), and
`POST /users/import/confirm` (session-authenticated and gated by a per-import token issued by
the already-limited #4).

---

## 8. Headers matrix

| Header | Set globally by the app? | Set per-response where? | Cloudflare must supply? |
|---|---|---|---|
| `Referrer-Policy: strict-origin-when-cross-origin` | **Yes** — `SetReferrerPolicy`, appended to the `web` group in `bootstrap/app.php` | — | No (do not override; the YouTube embed needs a non-empty Referer — error 153) |
| `X-Content-Type-Options: nosniff` | No | Yes, on the four byte-emitting responses: `LessonAttachmentController:51`, `LessonVideoStreamController:35`, `PaymentScreenshotController`, `PwaController` icons | **Yes** — for HTML and everything else |
| `Cache-Control: no-store, private` / `no-store` | No | Yes — payment screenshot, video stream, attachments | No |
| `X-Frame-Options: SAMEORIGIN` (or CSP `frame-ancestors`) | **No** | **No** | **Yes** — §1.6 |
| `Strict-Transport-Security` | **No** | **No** | **Yes** — §1.6 (edge terminates TLS) |
| `Content-Security-Policy` | **No** | **No** | **Optional** — §1.6; a blunt one-policy-for-all-responses approximation. App-level CSP is open item **A** |
| `Permissions-Policy` / `X-XSS-Protection` | No | No | Not required; the app uses no browser plugins or legacy APIs |

---

## 9. `app:preflight` inventory

Runs only when `APP_ENV=production` (otherwise it prints an informational line and returns
success — finding 7). Checks, in `handle()` order:

| # | Check | `docs/DEPLOY.md` claims it? | Matches? |
|---|---|---|---|
| 1 | `checkDebugDisabled` — `APP_DEBUG` must be false | §3 + §5 | ✅ |
| 2 | `checkAppKey` — non-empty | §3 + §5 | ✅ |
| 3 | `checkAppUrlIsHttps` — must start `https://` | §3 + §5 | ✅ |
| 4 | `checkDomainsConfigured` — `PLATFORM_DOMAIN`/`TENANT_BASE_DOMAIN`/`CENTRAL_DOMAINS` off the local default | §3 + §5 | ✅ |
| 5 | `checkSessionSecureCookie` — `session.secure === true` | §3 + §5 | ✅ |
| 6 | **`checkSessionDomain` — must be `null` or `''`** | **§3 claimed it; §5 did not list it** | ✅ **after this pass** (was finding 1) |
| 7 | `checkWritablePaths` — `storage/`, `bootstrap/cache/` (spreads to one entry per configured path) | §5 | ✅ |
| 8 | `checkDatabaseReachable` | §5 | ✅ |
| 9 | `checkMysqlVersion` — MySQL ≥ 8.0.16 / MariaDB ≥ 10.2.1 (no-op on SQLite) | §5 | ✅ claim; ⚠ no failing-branch test (finding 9) |
| 10 | `checkNoDemoData` — demo tenant, `demo.<base>` domain, or `admin@coaching.test` | §5 | ✅ |
| 11 | `checkNoLocalhostDemoDomain` — `demo.localhost` must not exist | §5 | ✅ |
| 12 | `checkVideoDriver` — `fake` refused; `bunny` requires the account key | §3 + §5 | ✅ claim; ⚠ tests were missing (finding 2, now added) |
| 13 | `checkGdFreetype` — GD present **and** built with FreeType | §5 | ✅ claim; ⚠ FreeType branch test was missing (now added) |

**Discrepancy found:** `docs/DEPLOY.md §3` claimed a `SESSION_DOMAIN` check that did not
exist (finding 1) — §5's list did not mention it either. §3 is now true and §5 lists it.
No other claim in §3 or §5 was wrong.

---

## 10. What was NOT checked

Explicitly out of scope for this pass:

- **Dependency / CVE scanning** — `composer audit` was not run; no SCA tool, no lockfile
  review, no transitive-dependency risk assessment.
- **Browser-level XSS / DOM testing** — no manual or automated injection testing against
  rendered pages; `{!! !!}` was audited statically, not exploited.
- **Penetration testing** — no attempt to actually break in; no fuzzing, no auth-bypass
  attempts beyond what the existing test suite already asserts.
- **Infrastructure / server configuration** — CloudPanel, Apache vhost, MySQL users,
  filesystem permissions, firewall, backup encryption.
- **Queue / job and CLI-command attack surface** — `artisan` is assumed to be run only by
  the operator.
- **The full tenancy model** — `TenantScope`, `TenantBuilder`, `BelongsToTenant`,
  `TenantUserProvider` and the `LessonAccess` rules are Phase 1-locked and were read, not
  re-audited or modified.
- **Notification / e-mail channels** — there are none in the codebase yet.
- **Accessibility, performance, and code quality** — other gates, other passes.
- **`archive-42JxzH/`** — untracked, never read for this pass, never committed.

### [UNVERIFIED] items

1. **Cloudflare dashboard paths in `docs/DEPLOY.md §1.6`** — the managed-transform /
   Transform-Rule / HSTS locations are written from knowledge, not confirmed against a live
   Cloudflare account (web search was unavailable during this pass). Confirm at deploy time
   and correct §1.6 if the labels have moved.
2. **Production behaviour of the two new throttles behind Cloudflare** — `ThrottleRequests`
   keys on `$request->ip()`, which is only the real client IP because `trustProxies()` pins
   `X-Forwarded-For` to Cloudflare's ranges. Verified by
   `tests/Feature/Production/CloudflareTrustedProxyTest.php`, but not exercised through a
   real Cloudflare edge.
3. **`checkMysqlVersion()` against a deliberately old server** — only the pure
   `DatabaseVersionCheck` function is tested (finding 9).
