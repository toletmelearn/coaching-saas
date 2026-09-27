# Architecture

## Codebase and database

- Single Laravel codebase serves every tenant. No per-tenant deployments.
- Single database. Every tenant-owned table carries a `tenant_id` column; there is no
  database-per-tenant or schema-per-tenant split. See [TENANCY.md](TENANCY.md) for how
  isolation is enforced within that shared schema.

## Central (non-tenant) tables

These tables are never scoped by `tenant_id` — they describe tenants themselves or the
platform:

- `tenants` — one row per coaching institute (name, plan, status, Bunny Stream library id,
  gateway config references).
- `tenant_domains` — hostnames (subdomain or custom domain) that resolve to a tenant.
  A tenant can have more than one domain (e.g. subdomain + custom domain during migration).
- `platform_admins` — platform-side staff, distinct from any tenant's `owner`/`staff` users.
  Never appears in a tenant's user list.
- `system_settings` — platform-wide configuration (feature flags, global limits).

## Roles

Three tenant-scoped roles, assigned per user per tenant:

- `owner` — the institute's admin. Full control of their tenant: courses, pricing, staff,
  payouts, branding.
- `staff` — instructors/coordinators the owner grants scoped permissions to (e.g. manage
  courses and students, no billing access).
- `student` — enrolled learners; access limited to their own enrollments and progress.

Platform admins are a separate concept (see `platform_admins` above) and do not hold a
tenant role.

## Provider interfaces

Video and payments are abstracted behind interfaces so a tenant-specific or future provider
can be swapped in without touching call sites:

- **Video provider interface** — `app/Contracts/VideoProvider.php` (planned). First and only
  implementation for the pilot is Bunny Stream. See [VIDEO.md](VIDEO.md). A `youtube`
  provider value is reserved for free-preview lessons, which bypass the interface entirely
  since they play directly from a public YouTube URL.
- **Payment provider interface** — `app/Contracts/PaymentProvider.php` (planned). First
  implementation is a manual UPI flow. See [PAYMENTS.md](PAYMENTS.md).

Both interfaces are designed so each tenant can eventually plug in their own account/library
under the same provider, or a different provider entirely, without a schema change beyond
storing which provider + credentials a tenant uses.
