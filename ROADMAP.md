# Roadmap

> **Phase numbering:** Phase numbers below match `docs/specs/` and git history — the
> authoritative source. This file previously used a different numbering that collided with
> the specs from Phase 5 onward; that drift is recorded in `PROJECT_BRIEF.md` §3 and §7.
> Cross-references to the old numbers appear in `docs/DEPLOY.md` and the spec files'
> status lines; those are historical and are not renumbered.

Numbered "Phases" below are implementation phases; "Stages" group them into release
milestones. Phase titles match the spec files' own titles.

## Stage A — Foundation and isolation (Phases 0, 1, 2, 3, P1) — all done

- Phase 0: repository foundation, tooling, documentation (this phase) — no spec.
- Phase 1: **Central Tables and Hostname-Based Tenant Resolution** — `tenants`,
  `tenant_domains`, `platform_admins`, `system_settings` and hostname-based tenant
  resolution (spec: [phase-1-tenant-resolution.md](docs/specs/phase-1-tenant-resolution.md)).
- Phase 2: **BelongsToTenant Isolation Framework** — `TenantContext`, `BelongsToTenant` trait,
  tenant-aware route model binding, composite foreign key convention (see [TENANCY.md](TENANCY.md);
  spec: [phase-2-belongs-to-tenant.md](docs/specs/phase-2-belongs-to-tenant.md)).
- Phase 3: **Tenant Users & Authentication** — the first tenant-owned models using
  `BelongsToTenant` (roles: owner/staff/student; tenant-aware user provider) (spec:
  [phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md), plus
  [phase-3-brief.md](docs/specs/phase-3-brief.md)).
- Phase P1: **Platform Home and Admin** — central platform home and `/admin` (spec:
  [phase-p1-platform.md](docs/specs/phase-p1-platform.md)).

Stage A is done when tenant isolation is provably enforced (schema + tests), before any
product feature is built on top of it.

## Stage B — Pilot (Phases 4, 5, 6, 7, 8, 9, 10 — all done, plus Phases 11 and 12 and the deploy)

- Phase 4: **Courses, Lessons, Notes and Manual Enrolment** — courses, chapters, lessons,
  PDF attachments, free YouTube previews, manual enrolment (spec:
  [phase-4-courses.md](docs/specs/phase-4-courses.md)).
- Phase 5: **Protected Video for Lessons** — Bunny Stream, signed embed tokens, watermarking
  (see [VIDEO.md](VIDEO.md); spec: [phase-5-video.md](docs/specs/phase-5-video.md)).
- Phase 6: **Institute Settings, Branding and Installable PWA** (spec:
  [phase-6-branding-pwa.md](docs/specs/phase-6-branding-pwa.md)).
- Phase 7: **Lesson Progress Tracking** — heartbeat/resume, auto-complete, teacher progress
  pages (spec: [phase-7-progress.md](docs/specs/phase-7-progress.md)).
- Phase 8: **One Device per Student** (spec: [phase-8-devices.md](docs/specs/phase-8-devices.md),
  plus [phase-8-brief.md](docs/specs/phase-8-brief.md)).
- Phase 9: **Pilot Readiness** — bulk onboarding, credential sharing, teacher help (spec:
  [phase-9-pilot.md](docs/specs/phase-9-pilot.md), plus
  [phase-9-brief.md](docs/specs/phase-9-brief.md)).
- Phase 10: **Manual UPI Payments** — student pays the tenant's UPI id, owner approves a
  screenshot (see [PAYMENTS.md](PAYMENTS.md); spec:
  [phase-10-payments.md](docs/specs/phase-10-payments.md)) — automated gateway integration
  is deferred to Stage C.
- Production readiness and the deploy runbook
  ([docs/DEPLOY.md](docs/DEPLOY.md), [docs/DEPLOY_RUNBOOK.md](docs/DEPLOY_RUNBOOK.md)).
- Phase 11: a focused security pass (session/tenancy replay checks, rate limits, signed URL
  expirations) before deploy — no spec file; recorded in
  [docs/SECURITY_PASS.md](docs/SECURITY_PASS.md) (git tag `phase-11`) — done.
- Phase 12: **Live Classes via Jitsi Meet** — schedule a class per course/lesson,
  enrolment-gated server-minted join, heartbeat attendance, teacher report + CSV export
  (see [LIVE_CLASSES.md](LIVE_CLASSES.md); spec:
  [phase-12-live-classes.md](docs/specs/phase-12-live-classes.md)) — done, flag-gated
  behind `LIVE_CLASSES_ENABLED` (off by default).
- Deploy: **the friend running the pilot institute is tenant #1 — the current milestone.**

Stage B is scoped to what one real tenant needs to run their coaching business day to day —
not every feature in this document.

## Stage C — Gateway, domains, hardening (payment gateway, Phases 13, 14, 15, DPDP consent) — not started

- Automated payment gateway integration (per-tenant accounts, idempotent webhooks — see
  [PAYMENTS.md](PAYMENTS.md)) — **no phase number**: the number it used to claim collides
  with Phase 8 (One Device per Student).
- Phase 13: custom domains + TLS via Caddy on-demand (see [SECURITY.md](SECURITY.md)) —
  *renumbered from 12 when Live Classes took that number in git history.*
- Phase 14: queues at scale, audit logging.
- Phase 15: catch-all — whatever was deferred from Stage B.
- DPDP consent flow: guardian fields, `consents` table, export/delete tooling (see
  [PRIVACY.md](PRIVACY.md)).

## Stage D — Growth (subscriptions, metering, WhatsApp) — not started

- Tenant subscription/billing plans on the platform side.
- Usage metering (video bandwidth/storage, active students) feeding tenant billing.
- WhatsApp notifications (enrollment confirmations, reminders).

No Stage D work starts before Stage B is live with a real tenant using it.
