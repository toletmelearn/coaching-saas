# Custom domains

## Why Stage C, not Stage B

- The pilot runs on a subdomain. The friend who is tenant #1 logs into
  `friend.coaching.test` and never touches DNS — Stage B is scoped to "what one real
  tenant needs day to day" (`ROADMAP.md`), and a subdomain needs zero customer
  coordination, zero certificate machinery, zero support surface.
- A custom domain is what a **mature** institute asks for after its first month — the
  brand on the WhatsApp share link, the name students type, the SEO the subdomain can't
  have. It arrives with expectations Stage B can't meet yet: instant propagation of a DNS
  change the owner made, certificates that renew without a human, an admin who can see
  every tenant's domains at a glance.
- It also carries operational dependencies the pilot doesn't: the platform must run Caddy
  with on-demand TLS at the origin (today's deploy target is Nginx + Cloudflare, see
  Unresolved risk in the spec), and one owner's DNS mistake becomes a support ticket.
  AGENT_RULES invariant 19 — don't implement ahead of the roadmap — puts that burden in
  Stage C, after the pilot is live.

## Why Caddy, not nginx + Certbot

- The answer already lives in [SECURITY.md](SECURITY.md) §"Custom domains and TLS":
  **Caddy's on-demand TLS with an "ask" endpoint**. A hostname Caddy has never seen
  triggers a callback to Laravel before any certificate is requested; Laravel answers 200
  only if `tenant_domains` holds that hostname with `verified_at` set. That callback *is*
  AGENT_RULES invariant 16 — never an open approve-any-hostname, which would let anyone
  mint a certificate for any domain on the internet through our endpoint.
- nginx + Certbot assumes every hostname is known **before** the first request: a vhost
  must exist for HTTP-01, or DNS provider API credentials must exist for DNS-01. Neither
  holds when a tenant adds `physics-institute.com` at 2am and we have no control of their
  zone. Wildcard certificates can't cover arbitrary customer domains either.
- Caddy's model inverts it: request arrives → ask endpoint (database-gated) → 200 →
  issue → renew silently, forever. No pre-provisioning, no reload hooks, no cron.

## Three-layer verification

1. **DNS TXT — ownership.** `_coaching-verify.<domain>` must contain the row's
   unguessable 32-hex token (`random_bytes(16)`). Only someone who can write records
   under the name can pass.
2. **DNS A/CNAME — routing.** The hostname must point at the platform (`tenants.<platform>`
   or its IPs). Proves the domain will actually reach us — a TXT alone would "verify" a
   domain whose traffic goes nowhere.
3. **TLS ask endpoint — issuance.** Even with 1 and 2 proven, certificates are issued only
   while `verified_at` stays set: the ask endpoint re-reads the database on every
   issuance/renewal, so revocation cuts off the *next* certificate, not just the current
   one.

Only a passing check sets `verified_at`; nothing is servable before that (resolution 404s,
issuance refuses), and a later DNS failure warns — it never silently revokes
(human force re-verify does).

## What the operator sees vs what the tenant sees

- **Platform operator:** `/admin/domains` — every custom domain across all tenants with
  status badges, searchable and filterable; the same rows on `/admin/institutes/{tenant}`;
  a **Force re-verify** button that nulls `verified_at`, rotates the token and writes an
  `admin_audit_logs` entry; Caddy's ask traffic in the logs. The operator never touches a
  customer's DNS and has no button that approves without proof.
- **Tenant owner:** `/manage/settings/domains` — enter the domain, copy the TXT and
  CNAME/A records, press **Verify now** (or wait for the 5-minute poll), watch the badge
  go `unverified → verified → live`, remove whenever. No certificates, no Caddy, no
  ACME — one screen, four badges.

## If a domain is revoked or expires

- **Revoked** (admin Force re-verify): `verified_at = null` takes effect on both layers at
  once — the resolver 404s the hostname and the ask endpoint denies renewal. The already
  issued certificate keeps the site up until it expires, then the hostname goes dark
  unless the owner re-proves ownership with the new token. Fail closed, by design.
- **Removed by the owner:** the row is gone — next request 404s immediately, no
  certificate renewal, no residue. Removing twice is a no-op, not an error.
- **Domain expires at the registrar and is re-registered by a stranger:** the stale
  `verified_at` still approves issuance while DNS points at us — the monthly re-check
  warns (TXT gone) but doesn't revoke. This is Known limit #2 and the phase's sharpest
  edge; see the spec's Unresolved risks.

## Known limits

- **Known limit #1**: `tls_issued_at` records the ask's 200, not a probe of the
  certificate — an issuance that fails *after* approval (e.g. Let's Encrypt rate limits,
  5 certs/registered domain/week) is invisible; the badge can say `live` while the site
  is dark.
- **Known limit #2**: verification is point-in-time. Ownership was proven once; DNS can
  change afterwards (expiry/transfer), and the design deliberately trades freshness for
  availability — only a human force re-verify revokes.
- **Known limit #3**: the check cannot distinguish "not propagated yet" from "wrong
  value", so a correct configuration can show `failed` for up to 48 hours; apex CNAME
  flattening providers can false-negative the routing half.
- **Known limit #4**: an abandoned pending row occupies its unique hostname forever,
  blocking the rightful owner (any tenant) from claiming it — no prune policy in v1.
- **Known limit #5**: the deploy topology is unresolved — SECURITY.md assumes Caddy,
  `docs/DEPLOY.md` targets Nginx + Cloudflare Full(strict). Where on-demand TLS terminates
  must be settled before implementation.

Out of scope: wildcards, more than 3 custom domains per tenant, transfer between tenants,
SPF/DKIM/DMARC, the platform's own domain, subdomain→custom-domain redirects.

See [docs/specs/phase-14-custom-domains.md](docs/specs/phase-14-custom-domains.md) for the
full implementation plan.
