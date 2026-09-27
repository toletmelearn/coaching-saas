# Agent Rules

Global rules for any agent (human or AI) working on this codebase. These encode the
invariants established in [TENANCY.md](TENANCY.md), [SECURITY.md](SECURITY.md),
[VIDEO.md](VIDEO.md), [PAYMENTS.md](PAYMENTS.md), and [PRIVACY.md](PRIVACY.md) — read those
first for the reasoning; this file is the checklist.

1. **Fail closed on tenant resolution.** An unrecognized hostname is rejected, never routed
   to a default or "demo" tenant.
2. **Never trust `tenant_id` from the client.** It always comes from `TenantContext`,
   never from a request body, query string, or hidden field.
3. **Every tenant-owned model uses `BelongsToTenant`.** No tenant-owned table is queried
   without the tenant global scope, and no `withoutGlobalScope` on it without a documented,
   reviewed reason.
4. **Composite foreign keys are mandatory for tenant-owned parent/child pairs.** Parent:
   `UNIQUE(tenant_id, id)`. Child: foreign key on `(tenant_id, parent_id)`. No exceptions
   without updating [TENANCY.md](TENANCY.md) first.
5. **`TenantContext` is set once per request and never mutated.** Acting "as" another
   tenant (support tooling, backfills) goes through an explicit, logged, narrow mechanism —
   never by reassigning the ambient context.
6. **Jobs carry an explicit `tenant_id`.** Never assume an ambient tenant context exists
   inside a queued job.
7. **`SESSION_DOMAIN` stays `null`.** Do not "fix" cross-subdomain session issues by
   widening the cookie domain — that reintroduces cross-tenant session sharing.
8. **Session replay across tenants is a critical bug, not an edge case.** Any change to
   auth or the user provider must preserve: a session id from tenant A can never resolve a
   user under tenant B's hostname.
9. **Price, ownership, and permissions are always re-derived server-side** from the
   authenticated user + tenant scope, never accepted as given from the client.
10. **Videos, PDFs, and payment screenshots live on private storage** and are served only
    through signed, expiring URLs — never a public path.
11. **Webhooks (Bunny, payment gateways) are notifications to re-check, not sources of
    truth.** Always re-fetch authoritative status from the provider's API before mutating
    state.
12. **Webhook and approval handlers are idempotent.** Processing the same event/delivery
    twice must not double-charge, double-enroll, or double-notify.
13. **One Bunny Stream library per tenant.** Do not share a library across tenants to save
    setup effort — it breaks both isolation and per-tenant usage metering.
14. **Free-preview lessons (`provider = youtube`) bypass signed URLs and Bunny entirely** —
    don't add signing/auth machinery to intentionally-public preview content.
15. **The platform never custodies student funds.** Don't introduce a shared/platform-level
    payment account that receives student payments on a tenant's behalf.
16. **Custom-domain TLS issuance must check `tenant_domains` verification status.** The
    Caddy "ask" endpoint approves only verified domains — never an open approve-any-hostname
    endpoint.
17. **Treat under-18 users as "children" under DPDP** and do not process their data without
    the planned guardian-consent flow once it exists; don't build features that assume
    self-consent is always sufficient.
18. **Don't state unresolved legal questions as fact.** Anything flagged "needs legal
    confirmation" in [PRIVACY.md](PRIVACY.md) stays flagged until a human with legal
    authority confirms it — don't quietly resolve it via a code comment or assumption.
19. **Don't implement ahead of the roadmap.** Stage B ships the manual UPI flow; don't build
    gateway integration, custom domains, or subscriptions early because they seem easy —
    check [ROADMAP.md](ROADMAP.md) first.
20. **When a security or tenancy invariant and a feature request conflict, the invariant
    wins.** Raise the conflict instead of quietly relaxing scoping, signing, or validation
    to make a feature easier to ship.
