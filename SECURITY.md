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
- Bunny Stream API keys and signing keys are application-level secrets (`.env`), not
  per-tenant unless a tenant brings their own account (out of scope for the pilot — see
  [VIDEO.md](VIDEO.md)).

## Private storage and signed URLs

- Videos, PDFs, and payment screenshots are stored on a **private** disk — never on a
  publicly readable path or bucket.
- Access to any of them goes through **signed, expiring URLs**. This applies to PDFs
  (course material, invoices, receipts) exactly as it does to video playback — a PDF is not
  treated as "less sensitive" just because it isn't streamed.
- Signed URLs are generated per-request, scoped to the requesting user's tenant and
  enrollment/ownership, and expire quickly enough to limit the value of a leaked link.

## Rate limiting

- Auth endpoints (login, password reset), payment-sensitive endpoints (manual UPI approval,
  webhook receivers), and any public-facing form are rate limited per IP and, where a tenant
  is already resolved, per tenant.

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
