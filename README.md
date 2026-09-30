# Coaching-SaaS

Multi-tenant SaaS for coaching institutes, built on Laravel 13 / PHP 8.3. Single codebase,
single database, tenant isolation enforced at the schema and application layer. See
[ARCHITECTURE.md](ARCHITECTURE.md), [TENANCY.md](TENANCY.md), [SECURITY.md](SECURITY.md),
[VIDEO.md](VIDEO.md), [PAYMENTS.md](PAYMENTS.md), [PRIVACY.md](PRIVACY.md) and
[ROADMAP.md](ROADMAP.md) for the rest of the design.

## Local setup (Windows / XAMPP)

Prerequisites: XAMPP with PHP 8.3, MySQL/MariaDB, and Apache; Composer; Node.js 18+.

1. Clone the repo into `C:\xampp\htdocs\coaching-saas`.
2. `composer install`
3. `copy .env.example .env` then `php artisan key:generate`
4. Create two MySQL databases: `coaching_saas` (app) and `coaching_saas_test` (MySQL test
   suite). Both use `root` with no password by default in XAMPP — adjust `.env` if yours
   differs.
5. `php artisan migrate`
6. `npm install && npm run build` (or `npm run dev` while working on frontend)

Protected lesson video works locally with **no external service and no `.env` keys** —
`VIDEO_DRIVER` defaults to `fake` (private-disk storage + a signed Laravel stream route) and
is the only supported driver in local/testing; `VIDEO_DRIVER=fake` is refused by
`php artisan app:preflight` in production, so never set it there. See
[docs/specs/phase-5-video.md](docs/specs/phase-5-video.md) and
[docs/DEPLOY.md](docs/DEPLOY.md) for the `bunny` driver's production setup.

**Testing an actual video upload locally**, rather than just the app's own test suite,
needs XAMPP's PHP to allow a file that size through *before* Laravel ever sees it. Edit
`C:\xampp\php\php.ini` (the CLI/Apache one XAMPP's control panel points at) and raise both
`upload_max_filesize` and `post_max_size` (the latter must be **larger** than the former —
PHP counts the whole multipart body, not just the file) to comfortably above
`COACHING_MAX_VIDEO_MB` (default 2048), e.g. `upload_max_filesize = 2100M` and
`post_max_size = 2200M`, then restart Apache from the XAMPP control panel. Left at PHP's
tiny defaults (2M/8M), PHP silently discards the upload before `$_FILES` is even
populated — the app detects this specific case (`Content-Length` above the configured
limit but no file present) and reports it as "file too large" rather than a generic
error, but the upload still won't go through until `php.ini` is raised.

### Hosts file (subdomain tenants)

Tenants are resolved by subdomain (see [TENANCY.md](TENANCY.md)), so add entries to
`C:\Windows\System32\drivers\etc\hosts` (edit as Administrator) for every tenant you test
locally, plus the central/platform host:

```
127.0.0.1 coaching.test
127.0.0.1 platform.coaching.test
127.0.0.1 friend.coaching.test
```

Add one line per tenant subdomain you need. Wildcard entries are not supported by the
Windows hosts file, so each subdomain needs its own line.

### Apache vhost

Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apacheconf
<VirtualHost *:80>
    ServerName coaching.test
    ServerAlias *.coaching.test
    DocumentRoot "C:/xampp/htdocs/coaching-saas/public"

    <Directory "C:/xampp/htdocs/coaching-saas/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Restart Apache after editing. Visit `http://coaching.test` or any tenant subdomain added to
the hosts file.

### Local credentials

`php artisan migrate:fresh --seed` creates a demo tenant with ready-to-use owner and student
accounts:

| Field           | Owner                           | Student                           |
|-----------------|----------------------------------|------------------------------------|
| Tenant domain   | `demo.coaching.test` (add it to your hosts file, see above) | same |
| Email           | `owner@demo.coaching.test`      | `student@demo.coaching.test`      |
| Password        | `password`                      | `password`                        |

Log in at `http://demo.coaching.test/login`. Neither seeded account requires a password
change on first login (unlike a user created through the app's "add person" flow, which
always generates a temporary password and forces a change — see
[docs/specs/phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md)).

**These demo credentials are local development only.** `TenantSeeder` only creates the demo
owner/student when `APP_ENV` is `local` or `testing`; in any other environment (including
`production`) it seeds the tenant/domain but skips the users entirely and prints a console
warning instead. Never add real password seeding to a production deploy.

The same seeder also creates demo course content (only in local/testing, guarded the same
way): a **published** course "Physics – Class 11" with 2 chapters and 4 lessons — one
free-preview lesson with a YouTube video, one draft lesson, and two paid lessons each with a
sample PDF note — plus the demo student enrolled in it with no end date. The free-preview
lesson's `youtube_video_id` is seeded as the neutral placeholder `xxxxxxxxxxx`, which does
**not** resolve to a real YouTube video — swap in a real video id (via `/manage/courses` in
the app, or by editing the seeded row) before demoing the free-preview player to anyone. A
second course,
"Chemistry – Class 11", is seeded as a **draft** (visible only to the owner/staff, useful for
testing the "draft course is invisible" behavior). See
[docs/specs/phase-4-courses.md](docs/specs/phase-4-courses.md).

### PWA testing locally

Service workers only register in a "secure context" — HTTPS, or `localhost`/`127.0.0.1`.
`http://demo.coaching.test:8000` is **not** a secure context, so `navigator.serviceWorker
.register()` silently fails there. The seeder also registers a second domain,
**`demo.localhost`**, for exactly this reason — Chrome treats any `*.localhost` hostname as
secure and resolves it to `127.0.0.1` automatically, with no hosts file entry needed:

```
php artisan serve
```

then open **`http://demo.localhost:8000`** and log in as the demo owner/student above. This is
where to test the manifest, install prompt, and offline page (`docs/specs/phase-6-branding-pwa.md`).

If you'd rather use `demo.coaching.test`, Chrome can be told to treat it as secure anyway:
visit `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add
`http://demo.coaching.test:8000`, and relaunch the browser. This is a local-only workaround —
never rely on it for anything other than your own dev machine.

**Installing on a real phone needs HTTPS.** Neither `demo.localhost` nor the Chrome flag
above exist on a phone; the PWA is only actually installable from a deployed, HTTPS-served
tenant domain (see [DEPLOY.md](docs/DEPLOY.md)). `php artisan app:preflight` fails in
production if the `demo.localhost` domain exists — it must never be created outside
local/testing.

`php artisan migrate:fresh --seed` also creates a **local-only** platform admin account
(same local/testing-only guard as the demo tenant above — never seeded in production, and
`php artisan app:preflight` fails in production if this account exists):

| Field    | Value                       |
|----------|------------------------------|
| Email    | `admin@coaching.test`       |
| Password | `password`                  |

Log in at `http://coaching.test/admin/login`. To create additional/production platform
admins, use `php artisan platform-admin:create` (interactive, prompts for name/email/
password) — never seed one outside local/testing.

Once you've created a tenant or two, `php artisan local:hosts` (local/testing only) prints
a `127.0.0.1 <domain>` line for every tenant domain plus the central domains, ready to paste
into `C:\Windows\System32\drivers\etc\hosts` instead of typing them by hand.

## Commands

| Command                 | Purpose                                                          |
|--------------------------|-------------------------------------------------------------------|
| `composer test`          | Run the full Pest suite against an in-memory SQLite database.     |
| `composer test:mysql`    | Run `tests/Feature/Tenancy` against the `coaching_saas_test` MySQL database (set `DB_TEST_*` in `.env`). |
| `composer lint`          | Format code with Laravel Pint.                                    |
| `composer analyse`       | Static analysis with Larastan (PHPStan) at level 5.                |

## Testing philosophy

Most tests run against in-memory SQLite for speed. Anything that depends on MySQL-specific
behavior — composite foreign keys, collation, or the tenancy isolation guarantees in
[TENANCY.md](TENANCY.md) — belongs in `tests/Feature/Tenancy` and must also pass under
`composer test:mysql`, since SQLite does not enforce composite foreign keys the same way
MySQL does.
