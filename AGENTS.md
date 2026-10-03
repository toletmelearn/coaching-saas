# Agent guidance

This project's binding rules for AI agents live in **`AGENT_RULES.md`** (20 process
rules + 21 project invariants).

Before making any change, read — in this order:

1. `AGENT_RULES.md` — the contract
2. `CLAUDE.md` — model-event / seeding rules
3. `TENANCY.md` — how tenant isolation actually works
4. `SECURITY.md` — session, signed URLs, secrets, rate limits
5. `PROJECT_BRIEF.md` — verified state of the repo, drift ledger, canary tests

Phase numbering is authoritative in `docs/specs/*` and git history, not `ROADMAP.md`.

The Laravel Boost bootstrap that used to live in this file is no longer installed. If you
want it, `composer require laravel/boost --dev && php artisan boost:install` — but it will
overwrite this file with generic guidance again, so prefer leaving this pointer in place.
