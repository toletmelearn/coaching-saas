# Agent Rules

1. Work only on the requested phase.
2. Inspect the existing repository before coding.
3. List planned files before modifying anything.
4. Do not change files outside the approved scope.
5. Do not perform broad refactors.
6. Do not rename models, tables, routes, or directories without approval.
7. Do not change database schema casually.
8. Never bypass tenant scoping.
9. Never accept tenant_id from untrusted request input.
10. Never trust prices from the browser.
11. Never expose private video or document storage publicly.
12. Add tests for every security-sensitive behavior.
13. Run formatting after code changes.
14. Run the relevant test suite.
15. Run static analysis.
16. Report all changed files.
17. Report any assumptions.
18. Report any unresolved issue instead of hiding it.
19. If requirements are ambiguous, stop and ask.
20. If an existing test conflicts with the requested change, do not delete the test automatically.

See TENANCY.md, SECURITY.md, PAYMENTS.md, VIDEO.md and PRIVACY.md for project-specific rules.
