# Tenancy

## Resolution

- Tenant is resolved from the request hostname (subdomain or custom domain), looked up in
  `tenant_domains`. There is no header-based or session-based tenant selection.
- **Fail closed.** If the hostname does not match a row in `tenant_domains`, the request is
  rejected (404/403) before any controller runs. There is no default or fallback tenant —
  an unresolved hostname must never silently fall through to tenant #1 or to a "demo"
  tenant.
- Resolution happens in middleware, early in the pipeline, before session/auth middleware
  runs, so that everything downstream can assume a tenant is already known.

## TenantContext

- A `TenantContext` (or equivalent singleton bound in the container) holds the resolved
  tenant for the current request.
- **Immutable once set.** Nothing in application code re-resolves or overwrites the tenant
  mid-request. If code needs to act "as" a different tenant (e.g. a queued job, a platform
  admin support action), it does so through an explicit, narrow, logged escape hatch — never
  by mutating the ambient context.

## BelongsToTenant trait

- Tenant-owned Eloquent models use a `BelongsToTenant` trait that:
  - Adds a global scope filtering all queries to the current `TenantContext` tenant.
  - Auto-fills `tenant_id` on creation from the current context.
  - Refuses to save a model whose `tenant_id` doesn't match the current context (defense in
    depth against a stray mass-assignment or `withoutGlobalScope` call).

## Tenant-aware route model binding

- Route model binding resolves models through the tenant-scoped query (i.e. respects the
  `BelongsToTenant` global scope), so `/courses/{course}` for tenant A can never resolve a
  course belonging to tenant B, even if the ID is guessed or enumerated.

## Jobs and queues

- Queued jobs never rely on an ambient "current tenant" — there isn't one once a job leaves
  the HTTP request. Every job constructor takes an explicit `tenant_id` (or the tenant-owned
  model, which carries its own `tenant_id`), and the job sets `TenantContext` from that value
  when it runs.
- **Scoped binding reset:** Laravel's queue service provider calls `forgetScopedInstances()` at
  the job execution boundary, resetting all scoped services. This prevents a request's
  `TenantContext` from leaking into a queued job. However, this is a framework safety mechanism,
  not a substitute for explicit job design: each job must still carry its own `tenant_id` and
  re-set `TenantContext` to it (Phase 13: queues at scale will add a job middleware to enforce
  this pattern).

## Composite foreign keys (schema-enforced isolation)

This is the core mechanical rule that makes cross-tenant links a database constraint
violation, not just an application bug:

- Every tenant-owned parent table has a **unique constraint on `(tenant_id, id)`**, in
  addition to its primary key on `id`.
- Every tenant-owned child table that references that parent stores **both** `tenant_id`
  and the parent's id, and its foreign key references the parent's `(tenant_id, id)` unique
  constraint — not just the parent's `id`.
- Effect: a child row can only ever point at a parent row that shares its own `tenant_id`.
  Inserting a child with a mismatched `tenant_id`/parent-id pair fails at the database
  level, regardless of what application code did or didn't check.
- This rule must be enforced identically on MySQL (production) and validated by
  `tests/Feature/Tenancy` run under `composer test:mysql` — SQLite's foreign key handling is
  not a reliable stand-in for this specific guarantee.

## Tenant-aware user provider

- Authentication uses a custom user provider that scopes user lookup by the resolved
  tenant. A session's user id can only ever resolve to a user belonging to the tenant the
  session's hostname resolves to.
- This is what makes "session replay across tenants must fail" (see
  [SECURITY.md](SECURITY.md)) actually hold: even if a session cookie or id were replayed
  against a different tenant's subdomain, the user provider would fail to resolve a user,
  because the same numeric user id in a different tenant either doesn't exist or belongs to
  someone else and is excluded by the scope.
