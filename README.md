# Coaching-SaaS

Multi-tenant SaaS for coaching institutes, built on Laravel 13 / PHP 8.3. Single codebase,
single database, tenant isolation enforced at the schema and application layer. See
[ARCHITECTURE.md](ARCHITECTURE.md), [TENANCY.md](TENANCY.md), [SECURITY.md](SECURITY.md),
[VIDEO.md](VIDEO.md), [PAYMENTS.md](PAYMENTS.md), [PRIVACY.md](PRIVACY.md) and
[ROADMAP.md](ROADMAP.md) for the rest of the design.

---

## Target

### What this is for

A small coaching institute — one instructor, a couple of coordinators, a few hundred students
taking subject classes (the seeded demo is "Physics – Class 11") — currently runs its
teaching business across WhatsApp groups, a Drive folder of recorded lectures, and a
spreadsheet of who paid and who watched what. This product replaces that stack with one
install the institute owner can operate themselves, with no developer in the loop.

Concretely, the owner must be able to, in one sitting on a phone or laptop: create their
institute's branding, build a course as chapters → lessons, attach PDFs and protected video
to lessons, bulk-add students from a spreadsheet, hand each student their login over
WhatsApp, enrol them, and see who is falling behind. See
[docs/PILOT_CHECKLIST.md](docs/PILOT_CHECKLIST.md) for the literal 360px walkthrough that
defines "usable".

### Who it is for

| Actor | Where they live | What they do |
|-------|-----------------|--------------|
| **Institute owner** | Tenant domain, `/manage/*` | Full control: courses, people, enrolments, settings, branding, device limits, progress |
| **Staff** | Tenant domain, `/manage/*` | Scoped permissions (courses and students; no billing) |
| **Student** | Tenant domain, `/dashboard`, `/courses/*` | Watch lessons, track progress, limited to their own enrolments — max 1–3 devices |
| **Platform operator** | Central domain, `/admin/*` | Create/suspend institutes, reset an owner password, work demo requests. Never touches tenant content |

### Product and business target

- **Stage B (the current milestone): one real tenant live.** The roadmap is explicit —
  *"the friend running the pilot institute is tenant #1"*. Everything built so far is scoped
  to what that one institute needs to run day to day, not to a feature-complete LMS.
- **The platform never holds money.** Student payments go to the tenant's own account/UPI id;
  the platform is not a payment intermediary (see [PAYMENTS.md](PAYMENTS.md)).
- **The platform never custodies content.** Each tenant gets its own Bunny Stream library, so
  isolation and per-tenant usage metering come for free.

### Explicit non-goals

- Not a public course marketplace — there is no discovery, no checkout, no platform listing.
- Not multi-framework or API-first — this is a server-rendered Blade app; the only JSON is
  the progress heartbeat and error rendering for `expectsJson()`.
- No per-tenant deployments, no database-per-tenant, no schema-per-tenant
  ([ARCHITECTURE.md](ARCHITECTURE.md)).
- Nothing ahead of [ROADMAP.md](ROADMAP.md): no gateway integration, custom domains, or
  subscriptions before Stage B is live (invariant 19 in
  [AGENT_RULES.md](AGENT_RULES.md)).

---

## Tech stack

| Layer | Choice |
|-------|--------|
| Framework | Laravel 13 (13.33), PHP 8.3 |
| Database | MySQL / MariaDB (XAMPP's MariaDB 10.4 locally), SQLite in-memory for the fast test suite |
| Auth | Hand-rolled — custom `tenant_eloquent` provider, `auth:tenant` + `auth:platform_admin` guards (no Breeze/Fortify) |
| Frontend | Blade + Tailwind CSS v4 + Vite 8, vanilla ESM modules (no React/Vue) |
| Video | Bunny Stream (`bunny`) in production, `fake` driver locally; TUS resumable upload via `tus-js-client`; `player.js` for playback |
| PWA | First-party service worker (`resources/js/sw.js`), manifest, generated icons, offline page |
| Queues / cache / session | All `database` by default (no Redis requirement for the pilot) |
| Backups | `spatie/laravel-backup`, scheduled daily |
| QA | Pest 4, Larastan (PHPStan level 5), Laravel Pint, Node's built-in test runner for JS |
| Runtime deps | Deliberately tiny: `laravel/framework`, `laravel/tinker`, `spatie/laravel-backup` |

---

## Project status — what is done

Everything below Stage B is implemented and committed (67 commits on `main`); each spec in
[docs/specs/](docs/specs/) is marked **Complete** (the one exception — Phase 2's stale
"In Progress" status line — is listed under Weaknesses). Verified by running every quality
gate:

| Gate | Command | Result (as verified) |
|------|---------|----------------------|
| Unit + feature tests (SQLite) | `composer test` | **822 tests, 821 passed, 1 skipped** (the skip is the documented MySQL-only preflight test) |
| Tenancy/auth/etc. on real MySQL | `composer test:mysql` | **790 / 790 passed** |
| JS unit tests (Node, no browser) | `composer test:js` | **29 / 29 passed** |
| Formatting | `composer lint` | **clean** (Pint reports no changes needed) |
| Static analysis | `composer analyse` | **0 errors** at Larastan level 5 |

### Stage A — Foundation and isolation ✅

| Phase | Spec | What it delivered |
|-------|------|-------------------|
| 0 | — | Repository foundation, tooling, documentation |
| 1 | [phase-1-tenant-resolution.md](docs/specs/phase-1-tenant-resolution.md) | Central tables (`tenants`, `tenant_domains`, `platform_admins`, `system_settings`) + hostname-based, **fail-closed** tenant resolution |
| 2 | [phase-2-belongs-to-tenant.md](docs/specs/phase-2-belongs-to-tenant.md) | `TenantContext`, `BelongsToTenant` trait + `TenantScope`, tenant-aware route model binding, composite-FK macros (`tenantKeys`/`tenantForeign`) |
| 3 | [phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md) | Tenant users and roles (owner/staff/student), tenant-aware user provider, temp-password + forced change, rate limits, cross-tenant login rejection |
| P1 | [phase-p1-platform.md](docs/specs/phase-p1-platform.md) | Platform home page, `/admin` (institutes, demo requests), `local:hosts` helper |

### Stage B — Pilot features ✅

| Phase | Spec | What it delivered |
|-------|------|-------------------|
| 4 | [phase-4-courses.md](docs/specs/phase-4-courses.md) | Courses → chapters → lessons, publish/draft/archive, ordering, PDF attachments, enrolment, free YouTube previews, student-facing polish |
| 5 | [phase-5-video.md](docs/specs/phase-5-video.md) | Protected video: Bunny Stream (one library per tenant), TUS upload with signed server-side signature, signed/expiring embed tokens, dynamic per-viewer watermark, `fake` local driver, `videos:sync` polling |
| 6 | [phase-6-branding-pwa.md](docs/specs/phase-6-branding-pwa.md) | Institute settings, accent-colour presets, logo upload, generated default icon, installable PWA (manifest, service worker, offline page, versioned assets) |
| 7 | [phase-7-progress.md](docs/specs/phase-7-progress.md) | Lesson progress: heartbeat + resume, auto-complete at 85% watched, student progress UI, teacher progress pages with filters/sort, per-student detail, CSV export |
| 8 | [phase-8-devices.md](docs/specs/phase-8-devices.md) | One device per student (1–3 limit), device list/sign-out UI, revocation reasons, `devices:prune`, 30-day sessions |
| 9 | [phase-9-pilot.md](docs/specs/phase-9-pilot.md) | Bulk CSV student import (preview → confirm), 15-minute one-time credentials sheet with WhatsApp links, CSV formula-injection guard, login help line, getting-started checklist, Help page, mobile People cards |
| 10 | [phase-10-payments.md](docs/specs/phase-10-payments.md) | Manual UPI payments: `payments` table with composite tenant FKs, course-fee pricing, screenshot upload (re-encoded, private disk), owner approve/reject queue, idempotent approval, per-viewer signed screenshot URLs |

Also shipped alongside: **production readiness** (Phase 4.6A — no trust of forwarded host,
Cloudflare trusted-proxy ranges, session cookie domain tests), **`php artisan app:preflight`**,
**scheduled backups**, and **[docs/DEPLOY.md](docs/DEPLOY.md)** (Hostinger VPS + CloudPanel +
Cloudflare).

### Not yet started

| Area | Status | Source |
|------|--------|--------|
| Mini security pass before deploy | Run (Phase 11) — [docs/SECURITY_PASS.md](docs/SECURITY_PASS.md) | ROADMAP Stage B |
| First real deployment / tenant #1 | Not deployed | ROADMAP Stage B |
| Automated payment gateway (per-tenant accounts, idempotent webhooks) | Not started | ROADMAP Stage C |
| Custom domains + TLS via Caddy on-demand | Not started (schema supports `DomainType::Custom`) | ROADMAP Stage C, SECURITY.md |
| Queues at scale, audit logging | Not started (queue driver is `database`) | ROADMAP Stage C |
| DPDP consent flow (guardian fields, `consents` table, export/delete tooling) | Not started; under-18 handling still flagged | [PRIVACY.md](PRIVACY.md) |
| Subscriptions, usage metering, WhatsApp notifications | Not started | ROADMAP Stage D |
| Self-service "forgot password" | Not implemented **by design** — `password_reset_tokens` is not tenant-scoped yet and must be fixed first | SECURITY.md |

> **Note on phase numbering:** [ROADMAP.md](ROADMAP.md)'s original numbering (Phase 5 =
> dashboards, Phase 6 = video, Phase 7 = PDFs) does **not** match the numbering actually used
> by the specs and the git history (Phase 5 = video, 6 = branding/PWA, 7 = progress, 8 =
> devices, 9 = pilot). The spec column above is authoritative; the roadmap needs renumbering.

---

## Strengths

1. **Tenant isolation is enforced in three independent layers**, each with tests that try to
   break it: schema (composite `UNIQUE(tenant_id, id)` + composite FKs), application
   (`TenantScope` global scope, `BelongsToTenant` model events, `tenant_id` never
   mass-assignable), and routing/middleware (`Route::domain()` for central routes,
   `RequireTenant` pinned ahead of `SubstituteBindings`). Failing closed is the default:
   no context → an exception, unknown host → 404, never "tenant #1".
2. **Documentation is a first-class artefact.** Eight root design docs
   ([ARCHITECTURE](ARCHITECTURE.md), [TENANCY](TENANCY.md), [SECURITY](SECURITY.md),
   [VIDEO](VIDEO.md), [PAYMENTS](PAYMENTS.md), [PRIVACY](PRIVACY.md),
   [ROADMAP](ROADMAP.md), [AGENT_RULES](AGENT_RULES.md)) plus 13 spec/brief files under
   `docs/specs/`. Non-obvious decisions carry their reasoning *next to the code* (see the
   middleware-priority comment in `bootstrap/app.php`). There are **zero
   `TODO`/`FIXME`/`HACK` markers** in the codebase.
3. **Test-to-code ratio is ~2:1** (15,291 test lines vs 7,569 app lines), with security
   behaviour tested explicitly — cross-tenant login rejection, session replay, token-key
   cross-tenant misuse, secrets never in HTML or logs, CSV formula injection, rate limits.
4. **All five quality gates are green** — tests on both SQLite *and* real MySQL, JS tests,
   formatting, and static analysis at level 5.
5. **Local development needs no external service.** `VIDEO_DRIVER=fake` gives real protected
   video (private disk + signed stream route) with no Bunny account and no API keys, and
   preflight refuses that driver in production so it cannot leak.
6. **Minimal dependency surface** — three runtime packages, no frontend framework. Less to
   upgrade, less supply-chain surface, faster installs.
7. **Secrets hygiene is deliberate and tested**: Bunny library keys are encrypted per tenant,
   embed/upload signatures are derived server-side, and tests assert keys appear in neither
   rendered HTML nor captured logs.
8. **Mobile/PWA is treated as a first-class target**, not an afterthought — a 360px
   walkthrough checklist exists for human testers, the People page renders cards below a
   breakpoint, and `demo.localhost` is seeded purely so service workers can be tested
   locally.

---

## Weaknesses and known gaps

1. **One flaky test.** `tests/Feature/Video/EmbedTokenPlaybackTest.php:98` asserts an *exact*
   embed token computed from `now()->addMinutes(10)` while the server computes its own
   `expires` independently — if the wall clock crosses a second boundary between the two,
   the tokens differ and the assertion fails. It fails roughly once per full-suite run and
   passes 3/3 in isolation. The sibling test at line 62 explicitly avoids this pattern for
   the same reason.
2. **No CI.** There is no `.github/workflows` (or any other pipeline). All five gates are
   local `composer` scripts, so nothing runs them on push — a regression can be committed
   unnoticed.
3. **No coverage measurement.** `phpunit.xml` declares `<source>` but no coverage run is
   configured, so "what is untested" is unknown.
4. **Documentation drift** (three known instances):
   - [ROADMAP.md](ROADMAP.md) phase numbering disagrees with the specs and git history
     (detailed above).
   - [ARCHITECTURE.md](ARCHITECTURE.md) still calls `app/Contracts/VideoProvider.php`
     "(planned)" — it exists, with `BunnyVideoProvider` and `FakeVideoProvider`.
   - `docs/specs/phase-2-belongs-to-tenant.md` still opens with **Status: In Progress**
     although Phase 2 is complete and its invariants are live in `TENANCY.md`.
   - This README previously described `composer test:mysql` as running only
     `tests/Feature/Tenancy`; it actually runs 20 suites (790 tests) — corrected below.
5. **`.env` has drifted from `.env.example`.** The local `.env` carries
   `SESSION_LIFETIME=120` while `.env.example` specifies `43200` (the Phase 8 30-day decision
   documented in SECURITY.md), and lacks `SESSION_SECURE_COOKIE`, `PLATFORM_DOMAIN` and
   `TENANT_BASE_DOMAIN`. A production deploy copied from `.env` instead of `.env.example`
   would silently get two-hour sessions.
6. **Repository hygiene.**
   - `coaching_saas` — a 151 KB SQLite database — is **committed to the repo root** and is
     not in `.gitignore`.
   - An untracked `archive-42JxzH/gk_3.1.76_windows_amd64.zip` (~9.8 MB) sits in the project
     root.
   - Both are inert, but they bloat clones and invite accidental commits of local state.
7. **Payments are manual and silent.** The Stage B UPI flow is built (Phase 10), but
   approving or rejecting a payment does not tell the student — there is no notification
   channel anywhere in the codebase yet — and there is no QR: students copy the institute's
   UPI id and upload a screenshot. Enrolment itself is still a manual, owner-initiated
   action; paying never enrols anybody.
8. **App-level security headers are thin — by decision, not by omission.** Only
   `Referrer-Policy` is set globally, plus `X-Content-Type-Options: nosniff` on the
   attachment/stream/PWA/payment-screenshot responses. CSP, `X-Frame-Options` and HSTS are
   delegated to Cloudflare (SECURITY.md "Response security headers"; configured in
   docs/DEPLOY.md §1.6) because the view layer has 17 inline event handlers, 3 inline
   `<script>` blocks and 201 inline `style="…"` attributes, which makes a real app-level
   CSP a template refactor rather than a security patch. Moving it into the app instead of
   the edge is an open decision — see docs/SECURITY_PASS.md §5.
9. **Single locale.** Localisation scaffolding exists (`lang/en/*`, `LocalizationTest`) but
   only English ships; the UI is English-only for an Indian-market product.
10. **Documented-but-live risks:** `password_reset_tokens` is not tenant-scoped (blocks
    self-service reset); the `users` contact `CHECK` constraint is a silent no-op on MySQL
    older than 8.0.16 (guarded by `DatabaseVersionCheck`).
11. **Process risk:** essentially all work has been produced by a single
    developer-plus-agent loop with no code review step — the strong docs and tests are the
    substitute for it, which is good but not the same thing.

---

## Repository layout

```
app/
  Actions/          Domain actions shared by controllers (EnrolStudentAction, CreateInstituteAction)
  Auth/             Tenant-aware user provider
  Console/Commands/ app:preflight, devices:prune, local:hosts, platform-admin:create, tenant:create, videos:sync
  Database/         TenantBuilder (tenant-safe query builder)
  Enums/            11 backed enums (roles, statuses, reasons)
  Http/             31 controllers (+ base) across Admin/, Auth/, Manage/ and top level, 8 middleware
  Models/           14 models; tenant-owned ones use BelongsToTenant
  Policies/         Course, Enrolment, Tenant, User
  Scopes/           TenantScope (throws when no context)
  Services/Video/   BunnyVideoProvider, FakeVideoProvider
  Support/          Branding, Csv, Devices, Import, Pwa, Video, TenantContext, ProgressRecorder…
  Traits/           BelongsToTenant
bootstrap/app.php   Middleware wiring, trusted proxies, exception handling
config/             coaching, tenancy, cloudflare, preflight, backup, services…
database/           24 migrations, 12 factories, 2 seeders (TenantSeeder does the demo data)
docs/               DEPLOY, DEPLOY_RUNBOOK, PILOT_CHECKLIST, SECURITY_PASS, specs/ (14 phase specs and briefs), superpowers/
lang/en/            16 translation files
public/             Front controller, PWA icons
resources/          css, fonts, js (sw, pwa, lesson-progress, progress-tracker, video-upload), Blade views
routes/             web.php (145 routes), console.php (scheduler)
tests/              Pest: Unit/ (12 files), Feature/ (113 files), js/ (Node), Fixtures/
```

---

## Design system

The UI is a hand-rolled design system layered **on top of** Tailwind's default palette rather
than replacing it — the views keep using plain `text-gray-600` / `divide-gray-100` utilities
untouched, and everything premium lives in two places:

| Layer | File | Holds |
|-------|------|-------|
| Compile time | `@theme` in [resources/css/app.css](resources/css/app.css) | Font stack, radii, shadows, motion curves |
| Runtime | `:root` in the same file | Brand/surface/ink tokens, the aurora pop colours |
| Components | `@layer components` in the same file | `.ui-card`, `.ui-btn*`, `.ui-header`, `.ui-table`, `.ui-badge*`, `.ui-alert*`, `.ui-h1`, `.ui-empty`, `.ui-list` … |

Blade side: a shared component set in `resources/views/components/` — `page-header`, `field`,
`button`, `checkbox`, `card`, `badge`, `alert`, `link`, `empty` and `icon` (18 hand-drawn
inline glyphs, no icon package).

### Colour strategy — "aurora on a brand-tinted canvas"

Two layers of colour, both derived from the tenant accent so a Settings change repaints the
whole product:

1. **Structural tint** baked into the neutral tokens (`--canvas`, `--line`,
   `--surface-muted`, `--brand-soft/line`) — the app is never flat grey.
2. **Decorative aurora** (`body::before`): four static radial glows in brand / violet /
   pink / cyan fixed behind everything, plus a 2px brand→violet→pink→amber hairline closing
   the frosted header.

Text never relies on the decorative layer: `--brand-ink` (brand mixed 70% → black) carries
every text and button fill and clears **4.5:1 against white for all eight accent presets in
`config/coaching.php`**, verified against the tinted canvas and the aurora peak alike. The
aurora is static, so it costs one composited layer and is inherently `prefers-reduced-motion`
safe.

**Invariants to preserve when editing the UI:**

- `layouts/app` writes **only `--brand`** onto `<html>` — `--brand-ink`, `--brand-deep`,
  `--brand-soft`, `--brand-line` and `--focus-ring` are all derived in `app.css`.
  The reason is testable: `CredentialsSheetTest` extracts the temporary password via
  `strip_tags($response)`, and `strip_tags()` keeps `<style>` contents — a `[a-z0-9]{4}-[a-z0-9]{4}`
  pattern inside any inline `<style>` would match before the real password.
- `-webkit-backdrop-filter` must **precede** `backdrop-filter`. The bundler collapses
  identical prefixed/unprefixed pairs and keeps the final one, so the reverse order silently
  drops the standard property and kills the frosted header.
- Headings use `background-clip: text` **behind an `@supports` guard**, with solid
  `color: var(--ink)` outside it, plus a `@media print` reset — the title can never render
  invisible. Centred headings opt into `.is-centered` so the gradient underline follows the
  text instead of the block edge.
- Footer and other canvas-level text uses `--ink-muted`, never `--ink-subtle` (4.44:1 on the
  old neutral canvas — caught by Lighthouse).
- Mobile table/card splits must take `display` from **classes** (`sm:hidden grid gap-3`),
  never an inline `style="display:grid"` — inline outranks `sm:hidden` and renders both
  variants at once.

Verified in-browser: Lighthouse on `/dashboard` scores **100 accessibility / 100 best
practices / 100 SEO** with zero failed audits, no horizontal overflow at any breakpoint, and
all five quality gates green.

---

## Local setup (Windows / XAMPP)

Prerequisites: XAMPP with PHP 8.3, MySQL/MariaDB, and Apache; Composer; Node.js 18+.

1. Clone the repo into `C:\xampp\htdocs\coaching-saas`.
2. `composer install`
3. `copy .env.example .env` then `php artisan key:generate`
4. Create two MySQL databases: `coaching_saas` (app) and `coaching_saas_test` (MySQL test
   suite). Both use `root` with no password by default in XAMPP — adjust `.env` if yours
   differs.
5. `php artisan migrate`
6. `npm install && npm run build` (or `npm run dev` while working on frontend)

Protected lesson video works locally with **no external service and no `.env` keys** —
`VIDEO_DRIVER` defaults to `fake` (private-disk storage + a signed Laravel stream route) and
is the only supported driver in local/testing; `VIDEO_DRIVER=fake` is refused by
`php artisan app:preflight` in production, so never set it there. See
[docs/specs/phase-5-video.md](docs/specs/phase-5-video.md) and
[docs/DEPLOY.md](docs/DEPLOY.md) for the `bunny` driver's production setup.

**Testing an actual video upload locally**, rather than just the app's own test suite,
needs XAMPP's PHP to allow a file that size through *before* Laravel ever sees it. Edit
`C:\xampp\php\php.ini` (the CLI/Apache one XAMPP's control panel points at) and raise both
`upload_max_filesize` and `post_max_size` (the latter must be **larger** than the former —
PHP counts the whole multipart body, not just the file) to comfortably above
`COACHING_MAX_VIDEO_MB` (default 2048), e.g. `upload_max_filesize = 2100M` and
`post_max_size = 2200M`, then restart Apache from the XAMPP control panel. Left at PHP's
tiny defaults (2M/8M), PHP silently discards the upload before `$_FILES` is even
populated — the app detects this specific case (`Content-Length` above the configured
limit but no file present) and reports it as "file too large" rather than a generic
error, but the upload still won't go through until `php.ini` is raised.

### Hosts file (subdomain tenants)

Tenants are resolved by subdomain (see [TENANCY.md](TENANCY.md)), so add entries to
`C:\Windows\System32\drivers\etc\hosts` (edit as Administrator) for every tenant you test
locally, plus the central/platform host:

```
127.0.0.1 coaching.test
127.0.0.1 platform.coaching.test
127.0.0.1 friend.coaching.test
```

Add one line per tenant subdomain you need. Wildcard entries are not supported by the
Windows hosts file, so each subdomain needs its own line.

### Apache vhost

Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apacheconf
<VirtualHost *:80>
    ServerName coaching.test
    ServerAlias *.coaching.test
    DocumentRoot "C:/xampp/htdocs/coaching-saas/public"

    <Directory "C:/xampp/htdocs/coaching-saas/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Restart Apache after editing. Visit `http://coaching.test` or any tenant subdomain added to
the hosts file.

### Local credentials

`php artisan migrate:fresh --seed` creates a demo tenant with ready-to-use owner and student
accounts:

| Field           | Owner                           | Student                           |
|-----------------|----------------------------------|------------------------------------|
| Tenant domain   | `demo.coaching.test` (add it to your hosts file, see above) | same |
| Email           | `owner@demo.coaching.test`      | `student@demo.coaching.test`      |
| Password        | `password`                      | `password`                        |

Log in at `http://demo.coaching.test/login`. Neither seeded account requires a password
change on first login (unlike a user created through the app's "add person" flow, which
always generates a temporary password and forces a change — see
[docs/specs/phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md)).

**These demo credentials are local development only.** `TenantSeeder` only creates the demo
owner/student when `APP_ENV` is `local` or `testing`; in any other environment (including
`production`) it seeds the tenant/domain but skips the users entirely and prints a console
warning instead. Never add real password seeding to a production deploy.

The same seeder also creates demo course content (only in local/testing, guarded the same
way): a **published** course "Physics – Class 11" with 2 chapters and 4 lessons — one
free-preview lesson with a YouTube video, one draft lesson, and two paid lessons each with a
sample PDF note — plus the demo student enrolled in it with no end date. The free-preview
lesson's `youtube_video_id` is seeded as the neutral placeholder `xxxxxxxxxxx`, which does
**not** resolve to a real YouTube video — swap in a real video id (via `/manage/courses` in
the app, or by editing the seeded row) before demoing the free-preview player to anyone. A
second course,
"Chemistry – Class 11", is seeded as a **draft** (visible only to the owner/staff, useful for
testing the "draft course is invisible" behavior). See
[docs/specs/phase-4-courses.md](docs/specs/phase-4-courses.md).

### Lesson progress tracking

Students see how far they've got (a tick + percentage on the course page, a progress bar
and "Continue" link on the dashboard) and can resume a video where they left off. Video
lessons complete automatically at 85% watched; everything else (notes-only lessons,
YouTube previews) is completed with a "Mark as complete" tap. Owners/staff see it from
`/manage/courses/{course}/progress` (filters for inactive/not-started, sort by progress or
last active) and a per-student detail page. See
[docs/specs/phase-7-progress.md](docs/specs/phase-7-progress.md).

### One device per student

A student account can only be signed in on a limited number of devices at once (owner
sets 1–3 on `/manage/settings`, default 1) — logging in on a new device signs the oldest
one out, so a paid login can't be shared with a whole class. Owner/staff see each
student's device count on the People page and manage them from `/users/{user}/devices`
("Sign out this device" / "Sign out all devices"). A signed-out device sees a plain
"opened on another device" message on its next request. Password reset/change and
disabling a student also revoke devices — see [SECURITY.md](SECURITY.md). Stale device
rows (60+ days unseen) are cleaned up by the scheduled `php artisan devices:prune`
command. See [docs/specs/phase-8-devices.md](docs/specs/phase-8-devices.md).

### Bulk student import, WhatsApp credential sharing, progress export

Owners/staff can add many students at once from `/users/import`: download a CSV template,
upload it, review a row-by-row preview (nothing is created yet), then confirm. Each
student's login and temporary password appears on a one-time credentials sheet
(`/users/import/sheet/{token}`, 15-minute window, creator only) with a ready-to-send
WhatsApp link per student — nothing is emailed or texted automatically. A course's progress
can be exported as CSV from its progress page. See
[docs/specs/phase-9-pilot.md](docs/specs/phase-9-pilot.md) and [SECURITY.md](SECURITY.md).

### PWA testing locally

Service workers only register in a "secure context" — HTTPS, or `localhost`/`127.0.0.1`.
`http://demo.coaching.test:8000` is **not** a secure context, so `navigator.serviceWorker
.register()` silently fails there. The seeder also registers a second domain,
**`demo.localhost`**, for exactly this reason — Chrome treats any `*.localhost` hostname as
secure and resolves it to `127.0.0.1` automatically, with no hosts file entry needed:

```
php artisan serve
```

then open **`http://demo.localhost:8000`** and log in as the demo owner/student above. This is
where to test the manifest, install prompt, and offline page (`docs/specs/phase-6-branding-pwa.md`).

If you'd rather use `demo.coaching.test`, Chrome can be told to treat it as secure anyway:
visit `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add
`http://demo.coaching.test:8000`, and relaunch the browser. This is a local-only workaround —
never rely on it for anything other than your own dev machine.

**Installing on a real phone needs HTTPS.** Neither `demo.localhost` nor the Chrome flag
above exist on a phone; the PWA is only actually installable from a deployed, HTTPS-served
tenant domain (see [DEPLOY.md](docs/DEPLOY.md)). `php artisan app:preflight` fails in
production if the `demo.localhost` domain exists — it must never be created outside
local/testing.

`php artisan migrate:fresh --seed` also creates a **local-only** platform admin account
(same local/testing-only guard as the demo tenant above — never seeded in production, and
`php artisan app:preflight` fails in production if this account exists):

| Field    | Value                       |
|----------|------------------------------|
| Email    | `admin@coaching.test`       |
| Password | `password`                  |

Log in at `http://coaching.test/admin/login`. To create additional/production platform
admins, use `php artisan platform-admin:create` (interactive, prompts for name/email/
password) — never seed one outside local/testing.

Once you've created a tenant or two, `php artisan local:hosts` (local/testing only) prints
a `127.0.0.1 <domain>` line for every tenant domain plus the central domains, ready to paste
into `C:\Windows\System32\drivers\etc\hosts` instead of typing them by hand.

## Commands

| Command                 | Purpose                                                          |
|--------------------------|-------------------------------------------------------------------|
| `composer setup`         | One-shot install: `composer install`, copy `.env`, `key:generate`, `migrate`, `npm install`, `npm run build`. |
| `composer dev`           | Run the Laravel dev stack (`php artisan dev`).                    |
| `composer test`          | Run the full Pest suite (822 tests) against an in-memory SQLite database. |
| `composer test:mysql`    | Run the MySQL variant of the suite — 20 suites / 790 tests: every `tests/Feature/` directory plus the schema- and security-relevant `tests/Unit/` directories — against the real `coaching_saas_test` database (set `DB_TEST_*` in `.env`). Excludes only `tests/Feature/ExampleTest.php`, `tests/Feature/ErrorPagesTest.php` and four `tests/Unit/` files that need no database. |
| `composer test:js`       | Run the Node test-runner suite for `resources/js/{sw,progress-tracker}.js` (no browser needed). |
| `composer lint`          | Format code with Laravel Pint (`vendor/bin/pint --test` to check without writing). |
| `composer analyse`       | Static analysis with Larastan (PHPStan) at level 5.                |

Other useful Artisan commands: `php artisan app:preflight` (production readiness — run it
before every deploy), `php artisan migrate:fresh --seed` (reset local data), `php artisan
local:hosts` (print hosts-file lines), `php artisan platform-admin:create` (create a
platform admin outside local/testing).

## Testing philosophy

Most tests run against in-memory SQLite for speed. Anything that depends on MySQL-specific
behavior — composite foreign keys, collation, or the tenancy isolation guarantees in
[TENANCY.md](TENANCY.md) — belongs in `tests/Feature/Tenancy` and must also pass under
`composer test:mysql`, since SQLite does not enforce composite foreign keys the same way
MySQL does.

Many feature tests assert *exact markup* (attribute order, class strings, substring
adjacency), which is deliberate — it is what catches accidental UI regressions. During the
design-system refresh exactly **one** assertion was relaxed rather than deleted:
`tests/Feature/Courses/StudentFacingPolishTest.php` now matches the lesson `<h1>` with
`/<h1[^>]*>Board Tag Lesson<\/h1>/` instead of pinning the old utility class list, because
the heading is now styled by the `.ui-h1` token class. Everything else in that file (and in
`CredentialsSheetTest`, `EnrolmentUiTest`, `PeoplePageMobileTest`, `MobileSafetyChecker`)
still asserts its original, unmodified contract.
