# Legal Pages — Implementation Brief (real)

## Goals

1. `config/privacy.php` carries the authoritative data inventory; the privacy page renders
   the itemised table from it. A guard test pins every purpose in the config against
   `lang/en/consents.php`.
2. ALL notice prose lives in versioned files under `resources/legal/notices/`. No legal
   text in Blade. Blade is structural only (title, table shell, lang-string labels,
   interactive elements).
3. Create a new notice version dated 2026-10-21. The v1.0 file stays; it is NOT
   described as the text dev consents agreed to (dev consents predate any notice).
4. Old versions render at stable URLs: `/privacy/{version}`.
5. English + Hindi files per version. Hindi marked as draft in PRIVACY.md pending legal
   review. Lang selector (`?lang=hi` / `?lang=en`) on the privacy page.
6. A "Withdraw or manage consent" section on the privacy page (tenant domain), with a
   dedicated `/consents/withdraw` page that explains the process.
7. YouTube embeds must use `youtube-nocookie.com`. **Finding: all embeds already use
   `youtube-nocookie.com` — no change required.**
8. DPBI wording: do not say "once operational". State the formal complaint mechanism
   exists and mark the exact URL/procedure as `[TO BE CONFIRMED BY OPERATOR]`.

## Routes

```
Central domain:
  GET /privacy              → LegalController@privacy       → legal.privacy
  GET /privacy/{version}    → LegalController@privacyArchive → legal.privacy-archive
  GET /terms                → LegalController@platformTerms → legal.platform-terms

Tenant subdomains (require.tenant, no auth):
  GET /privacy              → LegalController@privacy       → legal.privacy
  GET /privacy/{version}    → LegalController@privacyArchive → legal.privacy-archive
  GET /terms                → LegalController@studentTerms  → legal.student-terms
  GET /consents/withdraw    → LegalController@consentWithdrawInfo → legal.consent-withdraw
```

`{version}` must match `v\d+\.\d+-\d{4}-\d{2}-\d{2}`. 404 for unrecognised versions.
No `->name()` in the central-domain loop (route:cache clash). Named routes are fine in
the tenant group.

## Files

```
config/privacy.php                              — data inventory (categories, purposes, basis, retention)
resources/legal/notices/{VERSION}.md            — English notice text (plain prose)
resources/legal/notices/{VERSION}.hi.md         — Hindi draft notice text
lang/en/legal.php                               — UI strings (nav, table headers, CTA labels)
app/Http/Controllers/LegalController.php
resources/views/legal/privacy.blade.php         — dynamic shell (data table + rendered file)
resources/views/legal/privacy-archive.blade.php — raw versioned-file renderer for /privacy/{version}
resources/views/legal/platform-terms.blade.php
resources/views/legal/student-terms.blade.php
resources/views/legal/consent-withdraw.blade.php
```

## config/privacy.php — data inventory

Keys: `data_categories` — keyed by slug, each entry:
- `fields`: `string[]` — field names stored
- `purposes`: `string[]` — subset of the four consent purposes (must match lang/en/consents.php)
- `legal_basis`: `consent` | `contract` | `legitimate_interest` | `legal_obligation`
- `retention`: `string|null` — null means `[TO BE CONFIRMED BY OPERATOR]`

Guard test: every purpose in the config, deduplicated, sorted, must equal the sorted
keys of `trans('consents.purposes')`.

## Notice versioning

File stems: `v{major}.{minor}-{YYYY}-{MM}-{DD}`. Language variant: `{stem}.hi.md` for Hindi.

`Consent::NOTICE_VERSION` must match a `.md` file that exists. A test pins this.

**Dev consents (seeded or recorded during development):** carry `notice_version =
v1.0-2026-10-04`. That file is an INITIAL DRAFT created for the implementation —
dev consents predate any real notice being shown to a real guardian. See PRIVACY.md.

## Content rules — Blade vs. versioned files

### What Blade may contain
- Page title, version metadata, date, governing-law label
- Data Fiduciary / Processor identification (uses dynamic tenant name)
- Data inventory table (rendered from `config/privacy.php`)
- Lang selector (`?lang=hi` / `?lang=en`)
- Withdraw-consent CTA and `/consents/withdraw` link
- Footer crosslinks
- Lang-string labels (`__('legal.*')`)

### What must be in the versioned .md file (NOT Blade)
- All explanatory prose: sub-processors, retention, guardian-consent explanation,
  rights descriptions, DPBI complaint path, contact, updates-to-notice text

## DPBI wording

Do NOT say "once operational". Use:
> "To make a complaint to the Data Protection Board of India (DPBI), follow the
> formal complaint procedure. [TO BE CONFIRMED BY OPERATOR: DPBI complaint portal URL,
> exact procedure, and any applicable timelines under DPDP Rules 2025]"

## Lang strings — `lang/en/legal.php`

```php
'nav_privacy'    => 'Privacy notice',
'nav_terms'      => 'Terms',
// data inventory table
'data_inv_title' => 'What we collect',
'col_category'   => 'Category',
'col_fields'     => 'Fields stored',
'col_purposes'   => 'Purposes',
'col_retention'  => 'Retention',
'retention_tbco' => '[To be confirmed]',
'data_cat' => [
    'identity'         => 'Identity and contact',
    'guardian'         => 'Guardian (under-18 students)',
    'session_device'   => 'Session and device',
    'learning_progress'=> 'Learning progress',
    'payment'          => 'Payment records',
    'live_class'       => 'Live class attendance',
],
// notice text block
'notice_text_heading' => 'Full consent notice — :version',
'notice_file_missing' => 'Notice file not found for version :version.',
// lang toggle
'lang_en' => 'English',
'lang_hi' => 'हिंदी (draft)',
// withdraw consent
'withdraw_heading'     => 'Withdraw or manage consent',
'withdraw_cta'         => 'How to withdraw consent',
'withdraw_page_title'  => 'Consent withdrawal',
// archive
'archive_banner' => 'This is an archived version (:version). :link.',
'archive_link'   => 'View current notice',
```

## Tests

- Guard: config/privacy.php purposes == lang/en/consents.php purpose keys
- Notice file exists for current NOTICE_VERSION (English + Hindi)
- /privacy 200 central domain; /terms 200 central domain → platform terms
- /privacy 200 tenant subdomain; shows tenant name; shows notice version
- /terms 200 tenant subdomain → student terms
- /privacy/{version} 200 for v1.0-2026-10-04 (old version)
- /privacy/v0.0-1900-01-01 → 404
- /consents/withdraw 200 on tenant subdomain
- Platform terms ↔ student terms do not bleed across domain types

## Schema changes

None. All changes are config, lang, views, routes, and files.
