# Coaching-SaaS — Instructions for Claude

Before doing any work in this repository, read:

1. [AGENT_RULES.md](AGENT_RULES.md) — the 20 global rules for working on this codebase.
2. [TENANCY.md](TENANCY.md) — how tenant isolation is resolved and enforced.
3. [SECURITY.md](SECURITY.md) — session, storage, and credential handling rules.

These are not optional background reading — they encode invariants (fail-closed tenant
resolution, composite foreign keys, session-per-subdomain, signed URLs for private storage)
that later phases depend on holding. A change that violates one of them is a bug even if it
passes tests that don't happen to check for it.

See also [ARCHITECTURE.md](ARCHITECTURE.md), [VIDEO.md](VIDEO.md),
[PAYMENTS.md](PAYMENTS.md), [LIVE_CLASSES.md](LIVE_CLASSES.md), [PRIVACY.md](PRIVACY.md),
and [ROADMAP.md](ROADMAP.md) for the rest of the system design.

## Never bypass model events on tenant-owned models

Never use `WithoutModelEvents`, `saveQuietly()`, `withoutEvents()`, or `Model::withoutEvents()`
on tenant-owned models (or on any seeder/command that touches them) — not even for
performance, and not even "just for seeding". Data invariants this app depends on are
enforced in `creating`/`saving` model event hooks, not in the database alone: slug
auto-generation (`Course`), position auto-fill (`Chapter`, `Lesson`, `LessonAttachment`),
`tenant_id` auto-fill (`BelongsToTenant`), and the lesson/chapter course-match guard
(`Lesson`). Bypassing events skips all of them silently — see Phase 4.2, where
`DatabaseSeeder`'s `WithoutModelEvents` trait made `php artisan migrate:fresh --seed` fail
outright (`courses.slug` had no value to insert) because the seeder never went through the
hook that generates one.

## Correction Passes

For iterative corrections and small focused changes (e.g. "Phase 2 correction pass #4"), do not use
plan mode or spawn subagents unless explicitly asked. Edit directly, test, commit, and report results.
Plan mode and agents are for exploratory or complex tasks; correction passes are tactical refinements
that benefit from direct execution.
