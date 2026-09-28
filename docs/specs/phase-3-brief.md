# Phase 3 Brief — Tenant Users and Authentication

Read CLAUDE.md, AGENT_RULES.md (both sections), TENANCY.md and SECURITY.md first. Phase 2 is locked. Do NOT modify TenantScope, TenantBuilder or BelongsToTenant unless a test proves a bug — and if so, stop and report before changing them.

## Product decisions (already made)

- No self-registration. The owner (teacher) creates staff and student accounts and hands out credentials. This matches the pilot: the teacher issues logins to his students.
- Login identifier: mobile number OR email, plus password. Many students (14–18) do not use email. Both columns nullable; at least one required (validated in the action and enforced by a test).
- First login: accounts created by the owner get must_change_password = true; the user is forced to set a new password before anything else.
- Password reset: owner/staff-initiated reset only in this phase (owner sets a temporary password → must_change_password = true). NO self-service email reset yet — Laravel's default password_reset_tokens table is keyed by email only, which collides across tenants. Self-service reset is deferred and must use a tenant-aware token store when built.
- No email verification in this phase.
- Language: all UI strings through __() with lang/en/*.php files from the first screen, so Hindi can be added later without touching views.

## Schema

Modify the default users migration (fresh project, nothing in production yet):

```
users
- id
- tenant_id            FK tenants, restrictOnDelete   (via tenant conventions)
- name
- email                nullable, stored lowercase
- phone                nullable, stored as 10-digit Indian mobile (normalise +91 / 0 / spaces)
- password
- role                 string, backed by enum UserRole {owner, staff, student}
- status               string, backed by enum UserStatus {active, disabled}
- must_change_password bool default false
- last_login_at        nullable timestamp
- remember_token
- timestamps

unique(tenant_id, email)
unique(tenant_id, phone)
$table->tenantKeys()   // unique(tenant_id, id) for future child tables
```

- Remove the global unique index on email.
- Drop or leave unused the default password_reset_tokens table, and document why in SECURITY.md.
- User uses BelongsToTenant. role, status, tenant_id, must_change_password are NOT mass-assignable.
- UserFactory must NOT set tenant_id (the trait fills it from context).

## Authentication behaviour

- Tenant login happens only on tenant domains (routes behind require.tenant).
- Session lookup of the logged-in user goes through the tenant scope, so a session carrying a user id from Tenant A resolves to guest on Tenant B. If no tenant is in context (central domain), the tenant web guard must return guest, NOT throw — implement a small TenantUserProvider (or equivalent) that returns null when TenantContext is empty, and delegates to the scoped query otherwise. Also covers remember-me token lookup.
- Disabled users cannot log in, and an already-logged-in user who becomes disabled is logged out on the next request (middleware).
- Session is regenerated on login; invalidated and token regenerated on logout.
- Login rate limit keyed by tenant id + normalised identifier + IP (e.g. 5 attempts/minute). Error message never reveals whether the identifier exists.
- must_change_password middleware: redirects to the change-password page until done.
- Platform admin: separate platform_admin guard (already configured). Add login/logout routes on central domains only. A platform admin session must not authenticate on tenant domains, and a tenant user must never pass the platform_admin guard.
- Update last_login_at on successful login.

## Authorization foundations

- UserRole enum with helper methods (e.g. canManageCourses(), canManageBilling()).
- A UserPolicy: owner manages all users in the tenant; staff can create/manage students only; students manage only themselves. All checks rely on the tenant scope + policy, never on request input.
- Owner can create staff/student accounts and reset their passwords (simple Blade screens).
- Owner cannot be demoted or disabled by staff; the last owner of a tenant cannot be disabled or demoted.

## UI (first real screens — Blade + Tailwind, mobile-first, no JS framework)

- Tenant login page: tenant name at top, identifier + password, "remember me", clear errors.
- Change-password page (forced on first login).
- Minimal owner "Users" page: list (paginated), create user, reset password, disable/enable.
- Minimal post-login landing pages per role (placeholders: "Owner dashboard", "Student home").
- Platform admin login page on the central domain.
- Must work on a 360px-wide phone screen. Large tap targets. Every string via __().
- No visual design system yet — keep it plain and consistent; a design pass comes later.

## Seeder

Demo tenant (demo.coaching.test) gets one owner and one student with known local-only passwords, documented in README (local development only, never in production seeders).

## Process — TESTS FIRST

Step 1: write the Pest tests only, run them, confirm they fail for the right reason. No implementation code.

Step 2 (after approval): implement until all tests pass on BOTH suites.

## Required tests (at minimum)

- same email can exist in two tenants; same phone can exist in two tenants
- duplicate email or phone within one tenant is rejected
- a user must have at least one of email/phone
- phone normalisation (+91 98765 43210, 098765 43210, 9876543210 → same value)
- login with email works; login with phone works (on the correct tenant domain)
- Tenant A user cannot log in on Tenant B's domain (same identifier + password)
- session replay: log in on Tenant A, then request Tenant B with the same session → guest
- central domain with a tenant user id in session → guest, no exception
- disabled user cannot log in; disabling a logged-in user logs them out on next request
- session id changes on login; logout invalidates the session
- rate limiting kicks in after 5 failed attempts and is per tenant + identifier
- error message is identical for unknown identifier and wrong password
- must_change_password forces redirect until the password is changed
- tenant user cannot access platform-admin routes; platform admin cannot access tenant user routes or see tenant user lists
- mass assignment: role/status/tenant_id cannot be set from request input
- staff can create students but not staff/owners; student cannot create users
- owner cannot see or manage another tenant's users (list, edit, route binding → 404)
- the last owner cannot be disabled or demoted
- every tenant-facing page renders all text via translation keys (at least: login page has no hard-coded English outside lang files — check one representative view)

## Report

Files changed, raw final lines of composer test / test:mysql / lint / analyse, assumptions, unresolved risks. Write the spec to docs/specs/phase-3-tenant-auth.md. Commit as "Phase 3: tenant users and authentication", then git push.

## Decisions made during Step 1

- A. Staff posting role=owner or role=staff to POST /users → 403, no user created. Silent stripping applies only to tenant_id, status, must_change_password. role is a legitimate form field, validated against the policy and set explicitly (forceFill) in the create action.
- B. User model has $attributes defaults: role=student, status=active.
- C. "At least one of email/phone" is enforced twice: validation in the create action (errors on both fields) and a database constraint.
- D. Owner/staff creating a user does not send a password. The system generates a readable temporary password, flashes it once under the session key temporary_password, and sets must_change_password = true.
- Every negative/security test includes a positive control in the same test.
- Tests are the contract: the implementer may not edit a test without approval.

## Decisions made during Step 2

- Test helper freshRequestCycle() (app('auth')->forgetGuards()) simulates a new production request between two requests in one test, keeping session data. Session-isolation tests keep the positive control and the negative assertion in the same test.
- Owner can re-enable a disabled user.
- Platform admin login is rate-limited: normalised email + IP, 5 per minute.
- /admin routes are restricted to central domains with Route::domain().
- TenantContext is reset at the start and end of every request; code that runs after the response (afterResponse jobs, terminable middleware) has no tenant context and must carry tenant_id explicitly.
