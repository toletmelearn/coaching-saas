# Phase 8 Brief — One Device per Student

Read CLAUDE.md, AGENT_RULES.md, TENANCY.md, SECURITY.md, docs/specs/phase-3-tenant-auth.md, docs/specs/phase-6-branding-pwa.md and docs/specs/phase-7-progress.md first. Phases 2–7 are locked: do not modify TenantScope, TenantBuilder, BelongsToTenant, TenantUserProvider, LessonAccess rules, or the existing auth middleware (EnsureActiveTenantUser, RedirectIfMustChangePassword, RequireTenant) unless a test proves a bug — stop and report first. Work in THIS session, no background agents. Never commit the archive-42JxzH folder. Never use saveQuietly()/withoutEvents() on tenant-owned models.

## Goal

A student account can be used on a limited number of devices at a time (default one), so a paid login cannot be shared with a whole class. Owners can see and control it.

Out of scope: IP/location tracking, device fingerprinting beyond one cookie, push notifications, automatic account suspension, SMS/OTP login, limits for owner/staff accounts.

## Product decisions (already made)

1. **Limit applies to role `student` only.** Owner and staff are never limited or tracked as devices.
2. **Devices per student:** `tenants.max_devices_per_student` (1–3, default 1), editable by the owner on the Settings page.
3. **Newest login wins.** Logging in on a new device never fails; if the student is now over the limit, the OLDEST active device(s) are revoked. The revoked device sees a plain message on its next request: "You were signed out because your account was opened on another device."
4. **Device identity = a long-lived random cookie** (`device_id`, 32 hex chars from random_bytes, HttpOnly, SameSite=Lax, Secure when SESSION_SECURE_COOKIE, 1 year, host-only so it never crosses institutes). A new session on the SAME device (session expiry, remember-me restore) reuses the same device record and never evicts itself. Clearing cookies looks like a new device (acceptable).
5. **Privacy:** store only a short label ("Chrome on Android"), the truncated user-agent (255 chars) and first/last seen times. Never store IP addresses.
6. **Owner tools:** on the People page each student shows their active device count and last active; a per-student Devices page lists devices with "Sign out this device" and "Sign out all devices". Owner and staff may use them for students only.
7. **Anything that changes credentials revokes sessions:** owner/staff password reset → revoke ALL of that student's devices (reason password_reset); a student changing their own password → revoke all OTHER devices (keep the current one). Disabling a student already blocks them (existing middleware) — additionally revoke all devices.
8. **Abuse signal, not punishment:** the Devices page shows "Switching devices often" when there were ≥ 5 replacements in the last 7 days (`config('coaching.device_switch_flag')`, default 5). Nothing is blocked automatically.
9. **Session lifetime:** the default idle lifetime for the app becomes 30 days (`SESSION_LIFETIME=43200` in .env.example and config default), so students are not logged out every two hours. Sessions stay as they are otherwise (driver-agnostic — do NOT rely on the `sessions` table: its user_id is written for the default guard only and has no tenant_id).
10. **Known limit, documented in SECURITY.md:** a Bunny embed URL issued before a revocation stays valid until its expiry (max 10 minutes); the local `fake` stream re-checks access on every request so it stops at once. Ping-pong between two people sharing one login is possible by design; the owner sees the flag.

## Schema (tenant-owned: tenant_id, BelongsToTenant, tenantKeys(), composite FKs; new migrations only)

```
user_devices
- id
- tenant_id
- user_id                composite FK → users, cascadeOnDelete
- device_id              string(64)
- label                  string(80)
- user_agent             string(255) nullable
- first_seen_at          timestamp
- last_seen_at           timestamp
- revoked_at             timestamp nullable
- revoked_reason         string(20) nullable   enum DeviceRevocationReason {replaced, owner, password_changed, password_reset, disabled, logout}
- notified_at            timestamp nullable    set once the revoked device has been shown the message
- timestamps
unique(tenant_id, user_id, device_id)
index(tenant_id, user_id, revoked_at)
```
`tenants.max_devices_per_student` tinyint unsigned default 1. All guarded fields (tenant_id, user_id, device_id, revoked_*, notified_at, first/last seen) are never mass-assignable; the settings save uses explicit validated fields + forceFill as in Phase 6.

## Behaviour

**Registration (listener on Illuminate\Auth\Events\Login for the `tenant` guard; do NOT edit the login controller):** for a student — read the `device_id` cookie or mint one and queue it on the response; upsert the (tenant, user, device) row (label + user agent, last_seen_at = now, clear revoked_at); then, in one transaction with row locks on that student's device rows, count active (revoked_at null) devices; while count > limit, revoke the oldest by last_seen_at (never the current one) with reason `replaced`. Concurrent logins must not leave the student with more than the limit or with zero devices.

**Enforcement (new route middleware alias `device.limit`, added wherever `active.tenant.user` is applied today):** for an authenticated student — resolve the device by cookie; if there is no cookie/row (a session that pre-dates this phase) register it as above; if the row is revoked → log the student out (guard logout, session invalidate, token regenerate), then HTML: redirect to `/login` with the translated message flashed and `notified_at` set; JSON: 401 with `{ "code": "device_revoked" }` (the Phase 7 progress tracker already stops on 401). Update `last_seen_at` at most once per 60 seconds per device (no write on every request). At most 2 extra queries per authenticated student request. Owner/staff and guests pass through untouched.

**A revoked device that is no longer authenticated** (session gone) but still holds its cookie: the login page shows the message once (when its device row is revoked with reason replaced/owner/password_* and notified_at is null), then marks it notified.

**Logout:** a normal logout marks that device revoked with reason `logout` (frees the slot); logging in again on it reactivates the row.

**Guardrail against drift:** a test enumerates every route whose middleware includes `active.tenant.user` and asserts it also has `device.limit` (so a future route cannot forget it). The Phase 7 heartbeat/completion endpoints, the lesson pages, attachments and the fake video stream must all be covered.

**Label parser:** small internal class (no third-party service, no network) mapping common user agents to "Browser on OS": Chrome/Edge/Firefox/Safari/Samsung Internet/Opera × Android/iOS/Windows/macOS/Linux, plus "Unknown device" fallback. Table-driven tests.

**Maintenance:** `php artisan devices:prune` deletes rows not seen for 60 days; scheduled daily.

## UI (Blade + shared components, 360px, every string via `__()`, teacher-friendly)

- Settings: "Devices per student" select (1, 2, 3) with one line of help text.
- People page: per-student "Devices: 1 · Last active 3 h ago" (students only), link to the Devices page.
- `/users/{user}/devices` (owner/staff, students only): table of devices (label, first seen, last seen, status Active/Signed out + reason), buttons "Sign out this device" and "Sign out all devices" with @js confirms; the "Switching devices often" notice when applicable. Staff may view/act for students only; students and guests get the friendly 403.
- Login page: the "You were signed out because your account was opened on another device." notice (once).
- Student-facing: no new pages. (A "my devices" screen can come later.)

## Required tests (positive controls in every negative test; real tenant hosts; use freshRequestCycle() between requests where the second request must re-resolve auth)

Tenancy/schema
- unique per (tenant, user, device); composite FKs reject another tenant's user; user id 1 in tenant A and tenant B never interfere; guarded fields not mass-assignable; max_devices_per_student validation (1–3) and not settable via any other request.

Registration and limits
- Login mints a device cookie with the right attributes (HttpOnly, SameSite=Lax, ~1 year; Secure follows config) and creates one active row.
- Limit 1: second device login revokes the first (reason replaced); limit 2: third login revokes the OLDEST; limit 3 likewise; the current device is never the one revoked.
- Same device logging in again (new session, or remember-me restore) reuses its row and evicts nothing.
- Owner/staff logins create no device rows and are never limited.
- Concurrency: interleaved registrations never exceed the limit or leave zero active devices.

Enforcement
- Revoked device: next HTML request → logged out, redirected to /login with the message; next JSON request → 401 device_revoked; a still-valid device is unaffected (positive control) — cover lesson page, progress heartbeat, attachment download and the fake video stream.
- Pre-existing session with no cookie gets registered on its next request.
- last_seen_at throttled to once per minute; query-count test (≤ 2 extra queries).
- Route drift test (every active.tenant.user route also has device.limit).
- Message shown once on the login page for a revoked-but-not-authenticated device.

Credential events
- Owner/staff password reset revokes all that student's devices; student password change revokes all others but keeps the current one; disabling a student revokes all.

Owner tools
- People page counts; Devices page lists correctly; sign out one/all works and takes effect on the student's next request; permissions matrix (owner ok, staff ok for students, staff cannot view owner/staff, student 403, guest redirect, another tenant's user 404 — with positive controls); "Switching devices often" appears at 5 replacements in 7 days and not at 4.

Privacy and cleanup
- No IP address stored anywhere (assert columns and stored values); user agent truncated to 255.
- devices:prune removes only rows older than 60 days.
- Label parser table for the listed browsers/OSes plus the fallback.

## Process

Step 1: tests only, run them, paste real output. Target: 0 Phase 8 tests passing (positive controls so nothing passes by accident); every earlier test still passing; name the missing piece per group; verify the arithmetic (new tests = total now − previous total; failing + erroring must equal it) and state it. Add new test folders to phpunit.mysql.xml as <directory> entries. Commit "Phase 8: tests (Step 1)". STOP for review.

Step 2 (after approval): implement until all tests pass on BOTH suites plus `composer test:js`. Tests are the contract: do not edit an existing test without asking; if one seems wrong STOP and list every such test together with a proposed fix. Run npm run build, php artisan route:cache then route:clear.

## Report

Raw final lines of composer test / test:mysql / test:js / lint / analyse; `git diff --stat tests/` against the Step 1 commit with each change explained (before/after per changed test); assumptions; unresolved risks. Spec to docs/specs/phase-8-devices.md; update README, SECURITY.md (device model, no IP stored, the two known limits above) and DEPLOY.md if anything changes (note SESSION_LIFETIME). Commit "Phase 8: one device per student", push, tag phase-8.
