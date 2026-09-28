# Roadmap

Numbered "Phases" below are implementation phases; "Stages" group them into release
milestones.

## Stage A — Foundation and isolation (Phases 0–3)

- Phase 0: repository foundation, tooling, documentation (this phase).
- Phase 1: central tables (`tenants`, `tenant_domains`, `platform_admins`,
  `system_settings`) and hostname-based tenant resolution.
- Phase 2: `BelongsToTenant` isolation framework — `TenantContext`, `BelongsToTenant` trait,
  tenant-aware route model binding, composite foreign key convention (see [TENANCY.md](TENANCY.md)).
- Phase 3: tenant users and authentication — the first tenant-owned models using `BelongsToTenant`
  (roles: owner/staff/student; tenant-aware user provider).

Stage A is done when tenant isolation is provably enforced (schema + tests), before any
product feature is built on top of it.

## Stage B — Pilot (Phases 4, 5, 6, 7, 9-manual-UPI, 10, mini security pass, deploy)

- Phase 4: courses, lessons, enrollment.
- Phase 5: student dashboards / progress.
- Phase 6: video via Bunny Stream (see [VIDEO.md](VIDEO.md); watermarking decision due
  before this phase ships).
- Phase 7: PDFs / course materials, signed URLs.
- Phase 9 (manual UPI only): manual payment flow (see [PAYMENTS.md](PAYMENTS.md)) —
  automated gateway integration is deferred to Stage C.
- Phase 10: owner/staff admin tooling for the pilot tenant.
- A focused security pass (session/tenancy replay checks, rate limits, signed URL
  expirations) before deploy.
- Deploy: **the friend running the pilot institute is tenant #1.**

Stage B is scoped to what one real tenant needs to run their coaching business day to day —
not every feature in this document.

## Stage C — Gateway, domains, hardening (Phases 8, 12, 13, full 14, consent flow)

- Phase 8: automated payment gateway integration (per-tenant accounts, idempotent
  webhooks — see [PAYMENTS.md](PAYMENTS.md)).
- Phase 12: custom domains + TLS via Caddy on-demand (see [SECURITY.md](SECURITY.md)), and
  PWA support.
- Phase 13: queues at scale, audit logging.
- Phase 14 (full): whatever was deferred from Stage B's lighter version of this phase.
- DPDP consent flow: guardian fields, `consents` table, export/delete tooling (see
  [PRIVACY.md](PRIVACY.md)).

## Stage D — Growth (subscriptions, metering, WhatsApp)

- Tenant subscription/billing plans on the platform side.
- Usage metering (video bandwidth/storage, active students) feeding tenant billing.
- WhatsApp notifications (enrollment confirmations, reminders).

No Stage D work starts before Stage B is live with a real tenant using it.
