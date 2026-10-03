# Phase 6: Institute Settings, Branding and Installable PWA

**Status:** Complete. `composer test` (506/507, 1 pre-existing MySQL-only test skipped on
SQLite), `composer test:mysql` (475/475), `composer test:js` (17/17), `composer lint`, and
`composer analyse` all pass with zero regressions to Phases 1–5 / P1.
> Test count at time of writing; see README for the current totals.

---

## Goal

Each institute looks like its own product (name, logo, accent colour in the header and on
the login page) and is installable as an app on a student's phone. The owner manages this
from `/manage/settings`.

Out of scope (per the brief): push notifications, offline lessons/video/PDF, custom
domains, per-institute themes beyond one accent colour, payments.

## Schema

New migration `2026_10_03_000001_add_branding_columns_to_tenants_table.php` (never edits an
already-applied migration), nullable/defaulted columns on `tenants`:

| Column              | Type                        | Notes |
|---------------------|-----------------------------|-------|
| `logo_path`         | `string(255)` nullable      | `tenants/{id}/branding/{random}.png` on the private (`local`) disk |
| `theme_color`        | `string(7)` default `#4f46e5` | Must be one of `config('coaching.theme_presets')` |
| `contact_phone`      | `string(20)` nullable       | Normalized via `User::normalizePhone()` |
| `contact_email`      | `string(255)` nullable      | |
| `academic_year_end`  | `date` nullable             | Pre-fills the enrolment "Ends" field |
| `branding_version`   | `unsigned int` default `1`  | Bumped only on logo upload/removal — cache-busts `/pwa/icons/*` URLs |

`Tenant::$fillable` is **unchanged** (`name`, `status`, `timezone`, `currency`) — none of
the new columns are mass-assignable. `SettingsController` writes them exclusively via
explicit validated fields + `forceFill()`, never `$request->all()` and never a plain
`fill()`. The tenant acted on is always `TenantContext::get()`; no route or form ever
accepts a tenant id.

`Tenant::$attributes` gained `theme_color`/`branding_version` defaults (mirrors
`User::$attributes`) — MySQL applies the column defaults at insert time, but Eloquent
doesn't automatically re-fetch DB-level defaults afterwards, so a freshly-created
in-memory `Tenant` would otherwise show `null` until the next `->refresh()`.

## Settings (`/manage/settings`, owner only — `TenantPolicy::manageSettings`)

- `GET`/`PATCH /manage/settings` (`Manage\SettingsController@show`/`update`).
- `POST /manage/settings/logo`, `DELETE /manage/settings/logo` (`storeLogo`/`destroyLogo`).
- Validation (plain-language messages in `lang/en/settings.php`): name required, max 100;
  phone normalized if given; email valid if given; `theme_color` optional on update (keeps
  the existing value if omitted) but when given must match the regex **and** be in
  `config('coaching.theme_presets')`; `academic_year_end` optional, valid date, not more
  than 3 years ahead.
- **Logo upload** (`App\Support\Branding\LogoUploadService`): `mimes:png,jpg,jpeg,webp`
  (no SVG) + 2 MB cap via Laravel validation (content-sniffed, not by filename/declared
  MIME — a GIF or PHP file renamed to `.png` is rejected here). Dimensions are checked via
  `getimagesize()` **before** decode: each side 256–4096 px, and total ≤ 16,000,000 px
  (chosen so the per-side cap alone, 4096×4096 = 16,777,216, already exceeds it). Only
  then is the file decoded with GD and **re-encoded to PNG** (strips metadata/any embedded
  payload) and stored under `tenants/{id}/branding/{random}.png` — the client's filename
  is never used. The old file is deleted only **after** the new one is committed, so a
  failure mid-upload never leaves a tenant with no logo file. `branding_version` increments
  on every successful upload/removal, never on a rejected one.
- **Icons and the header logo are rendered on the fly**, not pre-generated and stored —
  `App\Support\Branding\BrandingImageService` loads the stored logo (or generates a default
  via `DefaultIconGenerator`) and produces the requested size/variant per request. This
  means "remove logo → falls back to the default icon" and "upload a new logo → icons
  update" both fall out for free, with no extra file bookkeeping. `Cache-Control:
  public, max-age=86400` plus the `?v={branding_version}` query string on manifest icon
  URLs is what makes this safe to cache in the browser despite being generated per-request.
- **Default icon** (`App\Support\Branding\DefaultIconGenerator`): the institute's first
  letter, white, centred, drawn with GD's `imagettftext()` on the accent colour, using a
  bundled OFL-licensed font (`resources/fonts/NotoSans-Bold.ttf` — Google's Noto Sans,
  licence in `resources/fonts/OFL.txt`). `app:preflight` fails in production if GD or its
  FreeType support is missing (`config('preflight.gd_extension_loaded')` /
  `gd_freetype_supported`, read from config rather than calling `extension_loaded()`/
  `gd_info()` directly so tests can mock a missing extension without needing a PHP build
  that's actually missing it).

## Academic year end → enrolment "Ends" pre-fill

`resources/views/manage/enrolments/index.blade.php`'s `ends_at` field now defaults to
`$tenant->academic_year_end` (a plain date string; empty if unset). `EnrolmentController
@store` interprets any submitted `ends_at` as the **end of that day in Asia/Kolkata**,
converted to UTC for storage (`Carbon::parse($value, 'Asia/Kolkata')->endOfDay()->utc()`) —
an expiry date should mean "valid through the end of that day," not midnight at its start.
An empty submitted value (the teacher explicitly cleared the pre-fill) is normalized to
`null` rather than left as `''` (`Carbon::parse('')` means "now", not "no value" — a
one-line bug that would otherwise silently set every cleared enrolment to expire
immediately).

## Serving routes (tenant domains only)

All registered inside the existing `Route::middleware('require.tenant')` group in
`routes/web.php` (same 404-on-central-domain guarantee every other tenant route already
has) and reachable by a guest (a browser fetching the manifest/service worker doesn't have
a session yet). The global `CheckTenantSuspended` web middleware (already appended to the
`web` group) gives every one of these a 503 for a suspended institute for free — no extra
per-route wiring needed.

- `GET /manifest.webmanifest` — `response()->json(...)`, `Content-Type:
  application/manifest+json`. `short_name` is the first 12 characters of the name; icon
  `src` values carry `?v={branding_version}`.
- `GET /pwa/icons/{size}.png` for `180`, `192`, `512`, `512-maskable` — any other value
  404s. `Cache-Control: public, max-age=86400`, `X-Content-Type-Options: nosniff`.
- `GET /branding/logo` — the header/login logo, longer side capped at 256 px (aspect ratio
  preserved, not cropped), same caching headers.
- `GET /sw.js` — reads `resources/js/sw.js` and replaces the `__CACHE_VERSION__` placeholder
  with a value derived from `public/build/manifest.json`'s own hash (falls back to `'dev'`
  if the manifest doesn't exist) — a `npm run build` naturally invalidates the previous
  service worker's caches on its next `activate`. `Content-Type: application/javascript`,
  `Cache-Control: no-cache`, `Service-Worker-Allowed: /`.
- `GET /offline` — institute name + generic "you're offline" text only, via `__()`; no user
  data even when requested by a logged-in user.

## The service worker (`resources/js/sw.js`)

Plain, dependency-free JavaScript — no Workbox, no bundling, no `eval`. **This is the exact
file `GET /sw.js` serves** (with the version placeholder substituted) and the exact file
the Node test suite loads — there is no separate "test copy" to drift from the real one.

- `install`: precaches only `/offline`, `skipWaiting()`.
- `activate`: `clients.claim()`, deletes every cache whose name isn't the current version.
- `fetch`: only `GET`, same-origin. A request with a `Range` header is never intercepted. A
  navigation (`mode: 'navigate'`) always gets network-first with the cached `/offline`
  fallback on failure, for **any** path — that's the point of an offline page — and the
  navigation response itself is never cached. A non-navigation request to
  `/lesson-videos/…`, anything containing `/attachments/`, or `/manage`, `/users`,
  `/admin`, `/auth`, `/login`, `/logout`, `/dashboard`, `/courses` is never intercepted at
  all (no `respondWith()` call — the browser's normal network handling applies). Only
  `/build/…`, `/pwa/icons/…` and `/branding/logo` use stale-while-revalidate, and only a
  `200`/`basic` response is ever written to the cache.
- Tested with Node's built-in test runner (`node --test`, no new frameworks) against a
  sandboxed fake `self`/`caches`/`fetch` — `tests/js/sw.test.mjs`, wired up as
  `composer test:js`. `node --test tests/js` (a bare directory) silently resolves as a
  CommonJS module on Node ≥ 20/24 instead of globbing — the script is
  `node --test "tests/js/**/*.test.mjs"` specifically to avoid that trap.

## Install UI (`resources/js/pwa.js`, a Vite entry — never bundled through `sw.js`)

Registers the service worker (feature-detected, errors swallowed) and drives:

- **Android/Chrome:** `beforeinstallprompt` is captured and `#pwa-install-button` (44 px
  tap target, `hidden` by default) is revealed only while the event is available; hidden
  again after `appinstalled`.
- **iPhone Safari:** if `navigator.userAgent` looks like iOS and the page isn't already
  standalone (`display-mode: standalone` / `navigator.standalone`), `#pwa-ios-hint` is
  shown once; dismissal is remembered in `localStorage`, wrapped in `try/catch` so a
  blocked/private-mode storage failure never breaks the page.
- Both elements exist in the markup unconditionally (`layouts/app.blade.php`, tenant pages
  only) and are hidden by default — this is what the layout tests assert on directly,
  since a full browser environment isn't available in Pest.

Layout also carries, tenant pages only: `<link rel="manifest">`, `<meta
name="theme-color">` (the tenant's own colour — never raw request input, only ever a
validated preset value that made it into the database), `<link rel="apple-touch-icon"
href="/pwa/icons/180.png">`, and the Vite-bundled `pwa.js`.

## Local PWA testing

`demo.coaching.test` over plain HTTP isn't a secure context, so service worker
registration fails there. `TenantSeeder` (local/testing only, same guard as the rest of the
demo data) also registers **`demo.localhost`** as a second domain of the demo tenant —
Chrome treats `*.localhost` as secure and resolves it to `127.0.0.1` with no hosts file
entry. See README.md "PWA testing locally" for the walkthrough and the
`chrome://flags/#unsafely-treat-insecure-origin-as-secure` fallback for
`demo.coaching.test`. `app:preflight` fails in production if `demo.localhost` exists.

## Assumptions

- "16 megapixels" (the logo's total-pixel cap) is read as 16,000,000 (decimal), not
  16×1024×1024 — chosen specifically so the per-side cap (4096×4096 = 16,777,216) already
  exceeds it, giving the megapixel check something to actually catch.
- `theme_color` is `sometimes` rather than `required` on `PATCH /manage/settings` — a
  request that only intends to change the name/contact fields (as several tests do) keeps
  the tenant's existing colour rather than being rejected outright for omitting a field the
  form itself always happens to submit.
- The header/login `<img src="/branding/logo">` deliberately has **no** `?v=` cache-busting
  query string (unlike the manifest's icon URLs) — it's a plain page-load image fetch, not
  something cached long enough across a branding change to matter, and keeping it a bare
  path matches the rest of the layout's convention for internal links.
- Icons and the header logo are generated per-request rather than pre-rendered and stored
  to disk on upload; see "Icons and the header logo are rendered on the fly" above for why
  this is deliberate rather than a shortcut.

## Unresolved risks

- Per-request icon generation means every manifest icon fetch costs a GD decode + resample;
  fine at this phase's scale, but a CDN or app-level cache keyed on
  `(tenant_id, size, branding_version)` would be the next step if icon traffic ever becomes
  a real cost.
- The re-encode-to-PNG defence assumes GD's own decoders (`imagecreatefrompng/jpeg/webp`)
  are themselves free of exploitable parsing bugs; this raises the bar considerably (no
  attacker-controlled bytes survive into the stored file) but isn't an absolute guarantee
  against a GD-level vulnerability.
- `academic_year_end`'s "end of day Asia/Kolkata" conversion is hardcoded (matching the
  rest of the codebase's existing convention of hardcoding this timezone, e.g. the
  attachments view) rather than derived from a per-tenant timezone column, even though
  `tenants.timezone` already exists for other purposes (currently unused for this
  specific conversion).
