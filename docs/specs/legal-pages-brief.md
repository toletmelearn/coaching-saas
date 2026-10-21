# Legal Pages — Implementation Brief

## Goals

1. Public `/privacy` (both domains) and `/terms` (split by domain) pages served without
   authentication.
2. Notice text lives in versioned files under `resources/legal/notices/`; the file for
   the current `Consent::NOTICE_VERSION` must always exist.
3. Platform Terms (for coaching institutes — central domain only) and Student Terms of
   Use (for students — tenant domains only) are separate pages.
4. Footer link text uses `__('legal.*')` keys.
5. No invented commitments in any page; operator-specific details use
   `[TO BE CONFIRMED BY OPERATOR]` placeholders.

## Routes

```
Central domain only:
  GET /privacy  → LegalController@privacy       → legal.privacy
  GET /terms    → LegalController@platformTerms → legal.platform-terms

Tenant subdomains only (require.tenant, no auth):
  GET /privacy  → LegalController@privacy       → legal.privacy
  GET /terms    → LegalController@studentTerms  → legal.student-terms
```

No route names on either group (central-domain routes would clash on `route:cache`;
tenant routes gain names but are referenced by `url()` throughout, not `route()`).

## Files

```
resources/legal/notices/{NOTICE_VERSION}.md   — versioned notice text (plain prose)
lang/en/legal.php                             — 'nav_privacy', 'nav_terms' keys
app/Http/Controllers/LegalController.php      — privacy(), platformTerms(), studentTerms()
resources/views/legal/privacy.blade.php       — DPDP notice (renders versioned file)
resources/views/legal/platform-terms.blade.php — institute operator terms
resources/views/legal/student-terms.blade.php  — student terms of use
```

## Notice versioning

`Consent::NOTICE_VERSION` is the filename stem, e.g. `v1.0-2026-10-04`. The file
`resources/legal/notices/v1.0-2026-10-04.md` must exist when this constant is set to
that value.

**What existing dev consents hold:** every consent row seeded by `TenantSeeder` and
every consent recorded during development carries `notice_version = 'v1.0-2026-10-04'`.
The file `resources/legal/notices/v1.0-2026-10-04.md` is the authoritative text for
those records — the notice that was (or represents what was) current when those consents
were obtained.

When you release a new notice version:
1. Create `resources/legal/notices/vX.X-YYYY-MM-DD.md` with the new text.
2. Update `Consent::NOTICE_VERSION` to the new stem.
3. The privacy page automatically renders the new file.
4. The old file stays on disk — it remains the reference for any consent row that
   recorded the old version.

## Controller loading

```php
$noticeVersion = Consent::NOTICE_VERSION;
$noticePath    = resource_path('legal/notices/' . $noticeVersion . '.md');
$noticeText    = is_file($noticePath) ? file_get_contents($noticePath) : null;
```

Passing `$noticeText = null` must not crash the view; a missing file shows an in-page
error note rather than a 500. (A test pins that the file exists for the current version,
so null should never reach production.)

## Content rules

### Privacy page (both domains)
- Header: notice version + "Governed by DPDP Act 2023"
- Section: Data Fiduciary / Processor — tenant name (on subdomain) or generic (central)
- Section: Four consent purposes from `lang/en/consents.php` via `__()`
- Section: Other data collected (device/IP, payment records)
- Section: Sub-processors — factual; NO "we never share" guarantee; use placeholder for
  full sub-processor list
- Section: Retention — factual for what is implemented; placeholder for specific periods
- Section: Guardian consent for under-18s
- Section: Rights under DPDP Act (access, erasure, withdrawal, DPBI complaint)
- Section: Contact — placeholder for operator contact details
- Rendered notice file in a clearly labelled collapsible or inline block (the verbatim
  text that `notice_version` refers to)
- Footer crosslink to `/terms`

### Platform Terms (central domain)
All invented specifics become `[TO BE CONFIRMED BY OPERATOR]` placeholders:
- Subscription pricing, billing cycle, trial
- Liability cap amount or formula
- Notice period for term changes
- Specific court jurisdiction

Keep: service description, institute data-fiduciary responsibility, acceptable use,
content ownership, service availability, governing law (India).

### Student Terms of Use (tenant domains)
- Scope: between student and institute (not the platform operator)
- Account security, device limits, conduct, content ownership
- Fee/refund policy: `[TO BE CONFIRMED BY OPERATOR]`
- Contact: the institute
- Cross-link to `/privacy`

## Lang strings — `lang/en/legal.php`

```php
return [
    'nav_privacy' => 'Privacy notice',
    'nav_terms'   => 'Terms',
];
```

Used in both footers: `__('legal.nav_privacy')`, `__('legal.nav_terms')`.

## Tests — `tests/Feature/Legal/LegalPagesTest.php`

- The notice file for `Consent::NOTICE_VERSION` exists on disk
- `/privacy` returns 200 on central domain (unauthenticated)
- `/terms` returns 200 on central domain → shows platform terms title
- `/privacy` on tenant subdomain returns 200, shows tenant name and notice version
- `/terms` on tenant subdomain returns 200 → shows student terms title
- Pages do NOT contain `[TO BE CONFIRMED BY OPERATOR]` literally in their HTTP output
  (placeholders are for the .md file and Blade source, not for end-user rendering)
  — SKIP this check; placeholders are intentionally visible until the operator fills them

## `[TO BE CONFIRMED BY OPERATOR]` items

See PRIVACY.md §"Operator confirmations required before go-live" for the full list.
