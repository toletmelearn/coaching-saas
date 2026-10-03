# PROJECT_BRIEF.md

> Audit date **2026-10-02** · branch `main` @ `2bc3f01` · 62 commits · 459 tracked files.
> Corrections recorded inline as of `c61a8bc` (2026-10-03) — the audit date and the
> `@ 2bc3f01` snapshot above stay as they were (they were accurate for their commit);
> resolved items below are marked, not deleted.
> An earlier correction pass is recorded as of `9173eb9` (2026-10-02); items closed by
> that pass keep their own commit citation.
> Truth order used throughout: **code + tests → spec files → README → ROADMAP (least authoritative)**.
> Every claim cites a path. Anything I could not confirm is marked `[UNVERIFIED]`.
> No `.env` value is reproduced anywhere in this document — only key names and set/unset status.

---

## §1 Identity

Coaching-SaaS is a multi-tenant Blade/Laravel 13 platform for small Indian coaching
institutes. One owner builds courses (chapters → lessons with PDFs and protected video),
bulk-imports students from a CSV, hands out logins over WhatsApp, enrols them, and tracks
progress — all on the institute's own subdomain. Single codebase, single database, tenant
isolation enforced at schema, model and routing layer. **Live:** Stage A (isolation) and
Stage B (pilot features — including the manual UPI payment flow, Phase 10) are complete
with all five quality gates green, and those gates also run in CI on every push
(`.github/workflows/ci.yml`, since `9ff5f11`) plus a nightly `backup:run` canary
(`.github/workflows/backup-canary.yml`, since `b6d2e61`). **Not live:** nothing is
deployed and no second tenant exists.

---

## §2 True state table

| Area | Status | Evidence (file / test) | Doc claims |
|---|---|---|---|
| **Phase 0** — foundation, tooling, docs | Done | git `0bc6424 Phase 0: foundation`; no spec file | `ROADMAP.md:11` |
| **Phase 1** — central tables + hostname resolution | Done | `app/Http/Middleware/ResolveTenant.php`; `tests/Feature/Tenancy/ResolveTenantTest.php` (7 tests incl. suffix spoof) | `docs/specs/phase-1-tenant-resolution.md:3` "✅ Complete" |
| **Phase 2** — `BelongsToTenant` isolation framework | Done | `app/Traits/BelongsToTenant.php`, `app/Scopes/TenantScope.php`, `app/Database/TenantBuilder.php`, `app/Macros/BlueprintTenancyMacro.php`; `tests/Feature/Tenancy/{BelongsToTenantTest,TenantScopeTest,TenantBuilderTest}.php` | **Contradicts docs** — `docs/specs/phase-2-belongs-to-tenant.md:3` still reads `**Status:** In Progress` although git `076cd78` + 5 follow-up fix commits shipped it |
| **Phase 3** — tenant users and auth | Done | `app/Auth/TenantUserProvider.php`, `config/auth.php` (guards `tenant`/`platform_admin`); `tests/Feature/Auth/*` (7 files, 62 tests) | `docs/specs/phase-3-tenant-auth.md:3` "Complete (includes Phase 3.1)" |
| **Phase P1** — platform home + `/admin` | Done | `routes/web.php:39-77`; `tests/Feature/Platform/*` (6 files) | `docs/specs/phase-p1-platform.md:3` "Complete (including P1.1)" |
| **Phase 4** — courses, lessons, enrolment, attachments | Done | `app/Http/Controllers/Manage/*`, `app/Support/LessonAccess.php`; `tests/Feature/Courses/*` (12 files, 69 tests) | `docs/specs/phase-4-courses.md:3` "Complete" |
| **Phase 5** — protected video | Done | `app/Contracts/VideoProvider.php`, `app/Services/Video/{Bunny,Fake}VideoProvider.php`; `tests/Feature/Video/*` (12 files, 58 tests) | `docs/specs/phase-5-video.md:3` "Complete (including 5.1)" |
| **Phase 6** — settings, branding, PWA | Done | `app/Http/Controllers/PwaController.php`, `config/coaching.php`; `tests/Feature/Pwa/*` (6) + `tests/Feature/Settings/*` (5) | `docs/specs/phase-6-branding-pwa.md:3` "Complete" |
| **Phase 7** — lesson progress | Done | `app/Support/ProgressRecorder.php`, `app/Http/Controllers/LessonProgressController.php`; `tests/Feature/Progress/*` (9 files, 78 tests) | `docs/specs/phase-7-progress.md:3` "Complete" |
| **Phase 8** — one device per student | Done | `app/Http/Middleware/EnforceDeviceLimit.php`, `routes/console.php:21`; `tests/Feature/Devices/*` (9 files, 53 tests) | `docs/specs/phase-8-devices.md:3` "Complete" |
| **Phase 9** — pilot readiness (import, credentials, help) | Done | `app/Http/Controllers/UserImportController.php`, `app/Support/Csv/CsvFormulaGuard.php`; `tests/Feature/Import/*` (4 files, 51 tests) | `docs/specs/phase-9-pilot.md:3` "Complete" |
| **Manual UPI payments** (screenshot → owner approves) | **Done** (Phase 10) | `app/Http/Controllers/PaymentController.php` (student submit), `app/Http/Controllers/Manage/PaymentController.php` (owner review); `tests/Feature/Payments/*` (7 files) green in CI gate 5; git `f8d5b6a Phase 10: manual UPI payment flow`, `efcf4aa Phase 10: tests (Step 1)`, tag `phase-10`, spec `docs/specs/phase-10-payments.md` | `PAYMENTS.md` "Manual UPI flow (pilot, **ships first**)" is now true; `ROADMAP.md:26` still calls it "Phase 9 (manual UPI only)" — the numbering collision recorded as C3 in §3 |
| **Security pass** (discrete pre-deploy pass) | **Done** (Phase 11) | `docs/SECURITY_PASS.md`; git `e605cc5 Phase 11: mini security pass` (tag `phase-11`); `tests/Feature/Console/AppPreflightCommandTest.php` gained a `checkSessionDomain` failing test + the `checkVideoDriver`/`checkGdFreetype` failing branches | `README.md:123` now reads "Run (Phase 11) — docs/SECURITY_PASS.md" (corrected in `c21ca36`) |
| **Deploy runbook** | **Done** | `docs/DEPLOY_RUNBOOK.md` — ordered first-deploy procedure: gates → server prep → `.env` diff → Node/PHP → backups → domains → Cloudflare → Bunny → cron → tenant #1 → verification → rollback, plus a §13 requirements sheet and §14 open questions | (new; `README.md:124` "Not deployed" is still correct — the runbook is the *procedure*, not a deployment) |
| **First real deployment** | Not started | no deploy commit; `docs/DEPLOY.md` is a plan | `README.md:124` "Not deployed" |
| **Custom domains** | Partial | `app/Enums/DomainType.php:8` enum case only; **no code path creates one** (`app/Actions/CreateInstituteAction.php` only mints subdomains); `tests/Feature/Tenancy/TenantDomainTest.php:25` round-trips the enum | `ROADMAP.md:40` Stage C; `TENANCY.md:5` describes them as if usable |
| **DPDP consent flow** (guardian, `consents`, export/delete) | **Not started** | no `consents`/`guardian` migration; `grep -i consent database/migrations` → empty | `PRIVACY.md` marks "Planned schema support (not yet implemented)"; `AGENT_RULES.md` invariant 17 anticipates it |
| **Automated payment gateway** | **Not started** | `app/Contracts/PaymentProvider.php` absent (`ls app/Contracts/` → `VideoProvider.php` only) | `ARCHITECTURE.md` lists it "(planned)"; `ROADMAP.md:38` Stage C |
| **Subscriptions / usage metering** | Not started | no billing tables or code | `ROADMAP.md` Stage D |
| **WhatsApp notifications** | Partial | outbound `wa.me` deep links exist (`resources/views/users/import/sheet.blade.php:41`, `resources/views/auth/login.blade.php:42`) and are tested; **no outbound notification/send mechanism** | `ROADMAP.md` Stage D says "WhatsApp notifications" not started — the *sharing* half is shipped, the *notification* half is not |
| **Forgot-password (self-service reset)** | **Not started by design** | `password_reset_tokens` exists with PK `email` and no `tenant_id` (`database/migrations/0001_01_01_000000_create_users_table.php:24`); no broker/route wired | `SECURITY.md:45` explains it is deliberately blocked until the table is tenant-scoped |

**Docs claiming Done where I found neither code nor test:** none. The only contradiction is
the inverse — phase 2's spec is stale-*behind* reality.

---

## §3 Authoritative phase numbering

**Winner: `docs/specs/*` + `git log` (they agree exactly). `ROADMAP.md` loses.**

Decisive evidence: every git commit subject uses the spec numbering — `5723b89 Phase 5:
protected lesson video`, `8faa256 Phase 6: institute settings, branding and PWA`,
`ccef063 Phase 7: lesson progress tracking`, `ad5881e Phase 8: one device per student`,
`83c973e Phase 9: pilot readiness`, `f89a615 Phase P1: platform home and admin`,
`f8d5b6a Phase 10: manual UPI payment flow`.
`ROADMAP.md` has never been renumbered since `13b4847 Phase 1 cleanup: roadmap order`.

| Canonical # | Name | Stage | Status | Spec file | Roadmap calls it |
|---|---|---|---|---|---|
| 0 | Foundation, tooling, docs | A | Done | *(none)* | Phase 0 — **same** |
| 1 | Central tables + hostname resolution | A | Done | `docs/specs/phase-1-tenant-resolution.md` | Phase 1 — **same** |
| 2 | `BelongsToTenant` isolation framework | A | Done | `docs/specs/phase-2-belongs-to-tenant.md` | Phase 2 — **same** (spec status line stale) |
| 3 | Tenant users and authentication | A | Done | `docs/specs/phase-3-tenant-auth.md` (+ `docs/specs/phase-3-brief.md`) | Phase 3 — **same** |
| **P1** | Platform home + `/admin` | A | Done | `docs/specs/phase-p1-platform.md` | **absent — ROADMAP has no P1** |
| 4 | Courses, lessons, enrolment, attachments | B | Done | `docs/specs/phase-4-courses.md` | Phase 4 — same, **but ROADMAP also splits out "Phase 7: PDFs"**, which actually shipped inside Phase 4 |
| **5** | Protected lesson video | B | Done | `docs/specs/phase-5-video.md` | **ROADMAP Phase 6** |
| **6** | Settings, branding, PWA | B | Done | `docs/specs/phase-6-branding-pwa.md` | **ROADMAP Phase 10 ("admin tooling")** |
| **7** | Lesson progress tracking | B | Done | `docs/specs/phase-7-progress.md` | **ROADMAP Phase 5 ("student dashboards / progress")** |
| **8** | One device per student | B | Done | `docs/specs/phase-8-devices.md` (+ `docs/specs/phase-8-brief.md`) | **ROADMAP Phase 8 = "automated payment gateway" (Stage C) — hard collision, different feature** |
| **9** | Pilot readiness (bulk import, credentials, help) | B | Done | `docs/specs/phase-9-pilot.md` (+ `docs/specs/phase-9-brief.md`) | **ROADMAP Phase 9 = "manual UPI only" — hard collision, different feature** |
| 10 | Manual UPI payments (screenshot → owner approves) | B | Done | `docs/specs/phase-10-payments.md` | **ROADMAP Phase 10 = "owner/staff admin tooling" — different feature, same number** |
| 11 | Mini security pass (discrete pre-deploy pass) | B | Done | *(none — `docs/SECURITY_PASS.md` instead)* | **absent — ROADMAP has no Phase 11** |
| 12 | Custom domains + TLS (Caddy) | C | Not started | *(none)* | Phase 12 — same, **plus a stray "and PWA support" clause** (PWA already shipped in Phase 6) |
| 13 | Queues at scale, audit logging | C | Not started | *(none)* | Phase 13 — same |
| 14 | Deferred-from-B catch-all | C | Not started | *(none)* | Phase 14 (full) — undefined content |
| — | Automated payment gateway | C | Not started | *(none)* | **ROADMAP Phase 8** (number already taken by devices) |

**Every numbering conflict, exhaustively:**

| # | Conflict | Where |
|---|---|---|
| C1 | 5↔6↔7 swap: ROADMAP 5=progress/6=video vs spec 5=video/6=branding/7=progress | `ROADMAP.md:22-25` vs `docs/specs/phase-{5,6,7}-*.md` |
| C2 | **Phase 8 collision** — ROADMAP=payment gateway, spec=devices | `ROADMAP.md:38` vs `docs/specs/phase-8-devices.md` |
| C3 | **Phase 9 collision** — ROADMAP=manual UPI, spec=pilot readiness | `ROADMAP.md:26` vs `docs/specs/phase-9-pilot.md` |
| C4 | ROADMAP Phase 10 = "owner/staff admin tooling" has no spec and no commit, while a *different* Phase 10 (manual UPI payments) has both | `ROADMAP.md:28` vs `docs/specs/phase-10-payments.md` + git `f8d5b6a` |
| C5 | Phase 11 appears in no roadmap document at all; it exists as git `e605cc5` + tag `phase-11` + `docs/SECURITY_PASS.md`, with no spec file | `ROADMAP.md` |
| C6 | Phase P1 exists as a spec + 2 commits but has no ROADMAP number | `docs/specs/phase-p1-platform.md`, git `f89a615`/`ae574ee` |
| C7 | ROADMAP "Phase 7: PDFs / course materials" shipped inside spec Phase 4 | `ROADMAP.md:25` vs `docs/specs/phase-4-courses.md` §Attachments |
| C8 | ROADMAP Phase 12 claims "and PWA support"; PWA shipped in Phase 6 | `ROADMAP.md:40` |
| C9 | Stage B membership: ROADMAP `{4,5,6,7,9-UP,10}` vs actual `{4,5,6,7,8,9,P1,10}` | `ROADMAP.md:19` vs `README.md:104-111` |
| C10 | `README.md:132-135` already flags C1 and says "the roadmap needs renumbering" — **the fix was never applied** | `README.md` vs `ROADMAP.md` |

**Reconciled stages:** A = {0,1,2,3,P1} · B = {4,5,6,7,8,9,10} + production readiness +
deploy (Phase 11's security pass is a discrete doc pass, not a roadmap stage) ·
C = {gateway, 12, 13, 14, DPDP consent} · D = {subscriptions, metering, WhatsApp notifications}.

---

## §4 Architecture — 10 structural facts

1. **One codebase, one database;** no per-tenant deploy/schema. Central tables: `tenants`, `tenant_domains`, `platform_admins`, `system_settings` — `ARCHITECTURE.md`.
2. **Three isolation layers:** composite FKs at schema, `TenantScope` global scope at model, `Route::domain()` + `RequireTenant` at routing — `TENANCY.md`, `app/Scopes/TenantScope.php`.
3. **Middleware order is deliberate:** `ResolveTenant` *prepended* to `web` (before session), `CheckTenantSuspended` *appended* (needs session) — `bootstrap/app.php:26-34`.
4. **`RequireTenant` is pinned before `SubstituteBindings`** in the priority list, so no tenant-scoped binding runs before a tenant is confirmed — `bootstrap/app.php:56-60`.
5. **`TenantContext` is write-once:** `set()` throws if already set; `reset()` only from `ResolveTenant`'s `try/finally` — `app/Support/TenantContext.php:16,37`.
6. **Two guards, one custom provider:** `auth:tenant` uses driver `tenant_eloquent`, `auth:platform_admin` uses `eloquent` — `config/auth.php:38-48`.
7. **Composite FK convention:** parent `UNIQUE(tenant_id,id)`, child `FK(tenant_id,parent_id) → parent(tenant_id,id)`, via `tenantKeys()`/`tenantForeign()` macros — `app/Macros/BlueprintTenancyMacro.php`.
8. **Video abstracted behind `App\Contracts\VideoProvider`** with `BunnyVideoProvider` / `FakeVideoProvider`, selected by `config('coaching.video_driver')` — `app/Contracts/VideoProvider.php`.
9. **Session cookie is host-only** (`SESSION_DOMAIN=null`), 43200-min default lifetime; proxies trusted only from `config/cloudflare.php` IP ranges, and `X-Forwarded-Host` is never trusted — `config/session.php:35,159`, `bootstrap/app.php:63-77`.
10. **DB/queue/cache/session all default to `database`**; design-system entry point is `@theme` + `:root` + `@layer components` in `resources/css/app.css` — `config/database.php:16`, `config/queue.php:16`.

---

## §5 Invariants and gotchas

Format: **Rule** — *Why* — **Enforced where** — **Test that catches it**.

### A. `AGENT_RULES.md` project invariants (the 20)

1. **Fail closed on tenant resolution** — an unknown host must never fall through to tenant #1 — `app/Http/Middleware/ResolveTenant.php:25` (`abort(404)`) — `tests/Feature/Tenancy/ResolveTenantTest.php:32`.
2. **Never trust `tenant_id` from the client** — a forged id silently re-homes data — `app/Traits/BelongsToTenant.php` `creating`/`saving`/`updating`/`deleting` hooks — `tests/Feature/Settings/MassAssignmentTest.php`.
3. **Every tenant-owned model uses `BelongsToTenant`; no undocumented `withoutGlobalScope`** — bypassing the scope reads another tenant's rows — `app/Traits/BelongsToTenant.php`, `app/Scopes/TenantScope.php` — `tests/Feature/Tenancy/BelongsToTenantTest.php` (306 lines).
4. **Composite FKs are mandatory for tenant-owned parent/child pairs** — SQLite does not enforce them, so only MySQL catches regressions — `app/Macros/BlueprintTenancyMacro.php` — `tests/Feature/Courses/CourseSchemaTest.php`; runs under `composer test:mysql` only.
5. **`TenantContext` set once per request, never mutated** — mutation would let code act as another tenant silently — `app/Support/TenantContext.php:16` (`set()` throws) — `tests/Feature/Tenancy/TenantContextTest.php`.
6. **Jobs carry an explicit `tenant_id`** — no ambient context inside a queued job — **no enforcement possible: `find app -type d -name Jobs` is empty and there are zero `dispatch()`/`ShouldQueue` references in `app/` or `routes/`** — none (invariant currently vacuous).
7. **`SESSION_DOMAIN` stays `null`** — a shared cookie domain hands one tenant's session to every subdomain — `config/session.php:159`, `.env.example:45-48` comment — `tests/Feature/Production/SessionCookieDomainTest.php`.
8. **Session replay across tenants is a critical bug** — a stolen cookie must not resolve a user on another host — `app/Auth/TenantUserProvider.php` — `tests/Feature/Auth/SessionIsolationTest.php:57,91,120,220`.
9. **Price, ownership, permissions re-derived server-side** — the browser is untrusted — `app/Policies/*`, `app/Support/LessonAccess.php`, and `app/Support/Payments/AmountResolver.php` for the price half — `tests/Feature/Users/UserPolicyTest.php` (508 lines), `tests/Feature/Courses/ManagementPermissionsTest.php`, `tests/Feature/Payments/AmountDerivationTest.php` (a posted `amount_paise`/`price`/`fee_paise` changes nothing; the stored amount comes from the course record). *The price half had no code path at all until `f8d5b6a` — see §6 item 6.*
10. **Videos/PDFs/screenshots on private storage, served only by signed expiring URLs** — public paths leak paid content — `->middleware('signed')` in `routes/web.php:117,176` — `tests/Feature/Video/PlaybackAccessTest.php`, `tests/Feature/Video/FakeStreamRouteTest.php:112`, `tests/Feature/Courses/AttachmentsTest.php:50`.
11. **Webhooks are notifications to re-check, not truth** — prevents replayed/forged state changes — **no webhook route exists**; the polling substitute is `videos:sync` per `routes/console.php:16-17` — `tests/Feature/Video/VideosSyncCommandTest.php`.
12. **Webhook/approval handlers must be idempotent** — double delivery must not double-charge/enrol — **no *webhook* handler exists yet** (invariant 11), but the Phase 10 approval handler does and is idempotent — `app/Http/Controllers/Manage/PaymentController.php:65` (`approve` on an already-`Approved` row is a no-op that does not move `reviewed_at` or re-point the enrolment; `:72` and `:99` refuse a non-`Pending` row with 422) — `tests/Feature/Payments/PaymentReviewTest.php:263` "approval is idempotent: a second approve is a no-op and enrolls nobody".
13. **One Bunny Stream library per tenant** — shared libraries break isolation *and* usage metering — `app/Services/Video/BunnyVideoProvider.php` `ensureLibraryProvisioned()` — `tests/Feature/Video/LibraryProvisioningTest.php`.
14. **YouTube free-preview lessons bypass signing entirely** — they are intentionally public; adding auth breaks them — `app/Support/LessonAccess.php`, `routes/web.php:105-118` — `tests/Feature/Courses/YoutubeTest.php`.
15. **Platform never custodies student funds** — keeps the platform out of PCI obligations — **no payment code existed** at the audit; Phase 10's manual UPI flow now has payment code, but it pays the *tenant's own* UPI id re-read server-side, so the platform still receives nothing — `app/Support/Payments/AmountResolver.php`, `app/Http/Controllers/PaymentController.php` — `tests/Feature/Payments/*`. Invariant holds, no longer vacuous.
16. **Custom-domain TLS must check `tenant_domains` verification** — an open approve-any-hostname endpoint is a takeover vector — **no Caddy/ACME/ask endpoint exists** (`grep -ri caddy app/ routes/ config/` → empty) — none (vacuous today).
17. **Treat under-18 users as DPDP "children"** — guardian consent required before processing — `PRIVACY.md`; no `consents`/`guardian` migration exists — none.
18. **Never state unresolved legal questions as fact** — `PRIVACY.md` keeps "needs legal confirmation" flags live — doc-only — none.
19. **Don't implement ahead of the roadmap** — process rule, `ROADMAP.md` first — process-only — none.
20. **When an invariant and a feature request conflict, the invariant wins** — process rule — `AGENT_RULES.md:76-79` — none.

### B. Design-system invariants (README "Invariants to preserve")

21. **`layouts/app` writes ONLY `--brand` into the inline `<style>` on `<html>`** — `strip_tags()` keeps `<style>` contents, so any `[a-z0-9]{4}-[a-z0-9]{4}` there matches before the temporary password — `resources/views/layouts/app.blade.php` — `tests/Feature/Import/CredentialsSheetTest.php`.
22. **`-webkit-backdrop-filter` must precede `backdrop-filter`** — the bundler collapses the prefixed/unprefixed pair and keeps the last, silently killing the frosted header — `resources/css/app.css` — **none; `[UNVERIFIED]` visual only.**
23. **`background-clip: text` must sit behind `@supports`, with solid `color: var(--ink)` outside and a `@media print` reset** — otherwise the `<h1>` can render invisible — `resources/css/app.css` — **none; `[UNVERIFIED]` visual only.**
24. **Canvas/footer text uses `--ink-muted`, never `--ink-subtle`** — `--ink-subtle` is 4.44:1 on the tinted canvas — `resources/css/app.css` — **none; `[UNVERIFIED]` (caught manually by Lighthouse).**
25. **Mobile table/card splits take `display` from classes (`sm:hidden grid gap-3`), never inline `style="display:grid"`** — inline outranks the utility and both variants render — `resources/views/*` convention — **none; `[UNVERIFIED]` visual only.**

### C. Discovered in code, not written down in any doc

26. **`TenantDomain::$fillable` contains `tenant_id`** — safe because it is a *central* table that deliberately does not use `BelongsToTenant`, and the only creation site passes an explicit array (`app/Actions/CreateInstituteAction.php:81`) — **but `TENANCY.md:34` states the guard as universal with no central-table exception**, so a future `TenantDomain::create($request->all())` would attach a hostname to an arbitrary tenant — `app/Models/TenantDomain.php:18` — **none.**
27. **No route inside the `central_domains` loop may use `->name()`** — `route:cache` (part of `optimize`, run on every deploy) throws on duplicate names once two central domains register the same name — `routes/web.php:44-53` comment — `tests/Feature/Platform/RouteRegistrationTest.php:28`.
28. **`SetReferrerPolicy` must be `strict-origin-when-cross-origin`, NOT `no-referrer`** — YouTube's embed fails with error 153 when no Referer is sent at all — `app/Http/Middleware/SetReferrerPolicy.php:10` — `tests/Feature/Courses/YoutubeTest.php:103,123`.
29. **`EnforceDeviceLimit` must cost at most 1 SELECT + 1 UPDATE per request**, using an atomic conditional `UPDATE` for `last_seen_at` rather than read-then-write — `app/Http/Middleware/EnforceDeviceLimit.php:66` — `tests/Feature/Devices/DeviceLimitEnforcementTest.php`.
30. **`ShowDeviceRevokedNotice` must use `session()->now()`, not `flash()`** — the DB's `notified_at` is the source of truth for "already shown"; flash would re-show on a fast reload — `app/Http/Middleware/ShowDeviceRevokedNotice.php:49` — `tests/Feature/Devices/DeviceLimitEnforcementTest.php:245-256` (asserts `assertSee` on first request, `assertDontSee` on second).
31. **The backup `disks` root must differ from the `local` disk root** — otherwise a backup run nests its own output inside the tree it is archiving — `config/filesystems.php:44-51` comment — `tests/Feature/Production/BackupConfigurationTest.php:13`.
32. **No `env()` call in app code; config is the only `env()` reader** — `config:cache` would otherwise break — `app/` (convention, enforced by Larastan level 5) — `composer analyse`.

---

## §6 Verified weaknesses

### README §"Weaknesses and known gaps" re-checked

| # | README's claim | Verified? | Current state |
|---|---|---|---|
| 1 | `tests/Feature/Video/EmbedTokenPlaybackTest.php:98` is flaky on a clock boundary | **Yes at audit — RESOLVED in `b9063a3`** | line 90 computed `$expires = now()->addMinutes(10)->timestamp` while line 98 `assertSee()`d the token the server signed independently, so a second-boundary crossing between the two made the strings differ. `b9063a3` rewrote the assertions to check shape + provenance against the `expires` the *server* actually used — there is no second clock left to race. `composer test` green 3× consecutively (824/823/1). |
| 2 | No CI | **Yes at audit — RESOLVED in `9ff5f11`** | `.github/workflows/ci.yml` runs all five gates on every push and every PR, against a `mysql:8.4` service (gates: lint → analyse → SQLite suite → JS → MySQL suite). `.github/workflows/backup-canary.yml` (since `b6d2e61`) adds a nightly `backup:run` canary. First red run was `37036620403`, which caught the device tie-order bug fixed in `c61a8bc`. |
| 3 | No coverage measurement | **Yes** | `phpunit.xml` has `<source>` only — no `coverage` element, no coverage driver. |
| 4 | "Documentation drift (three known instances)" | **Yes, but understated** | Heading says *three*, lists *four* bullets. §7 of this brief records **16 rows** (15 drifts + 1 verified-correct). |
| 5 | `.env` drifted from `.env.example` | **Yes** | `SESSION_LIFETIME` values differ; `SESSION_SECURE_COOKIE`, `PLATFORM_DOMAIN`, `TENANT_BASE_DOMAIN` are set in `.env.example` but **unset in `.env`**. `SESSION_DOMAIN` and `CENTRAL_DOMAINS` match. **No test reads `.env` at all** — the guard checks `config/session.php` and `.env.example` only. |
| 6 | Repo hygiene: committed SQLite + untracked archive | **Yes at audit — RESOLVED in `db2e9dc`** | `git rm --cached coaching_saas` untracked the 151,552 B SQLite file, and `.gitignore` now covers both `coaching_saas` and `/archive-42JxzH/` (the 9,861,488 B zip, gitignored on human decision 2026-10-02). `git ls-files \| grep coaching_saas` → empty; `git status --porcelain` → clean. |
| 7 | Payments — the one Stage B feature not built | **Yes at audit — RESOLVED in `f8d5b6a` (Phase 10)** | `tests/Feature/Payments/` (7 files) and `app/Http/Controllers/{PaymentController,Manage/PaymentController}.php` exist and are green in CI gate 5. **No `PaymentProvider` contract and no automated gateway** — correct and still intentional: the manual UPI flow needs no provider abstraction (§10 question 6). |
| 8 | App-level security headers are thin | **Yes** | Only `Referrer-Policy` globally (`app/Http/Middleware/SetReferrerPolicy.php:22`) + `X-Content-Type-Options` in 3 controllers. No CSP, HSTS or X-Frame-Options anywhere in `app/`. (These three *are* covered by tests — `tests/Feature/Courses/YoutubeTest.php:123`, `tests/Feature/Courses/AttachmentsTest.php:50`, `tests/Feature/Pwa/IconsTest.php:19`, `tests/Feature/Video/FakeStreamRouteTest.php:112`.) |
| 9 | Single locale | **Yes** | `lang/` contains `en/` and `vendor/` only. |
| 10 | `password_reset_tokens` not tenant-scoped; contact `CHECK` no-op on old MySQL | **Yes** | `database/migrations/0001_01_01_000000_create_users_table.php:24` (PK `email`); guard `app/Support/DatabaseVersionCheck.php`. |
| 11 | Single-developer/no-review process risk | `[UNVERIFIED]` | Not determinable from the repository. |

### New weaknesses the README missed

1. **`AGENTS.md` is a pure Laravel Boost stub, not project guidance.** — **RESOLVED in `ee58c2e`.** It was 47 lines wrapped in `<laravel-boost-guidelines>`, containing PHP install scripts and `composer require laravel/boost --dev`, with `grep -ci` for `tenant`/`isolation`/`phase`/`invariant`/`AGENT_RULES`/`coaching` all returning 0, and Boost in neither `composer.json` nor `composer.lock`. It was the file an agent reads first and it actively conflicted with `CLAUDE.md`. Replaced by an 18-line pointer to `CLAUDE.md`/`AGENT_RULES.md`/`TENANCY.md`/`SECURITY.md` that also records the "don't re-run `laravel/boost:install`, it will overwrite this file" warning.
2. **`docs/DEPLOY.md` §3 overstates `app:preflight`.** — **RESOLVED in `e605cc5`.** It says "`SESSION_DOMAIN` staying unset … and the `VIDEO_DRIVER`/account-key pair are all checked by `php artisan app:preflight`." At audit `AppPreflightCommand::check*` ran 12 checks with **no `SESSION_DOMAIN` check**; Phase 11 added `checkSessionDomain()` (`app/Console/Commands/AppPreflightCommand.php:111`) plus a failing test, and the `VIDEO_DRIVER`/key pair was already checked (`:203`). The sentence in `docs/DEPLOY.md` is now true.
3. **`checkVideoDriver`'s failure branches have no test.** — **RESOLVED in `e605cc5`.** `tests/Feature/Console/AppPreflightCommandTest.php` now has `fails when VIDEO_DRIVER is fake` and `fails when VIDEO_DRIVER is bunny but the account API key is empty`, plus `fails when GD is loaded but has no FreeType support` for the second `checkGdFreetype` branch.
4. **`SessionLifetimeTest` passes for the wrong reason.** It asserts `file_get_contents(config_path('session.php'))` and `.env.example` — never `.env`, which is precisely the file that has drifted. The green test is fully compatible with weakness #5 above.
5. **AGENT_RULES invariant 6 ("jobs carry an explicit tenant_id") is unenforceable — there are no jobs.** `find app -type d -name Jobs` → empty; zero `dispatch()` / `ShouldQueue` references in `app/` or `routes/`.
6. **AGENT_RULES invariants 11 and 16 still have no code path at all** — no webhook endpoint (the polling substitute remains `videos:sync` per `routes/console.php:16-17`) and no Caddy ask-endpoint. Invariant 17's consent flow is likewise unimplemented. **Two that were vacuous at the audit now have code**, both from Phase 10: invariant 12's approval handler (`app/Http/Controllers/Manage/PaymentController.php` — idempotent, `tests/Feature/Payments/PaymentReviewTest.php:263`) and invariant 9's price half (`app/Support/Payments/AmountResolver.php`). Invariant 15 remains satisfied rather than vacuous — no platform payment account exists, by design.
7. **`backup:clean` (01:30) is scheduled *before* `backup:run` (02:00)** — `routes/console.php:13-14`. Harmless at 14-day retention, but `tests/Feature/Production/BackupConfigurationTest.php:18` asserts each cron expression independently and would still pass if the order were inverted.
8. **README's own counts have drifted** (re-measured at `c61a8bc`, 2026-10-03): 60 vs **80** commits (`README.md:79`); 105 vs **113** `tests/Feature` PHP files (plus `tests/Feature/Tenancy/.gitkeep`, so 114 entries in total); 14 vs **16** `lang/en` files; 136 vs **146** routes (`php artisan route:list --json`); 15,291 vs **17,002** test lines (`find tests -name '*.php' | xargs cat | wc -l`); "three known instances" listing four. The gate table at `README.md:86-87` (824 / 792) is now one short too, since `c61a8bc` added a test — current is 825 / 793.
9. **`PlatformAdminDashboardController` is only ever auth-gated, never content-tested.** Every assertion on `/admin/dashboard` in `tests/Feature/Auth/PlatformAdminGuardTest.php:135,147,158,166,176` is `assertOk()` or `assertRedirect()`. `tests/Feature/Platform/PlatformStatsTest.php` tests `PlatformStats::countsByTenant()` directly, never the page that renders it.
10. **No test asserts `DefaultIconGenerator`'s output image** — `tests/Feature/Pwa/IconsTest.php:6,23` assert headers, status codes and tenancy only; the GD/FreeType rendering path is checked solely by `AppPreflightCommand::checkGdFreetype` (a config-level boolean). `[UNVERIFIED]` whether pixel output is covered anywhere.
11. **Three `.env.example` keys are referenced by no `env()` call in this repo's `config/`**: `BCRYPT_ROUNDS` (`.env.example:19`), `BROADCAST_CONNECTION` (`:55`), `VITE_APP_NAME` (`:92`). The first two are consumed by Laravel core defaults (no `config/hashing.php` or `config/broadcasting.php` exists); `VITE_APP_NAME` appears vestigial from the skeleton — **re-verified 2026-10-03 at `c61a8bc`**, still no reader in `config/`, `bootstrap/`, `resources/`, `vite.config.js` or `package.json`. Low severity; kept as a note rather than a defect, since removing keys from `.env.example` would only surprise anyone whose local `.env` still carries them.
12. **`laravel/pao` — RESOLVED 2026-10-02, keep (human decision).** Was flagged here as an unexplained dev dependency (`composer.json:18`, v1.1.5, pulling `laravel/agent-detector`). Reading the source answers the `[UNVERIFIED]` why: `vendor/laravel/pao/src/Autoload.php` calls `Laravel\AgentDetector\AgentDetector::detect()` and, only when it is running under an AI agent (or `PAO_FORCE=1`; `PAO_DISABLE=1` opts out), intercepts Pest/PHPUnit/PHPStan/Pint output and re-emits it as structured JSON — `TestResultParsable.php:232` builds `'result' => 'passed'|'failed'`, `ProfileCollector.php:79` builds `duration_ms`. **That JSON is the final line of every quality gate** (`{"tool":"pest","result":"passed","tests":824,…}`, `{"tool":"pint","result":"passed"}`, `{"tool":"phpstan","result":"passed","errors":0}`), which is why no file in `app/`, `routes/`, `tests/` or `config/` references it: it hooks in through its service provider (`bootstrap/cache/services.php:29`) and an autoload file. It changes output format only — never pass/fail. Keeping it; this entry now records the decision instead of the mystery.
13. **`phpunit/phpunit` is pinned exactly (`12.5.33`, not `^12.5`) — deliberate, kept (human decision 2026-10-02).** `composer.lock` already pins the resolved version for every install, so the exact requirement changes nothing about what CI runs; it only forces a deliberate `composer update phpunit/phpunit` to pick up a patch release, which keeps a PHPUnit upgrade visible as its own commit instead of letting it ride along silently inside some other update. Not a weakness — recorded so the next person doesn't "fix" it.

**Items 12 and 13 are recorded decisions, not weaknesses.**

### Phase 11 security-pass findings still open (`docs/SECURITY_PASS.md` §3/§5)

Untracked until this row was added — the pass recorded ten findings; 3 were fixed in code
with tests, 2 as documentation, and **these 5 were still open when this table was written**
(SP-7, SP-8 and SP-9 are now RESOLVED; **2 remain open** — SP-6 and SP-10, both deliberate
dispositions). Severity and location copied verbatim from the pass; those two are still
**not fixed** (surfacing only).

| # | Description | Severity | Location | Decision |
|---|---|---|---|---|
| SP-6 | The only two `report($e)` calls in `app/` can put a client-supplied `filename` into `storage/logs/laravel.log`: `QueryException::formatMessage()` interpolates bindings into the SQL text. | low | `app/Http/Controllers/Manage/LessonVideoController.php:89` and `:114` (open item **D**) | **Defer** — open item D says to bundle it with future structured-logging work (there is no structured logging anywhere, so one call site is not worth a policy; no phase named). |
| SP-7 | `app:preflight` returns `SUCCESS` immediately unless `APP_ENV=production`, so a deploy that forgets `APP_ENV=production` gets a green preflight while `APP_DEBUG=true`. Not silent — prints "Not running in production…" and `docs/DEPLOY.md §5` documents it — but nothing forces the operator to read that line. | low | `app/Console/Commands/AppPreflightCommand.php:21` | **RESOLVED in `ab58045`** — the missing decision was the `--require-production` flag: without it the no-op is unchanged (exit 0), with it a non-production run errors (`APP_ENV is "…", not "production" — this command was run with --require-production.`) and exits **2** (`self::INVALID`, distinct from 1 = a real check failed). Runbook §6.1/§6.2/§10.12 now pass the flag; covered by `AppPreflightCommandTest` (no-op test + new exit-code-2 test). |
| SP-8 | `.env.example` has no `BUNNY_STREAM_ACCOUNT_API_KEY=` placeholder line, although `docs/DEPLOY.md §3` says to base production `.env` on `.env.example` and set that very key. | low | `.env.example` (open item **E**) | **RESOLVED in `9173eb9`** — the pass's "absent" claim was true at its own HEAD (`f8d5b6a`), but `9173eb9` (same evening, after Phase 11) added both keys: `.env.example:89-90` now carries the comment ("Bunny Stream ACCOUNT-level API key (per-tenant library keys live encrypted on tenants); required by `app:preflight` when VIDEO_DRIVER=bunny") plus `BUNNY_STREAM_ACCOUNT_API_KEY=`, next to `VIDEO_DRIVER=fake` rather than the session block. `docs/DEPLOY_RUNBOOK.md` §1.8 row 7 already records this. **No change made this session** — the placeholder was already there, and adding a second `BUNNY_STREAM_ACCOUNT_API_KEY=` line would have duplicated a key in the file `composer setup` copies to `.env`. |
| SP-9 | `checkMysqlVersion()` is the only preflight check with no failing-branch test — no seam in `config/preflight.php` to mock, so the wiring (not just the pure function) is untested. | low | `app/Console/Commands/AppPreflightCommand.php:124` (open item **F**) | **RESOLVED** — `config/preflight.php` gained `forced_database_version` (nullable string, mirroring the GD seam; `null` in every real environment, so production still queries the real server) and `checkMysqlVersion()` consults it first — before the SQLite driver gate, which is what previously made the branch unreachable by any test. New test *"fails when the database server version is below the CHECK-constraint floor"* forces `8.0.15`, asserts `expectsOutputToContain('MySQL 8.0.15 is below the minimum 8.0.16')` and **exit 1**; proven to have teeth (with the seam reverted the test fails with "Expected status code 1 but received 0"). Commit `test(preflight): failing-branch coverage for checkMysqlVersion` — SHA pinned in the next docs commit, since a file cannot contain its own commit's hash. |
| SP-10 | Local `.env` carries `SESSION_LIFETIME=120` while `config/session.php` defaults to `43200` and `.env.example` ships `43200`. Local only, gitignored. | low | local `.env` | **Accept as known risk** — already recorded as README weakness #5; the pass treats it as a note, not a defect. |

All three "fix now"/NEEDS HUMAN rows are now **applied, not just recorded**: SP-7 (the
`--require-production` flag), SP-8 (already done by `9173eb9` before this table existed —
see its row) and SP-9 (config seam + failing-branch test). **No NEEDS HUMAN items remain in
this table**; the 2 rows still open are deliberate dispositions — SP-6 (defer) and SP-10
(accept as known risk).

---

## §7 Documentation drift ledger

| Doc | Location | Says | Reality | Fix needed |
|---|---|---|---|---|
| `ROADMAP.md` | `:22-25` | Phase 5 = progress, 6 = video, 7 = PDFs | spec/git: 5 = video, 6 = branding/PWA, 7 = progress | Renumber Stage B to {4,5,6,7,8,9} |
| `ROADMAP.md` | `:38` | Stage C "Phase 8" = payment gateway | Phase 8 = devices, shipped in Stage B | Move gateway to a free number |
| `ROADMAP.md` | `:26` | Stage B "Phase 9 (manual UPI only)" | Phase 9 = pilot readiness; the UPI flow shipped separately as **Phase 10** (`docs/specs/phase-10-payments.md`, `f8d5b6a`) | Split the two concepts |
| `ROADMAP.md` | `:28` | "Phase 10: owner/staff admin tooling" | no spec/commit for *that* feature; number 10 was taken by manual UPI payments | Delete or map to Phase 6 |
| `ROADMAP.md` | `:40` | Phase 12 = custom domains "**and PWA support**" | PWA shipped in Phase 6 | Drop the PWA clause |
| `ROADMAP.md` | `:19` | Stage B = `{4,5,6,7,9-UP,10}` | actual = `{4,5,6,7,8,9,P1}` | Rewrite membership |
| `ROADMAP.md` | whole file | no Phase 11, no Phase P1 | P1 has a spec + 2 commits; 11 exists as git `e605cc5` + tag `phase-11` + `docs/SECURITY_PASS.md`, but has no spec file | Add P1; document 11 |
| `ARCHITECTURE.md` | Provider interfaces | `app/Contracts/VideoProvider.php` **(planned)** | exists, with `BunnyVideoProvider` + `FakeVideoProvider` | Drop "(planned)" |
| `docs/specs/phase-2-belongs-to-tenant.md` | line 3 | `**Status:** In Progress  ` (verbatim, trailing spaces) | Phase 2 shipped in `076cd78` + 5 fix commits; README and all invariants live | Change to Complete |
| `docs/DEPLOY.md` | §3 `.env` for production | `SESSION_DOMAIN` unset "**is checked by** `app:preflight`" | **RESOLVED in `e605cc5`** — `checkSessionDomain()` was added (`app/Console/Commands/AppPreflightCommand.php:111`) with a failing test, so the sentence is now accurate | — (closed) |
| `AGENTS.md` | whole file | generic Boost bootstrap; `composer require laravel/boost --dev`; PHP 8.5 | **RESOLVED in `ee58c2e`** — replaced by an 18-line pointer to `CLAUDE.md`/`AGENT_RULES.md`/`TENANCY.md`/`SECURITY.md` | — (closed) |
| `TENANCY.md` | `:34` | "tenant_id is not mass-assignable" stated universally | `app/Models/TenantDomain.php:18` has `tenant_id` in `$fillable` (central table) | State the central-table exception |
| `README.md` | `:187` | "Documentation drift (**three** known instances)" | lists **four** bullets; this ledger has **16 rows** (15 drifts + 1 verified-correct) | Correct the count |
| `README.md` | `:79, :154, :245, :248, :249` | 60 commits · 15,291 test lines · 14 lang files · 136 routes · 105 Feature files | **80 · 17,002 · 16 · 146 · 113** (+1 `.gitkeep`), re-measured at `c61a8bc` 2026-10-03 | Update all five |
| `README.md` | `:194-195` | `composer test:mysql` "runs 19 suites (730 tests)" | **Partly wrong** — `README.md:195` already reads "20 suites (790 tests) — corrected below", and `phpunit.mysql.xml` does have exactly **20** `<testsuite>` entries (this brief previously claimed 19). The test count has since moved on twice: 790 → 792 (`c859e74`) → **793** (`c61a8bc`) | Update README's 790 to 793 |
| `.env` vs `.env.example` | — | `.env` sets `SESSION_LIFETIME` to a different value; omits `SESSION_SECURE_COOKIE`, `PLATFORM_DOMAIN`, `TENANT_BASE_DOMAIN` | `.env.example:42` = 43200 (Phase 8 decision); README:196-200 already documents the drift | No automated guard reads `.env` |

**Contradictions between docs and code: 4** — phase-2 status line · `ARCHITECTURE.md`
"(planned)" · `TENANCY.md:34` universality · `ROADMAP.md` numbering (7 rows, counted as one
conflict cluster in §3). **Closed since the audit:** `docs/DEPLOY.md`'s preflight claim
(`e605cc5`) and the `AGENTS.md` Boost stub (`ee58c2e`) — both were still listed here as
open until this pass.

---

## §8 Files to read / files that are dangerous

### Read before any change

1. `AGENT_RULES.md` — 20 rules + 20 project invariants; the binding contract.
2. `CLAUDE.md` — the "never bypass model events" rule, which silently breaks seeding.
3. `TENANCY.md` — how resolution, scope, escape hatches and composite FKs actually work.
4. `SECURITY.md` — session cookie policy, signed URLs, rate limits, watermark limits.
5. `routes/web.php` — the middleware stack each endpoint sits in (219 lines).
6. `bootstrap/app.php` — prepend/alias/priority order and trusted-proxy policy.
7. `tests/Pest.php` — `inTenant()`, `freshRequestCycle()`, `csvUploadFile()`, suite wiring.
8. `composer.json` — the five gate scripts and their exact order.
9. `resources/css/app.css` — tokens, aurora, and every `.ui-*` component class.
10. `README.md` — status tables, local setup, design-system invariants, known weaknesses.

### Mistakes here break tenancy or security

1. `app/Http/Middleware/ResolveTenant.php` — weakening `abort(404)` or the host normalisation.
2. `app/Scopes/TenantScope.php` — turning the `MissingTenantContextException` throw into a silent no-op.
3. `app/Traits/BelongsToTenant.php` — relaxing the `creating`/`saving`/`updating`/`deleting` hooks.
4. `bootstrap/app.php` — reordering `RequireTenant` after `SubstituteBindings`, or trusting `X-Forwarded-Host`.
5. `app/Auth/TenantUserProvider.php` — dropping the tenant filter from user lookup (enables cross-tenant replay).
6. `config/session.php` + `.env` — `SESSION_DOMAIN` non-null, or `SESSION_LIFETIME` shrunk.
7. `routes/web.php` — a tenant-scoped route outside `require.tenant`, or a named route in the central loop.
8. `app/Http/Middleware/EnforceDeviceLimit.php` / `app/Http/Middleware/EnsureActiveTenantUser.php` — removing a gate from the nested stack.
9. `app/Http/Controllers/LessonVideoStreamController.php` / `app/Http/Controllers/LessonAttachmentController.php` — the `signed` + ownership check.
10. `app/Support/Csv/CsvFormulaGuard.php` + `app/Http/Controllers/UserImportController.php` — formula injection and cross-tenant row import.

---

## §9 Test map

Measured by running `composer test` at `c61a8bc` (2026-10-03): **825 tests, 824 passed, 1
skipped, 2,436 assertions, 152.0 s** (the skip is the documented MySQL-only preflight
test). `composer test:mysql` = **20 suites / 793 tests**. `composer test:js` = 29.

| Directory | Files | Tests | Speed | Covers / notes |
|---|---|---|---|---|
| `tests/Feature/Tenancy/` | 14 | ~80 | fast | resolution, scope, trait, builder, route binding, fixture migrations. **The canary directory.** |
| `tests/Feature/Courses/` | 12 | ~69 | fast | schema, ordering, permissions, `LessonAccess`, UI markup, seeder |
| `tests/Feature/Video/` | 12 | ~58 | **slow** (HTTP fakes, TUS flows) | driver selection, embed tokens, upload, watermark, CSRF, `videos:sync`. Contains the only flaky test. |
| `tests/Feature/Progress/` | 9 | ~78 | fast | heartbeat, completion, resume, teacher view, CSV export |
| `tests/Feature/Devices/` | 9 | ~53 | fast | registration, limit, revocation, prune, session lifetime, schema |
| `tests/Feature/Auth/` | 7 | ~62 | fast | login, session isolation, rate limiting, platform-admin guard, password change |
| `tests/Feature/Users/` | 5 | ~78 | fast | policy matrix (508-line `UserPolicyTest`), schema, mobile People cards |
| `tests/Feature/Pwa/` | 6 | ~25 | fast | manifest, icons, service-worker route, offline, layout, logo route |
| `tests/Feature/Platform/` | 6 | ~27 | fast | home, stats, demo requests, `/admin`, `route:cache` |
| `tests/Feature/Settings/` | 5 | ~27 | fast | mass assignment, validation, logo upload, permissions, academic year end |
| `tests/Feature/Import/` | 4 | ~51 | fast | CSV parse, preview/confirm, credentials sheet, rate limit |
| `tests/Feature/Production/` | 4 | ~8 | fast | cookie domain, Cloudflare proxies, backups — few but high-value |
| `tests/Feature/Console/` | 4 | ~31 | fast | preflight, `local:hosts`, `tenant:create`, `platform-admin:create` |
| `tests/Feature/Enrolments/` | 2 | ~11 | fast | enrolment + UI |
| `tests/Feature/Dashboard/`, `Help/`, `MobileGuards/` | 1+1+1 | 6+6+4 | fast | checklist, help page, mobile safety checker |
| `tests/Feature/` (root) | 2 | 2 | fast | `ExampleTest`, `ErrorPagesTest` — both excluded from `test:mysql` |
| `tests/Unit/` (+ subdirs) | 12 | ~44 | very fast | pure helpers: signers, parsers, rate limiter, `ProgressRecorder`, `ServiceWorkerVersion` |
| `tests/js/` | 2 | 29 | very fast | `progress-tracker.test.mjs` (12), `sw.test.mjs` (8) — Node runner, no browser |
| `tests/Fixtures/` | 6 | — | — | tenancy test models/factories + `2099_*` migrations deliberately kept out of production |

> **The per-directory rows above are a pre-Phase-10 snapshot** (audit, 2026-10-02) and the
> `~` figures were approximate even then. `tests/Feature/Payments/` (7 files, from `f8d5b6a`)
> and the `Actions/`/`Config/` suites are not listed at all, and `tests/Feature/Devices/` is
> now **57** tests rather than ~53 (`c61a8bc` added one). The **headline** figures above the
> table are current — treat the per-directory rows as a coverage map, not a ledger, and
> re-measure before quoting any single directory's number.

**Flaky — none outstanding (was exactly one, RESOLVED in `b9063a3`).**
`tests/Feature/Video/EmbedTokenPlaybackTest.php:98` used to assert an exact token derived
from its own `now()` against one the server derives independently, crossing a second
boundary roughly once per full-suite run (3/3 in isolation). It now asserts shape and
provenance against the `expires` the server actually used, which cannot race, and the
suite went green 3× consecutively after the fix. Recorded rather than deleted because
§6 row 1 carries the same history — **a failure in that file is a real failure now.**

**The 10 canary tests (failure = real regression):**

1. `tests/Feature/Tenancy/BelongsToTenantTest.php` — the isolation contract itself (306 lines).
2. `tests/Feature/Auth/SessionIsolationTest.php` — cross-tenant replay, cookie scoping, provider scoping.
3. `tests/Feature/Tenancy/CentralDomainRouteBindingTest.php` — the 404-not-500 middleware ordering.
4. `tests/Feature/Tenancy/TenantScopeTest.php` — scope throws instead of silently emptying.
5. `tests/Feature/Tenancy/TenantBuilderTest.php` — builder guards incl. `TENANT_ID` key normalisation.
6. `tests/Feature/Production/SessionCookieDomainTest.php` — host-only cookie, the first isolation layer.
7. `tests/Feature/Production/CloudflareTrustedProxyTest.php` — forged-`XFF` rate-limit bucket sharing.
8. `tests/Feature/Settings/MassAssignmentTest.php` — `tenant_id`/`status`/`logo_path` never from input.
9. `tests/Feature/Platform/RouteRegistrationTest.php:28` — `route:cache` success; failure breaks every deploy.
10. `tests/Feature/Import/CredentialsSheetTest.php` — the `strip_tags` first-match rule (§5.21).

---

## §10 Open questions for a human

1. **Which phase numbering is canonical going forward?** Specs + git say 5=video…9=pilot; `ROADMAP.md` says otherwise. `README.md:135` says "the roadmap needs renumbering" but nobody did it. Nothing in the repo resolves this.
2. **Is the manual UPI flow still a Stage B requirement, or has it slipped to Stage C?** — **RESOLVED: it shipped in Stage B as Phase 10.** `f8d5b6a Phase 10: manual UPI payment flow` + `efcf4aa Phase 10: tests (Step 1)`, tag `phase-10`, spec `docs/specs/phase-10-payments.md`; `tests/Feature/Payments/*` (7 files) is green in CI gate 5. `PAYMENTS.md`'s "ships first" held. Shipping it does *not* answer question 6 below — the flow needs no `PaymentProvider` abstraction, so that contract question is untouched. **Was "unbuilt / not started" at the audit.**
3. **Is `AGENTS.md` meant to be replaced by real project rules?** — **RESOLVED in `ee58c2e`: yes, and it was done.** The Boost stub was replaced by an 18-line pointer to `CLAUDE.md`/`AGENT_RULES.md`/`TENANCY.md`/`SECURITY.md`, carrying an explicit warning that re-running `laravel/boost:install` would overwrite the file again.
4. **Is the committed `coaching_saas` SQLite file intentional** (a fixture?) **or should `.gitignore` absorb it?** — **RESOLVED in `db2e9dc`: ignore it, done.** `git rm --cached coaching_saas` untracked the 151 KB file and `.gitignore` now covers `coaching_saas` plus `/archive-42JxzH/` (the archive was a separate human decision, recorded in §6 item 12).
5. **Will `password_reset_tokens` be tenant-scoped in Stage B or Stage C?** — **RESOLVED: Stage C**, decided during Phase 11 and written down in `docs/SECURITY_PASS.md` §6 (`e605cc5`, wording pinned in `60093da`). The table keeps PK `email` with no `tenant_id` and still has no route, writer or broker, so the exposure is latent; re-keying now and again when self-service reset lands would mean doing the same schema change twice. Owner/staff reset and self change-password are already wired, tenant-scoped and tested, so nothing is blocked on the deferral.
6. **Is `ARCHITECTURE.md`'s `PaymentProvider` contract for the manual UPI flow or only the Stage C gateway?** It says "First implementation is a manual UPI flow", but `PAYMENTS.md` describes a screenshot-approve flow that needs no provider abstraction.
7. **Is `DomainType::Custom` meant to stay schema-only until Stage C?** No creation code path exists.
8. **Is `laravel/pao` intentional?** — **RESOLVED in `ca73b30`: yes, keep it.** The source was read and the answer recorded in §6 item 12: while running under an AI agent it re-emits Pest/PHPUnit/PHPStan/Pint output as the structured JSON final line of every gate, and is inert otherwise — **including in CI**: run `37090769580`'s log contains zero `"tool":"pest"` JSON lines and instead shows plain `PASS …` output, so the detector does not treat GitHub Actions as an agent. Output format only, never pass/fail.
9. **Should `docs/DEPLOY.md`'s `SESSION_DOMAIN` preflight claim become a real check, or should the doc change?** — **RESOLVED in `e605cc5`: the check was added, so the doc stands.** `AppPreflightCommand::checkSessionDomain()` with a passing test (`…empty string docs/DEPLOY.md tells you to use`) and a failing test (`…widens the session cookie to a shared parent domain`). The §7 `docs/DEPLOY.md` row is closed on the same commit.
10. **Who owns the "mini security pass" (`README.md:123`) — is it a discrete pass or already covered by the suite?** — **RESOLVED in `e605cc5`: a discrete pass, already run.** It produced `docs/SECURITY_PASS.md` (tag `phase-11`): ten findings, three fixed in code with tests (`SESSION_DOMAIN` preflight, three missing `app:preflight` failing branches, video-upload rate limits), two fixed as documentation, five left open. `README.md:123` was corrected to "Run (Phase 11) — docs/SECURITY_PASS.md" in `c21ca36`.
11. **Should `composer test:mysql`'s four excluded Unit files stay excluded?** `DatabaseVersionCheckTest` and `LoginRateLimiterTest` are arguably DB-adjacent (`phpunit.mysql.xml` vs README:493).

**Still open: 1, 6, 7, 11.** Questions 2–5 and 8–10 are answered above with their commit
SHA. Recorded, not deleted, per the convention used throughout this document.

---

## §11 How to work on this project

### Command sequence before every commit (order matters)

```sh
composer lint        # 1. scripts.lint  → vendor/bin/pint            (formats first, so everything below sees final code)
composer test        # 2. scripts.test  → config:clear + pest        (825 tests, SQLite, ~152 s — widest signal)
composer test:js     # 3. scripts.test:js → node --test tests/js/**  (29 tests, no browser)
composer analyse     # 4. scripts.analyse → phpstan level 5          (must be 0 errors)
composer test:mysql  # 5. scripts.test:mysql → pest --configuration=phpunit.mysql.xml (793 tests, real MySQL — slowest, catches composite-FK regressions SQLite cannot)
```

`composer lint` is listed first deliberately: it is `vendor/bin/pint` (a *writer*, not
`pint --test`), so running it late would leave formatted code that was never re-tested.

### Three ways a change silently breaks tenant isolation

1. **Putting `tenant_id` into `$fillable`, or reading it from `$request`.** The guard in `app/Traits/BelongsToTenant.php` only rejects a *mismatched* id; a request supplying the right-looking wrong id still reaches the controller. `MassAssignmentTest` covers only the settings endpoint — new endpoints need their own assertion.
2. **Touching a tenant-owned model outside a `TenantContext`** — console command, job, `afterResponse()` closure, or a test that forgets `inTenant()`. Either `TenantScope` throws (loud) or, if you "fix" it with `withoutGlobalScope()`, rows are written with no/wrong `tenant_id` (silent). `TenantContext::reset()` runs in `finally` at the end of every request (`app/Http/Middleware/ResolveTenant.php:36`), so anything after the response carries no tenant.
3. **Resolving the tenant from anything but `Request::getHost()`** — honouring `X-Forwarded-Host`, adding a header/session fallback, or setting `SESSION_DOMAIN` to a parent domain. Each reopens the cross-tenant path `bootstrap/app.php` and `config/session.php` currently close.

### Three ways a change silently breaks the design system

1. **Writing any `--brand-*` derivative into the inline `<style>` in `resources/views/layouts/app.blade.php`** — a `[a-z0-9]{4}-[a-z0-9]{4}` pattern there is captured by `strip_tags()` before the real temp password (`CredentialsSheetTest`).
2. **Swapping the order of `-webkit-backdrop-filter` / `backdrop-filter`, or adding another prefixed property without checking the bundler** — Vite collapses identical prefixed/unprefixed pairs and keeps the last, dropping the standard one. Only visible in a real bundle.
3. **Adding an inline `style="display:…"` to a mobile table/card split, or moving `background-clip: text` outside its `@supports` guard** — inline outranks `sm:hidden` (both variants render), and an unguarded clip can render the `<h1>` invisible. No test catches either.

### The one command that must pass before deploy

```sh
php artisan app:preflight
```

Per `README.md` ("Other useful Artisan commands") and `docs/DEPLOY.md` §5 — run it before
every deploy. It is a **no-op outside `APP_ENV=production`**
(`tests/Feature/Console/AppPreflightCommandTest.php:38`), refuses `VIDEO_DRIVER=fake`, and
is the last line of defence for a misconfigured production `.env`.

---

## §12 Coverage gaps

Confident (from directory listings + test names + targeted greps):

1. **The entire visual/design-system layer.** No test asserts computed CSS, contrast, overflow or Lighthouse scores. The README's "Lighthouse 100/100/100, no horizontal overflow" claim is browser-run only. Affects §5 items 22-25.
2. **`PlatformAdminDashboardController` content.** Only `assertOk()`/`assertRedirect()` (`tests/Feature/Auth/PlatformAdminGuardTest.php:135-176`); `PlatformStatsTest` exercises the helper class, never the page.
3. **`.env` itself** — no test reads it; `SessionLifetimeTest` reads `config/session.php` and `.env.example`.
4. **Security-header coverage exists but is scattered** — `Referrer-Policy` asserted in exactly one test (`tests/Feature/Courses/YoutubeTest.php:123`), `nosniff` in four. Nothing asserts their absence/presence globally on ordinary pages.

Uncertain — `[UNVERIFIED]`:

5. **`app/Support/Branding/DefaultIconGenerator` output** — `tests/Feature/Pwa/IconsTest.php:6,23` assert headers, status codes and tenancy but never the generated image bytes; the GD/FreeType rendering path is checked solely by `AppPreflightCommand::checkGdFreetype` (a config-level boolean). `[UNVERIFIED]` whether pixel output is covered anywhere.
6. **`app/Support/Import/ImportSessionStore` and `app/Actions/CreateInstituteAction`** — referenced by no test *by name*, but both are exercised indirectly through `UserImportController`/`InstituteController` callers that *are* tested (`tests/Feature/Import/*`, `tests/Feature/Platform/Admin/InstituteTest.php`). Whether their edge cases (15-minute expiry, duplicate-subdomain race) are fully covered cannot be confirmed from names alone. `[UNVERIFIED]`
7. **Areas with no test file at all by name:** `app/Support/DatabaseVersionCheck` *is* covered (`tests/Unit/Support/DatabaseVersionCheckTest.php`) — listed here only to record that I checked. I found **no controller or console command without an apparent test**; every one of the 31 controllers and 6 commands maps to at least one test file.

Nothing found for: dead code (grepped `app/Support`, `app/Actions`, `app/Services` — all have callers), `TODO`/`FIXME`/`HACK` markers (**0 matches** across `app/`, `config/`, `database/`, `resources/`, `routes/`, `tests/`), or stale migrations (all 22 migrations are referenced by current models/tests).
