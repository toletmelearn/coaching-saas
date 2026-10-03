# Deploying to production — Hostinger VPS + CloudPanel + Cloudflare

Target stack: Ubuntu VPS (Hostinger), CloudPanel for server/PHP/Nginx management, MySQL 8,
Cloudflare in front (proxied DNS, wildcard `*.PLATFORM_DOMAIN`).

This is a runbook, not a script — read it once before running anything, since a couple of
steps (marked **VERIFY** below) need confirming against your actual CloudPanel version and
Cloudflare zone before you commit to them.

---

## 0. CloudPanel and wildcard domains — read this first

**CloudPanel's site UI does not support wildcard domains.** Checked directly against
CloudPanel's own docs and community feature-request tracker before writing this runbook:

- The official "Add Domain" flow (CloudPanel CE docs, Frontend Area → Domains) only
  documents entering a literal `Domain Name` (e.g. `example.com`, `www.example.com`) — there
  is no wildcard (`*.example.com`) syntax anywhere in that documentation.
- A "Wildcard domains support" feature request exists on CloudPanel's public feature-request
  board, which is itself evidence the capability isn't built in (open requests are for
  missing features).

**Consequence:** you cannot create a single CloudPanel "site" for `*.PLATFORM_DOMAIN` and
have every tenant subdomain route through it automatically via the panel UI. The fallback
below is what this runbook uses instead — **VERIFY** the exact file paths against your
CloudPanel version once you're on the server (CloudPanel's Nginx vhost layout has changed
between major versions), but the approach itself is standard Nginx and will work regardless:

1. Create **one** CloudPanel site for the bare `PLATFORM_DOMAIN` (e.g. `mycoaching.app`) as
   a normal PHP site, pointed at this app's `public/` directory. This gives you the
   PHP-FPM pool, document root, and a CloudPanel-managed vhost file you should otherwise
   leave alone.
2. Add a **separate**, hand-written Nginx server block (a new file under CloudPanel's Nginx
   include directory — typically `/etc/nginx/sites-enabled/` or
   `/home/<site-user>/htdocs/<domain>/conf/` depending on CloudPanel version; **VERIFY**)
   that:
   - Listens on 443 (and 80 → redirect) for `server_name *.PLATFORM_DOMAIN;`
   - Uses the **same** `root` (this app's `public/`) and the **same** PHP-FPM socket as the
     CloudPanel-managed site from step 1 (copy the `fastcgi_pass` value from that site's
     generated vhost).
   - Uses the **same** TLS certificate as step 3 below (it covers the wildcard, so one
     cert file serves both the bare domain and every subdomain).
   Because this app resolves the tenant from the hostname **in the application layer**
   (`ResolveTenant` middleware looks up the host in `tenant_domains`, not a per-subdomain
   webserver config — see TENANCY.md), a single wildcard server block is sufficient; you do
   **not** need one Nginx site per tenant.
3. Reload Nginx (`nginx -t && systemctl reload nginx`) after adding the file. Re-check this
   file exists and is included after any CloudPanel update — CloudPanel manages its own
   vhost files, not this one, so it should survive panel updates, but confirm after any
   major CloudPanel version upgrade.

## 1. Cloudflare

1. **DNS** (orange-clouded / proxied, not grey/DNS-only):
   - `A` record: `@` → your server's IP
   - `A` record: `*` → your server's IP
2. **SSL/TLS mode: Full (strict)** — Cloudflare must verify your origin's certificate, not
   just encrypt-and-trust-anything ("Full") or terminate-unencrypted ("Flexible").
3. **Origin Certificate:** Cloudflare dashboard → SSL/TLS → Origin Server → Create
   Certificate. Hostnames: `PLATFORM_DOMAIN` and `*.PLATFORM_DOMAIN`. Install the resulting
   cert + key on the server (referenced by both the CloudPanel-managed site and the
   hand-written wildcard vhost from §0, so both present the same wildcard cert).
4. Trusted client IPs: this app only honours `X-Forwarded-For`/`X-Forwarded-Proto` from
   Cloudflare's published IP ranges (`config/cloudflare.php`) — Cloudflare rotates these
   occasionally; refresh via `curl -s https://www.cloudflare.com/ips-v4` and `ips-v6`, see
   the comment at the top of that file.
5. **Cache Rule for `/sw.js` and `/manifest.webmanifest` (Phase 6 — installable PWA):**
   Cloudflare dashboard → Caching → Cache Rules → create a rule matching
   `(http.request.uri.path eq "/sw.js") or (http.request.uri.path eq "/manifest.webmanifest")`
   with **Cache eligibility: Bypass cache** (or, if you'd rather cache it briefly, an Edge TTL
   of a few minutes at most — never "respect existing headers" left to Cloudflare's own
   long default). An owner changing their institute's name, colour or logo must take effect
   for visitors within minutes, not whatever Cloudflare's default TTL for static-looking
   paths would otherwise be. Static build assets under `/build/…` are fine to leave on
   Cloudflare's normal (long) cache behavior — they're already content-hashed by Vite.
   **HTTPS is mandatory** for the PWA to be installable at all (service workers only
   register in a secure context — see README.md "PWA testing locally"); §1.2's Full
   (strict) SSL/TLS mode already covers this end to end.
6. **Security headers** (Phase 11 — the application deliberately does not set these itself,
   see SECURITY.md "Response security headers"; Cloudflare is where they belong, because it
   already terminates TLS and answers before the origin does):
   - **`X-Frame-Options: SAMEORIGIN`** and **`X-Content-Type-Options: nosniff`** — via
     Cloudflare's managed transform that adds security headers, or a Transform Rule that
     modifies response headers for `PLATFORM_DOMAIN` and `*.PLATFORM_DOMAIN`.
   - **`Strict-Transport-Security: max-age=31536000; includeSubDomains`** — SSL/TLS → Edge
     Certificates → HTTP Strict Transport Security. Use a short `max-age` on the first
     deploy and raise it once you're certain the origin is HTTPS-only for good (HSTS is
     painful to walk back).
   - **`Content-Security-Policy`** — optional, and blunt at the edge (one policy applied to
     every response type). The app cannot ship its own yet: SECURITY.md records the exact
     inline handler/script/style counts that would have to be nonced first.
   - **[UNVERIFIED]** the exact dashboard labels above are not confirmed against a live
     Cloudflare account (docs/SECURITY_PASS.md flags this) — check them in your dashboard
     at deploy time and correct this list if they've moved.

## 2. Server prerequisites

- **PHP version:** `^8.3` (composer.json). Use CloudPanel's PHP version selector per site.
- **PHP extensions** (Laravel 13's own composer.json requires ctype, filter, hash,
  mbstring, openssl, session, tokenizer; this app additionally needs): `pdo`, `pdo_mysql`,
  `curl`, `dom`, `fileinfo`, `xml`, `bcmath`, `zip` (spatie/laravel-backup zips archives via
  `ZipArchive`). CloudPanel's PHP builds normally ship all of these — **VERIFY** with
  `php -m` on the server after selecting the site's PHP version.
- **MySQL 8** database + user (via CloudPanel's "Databases" section, or manually):
  ```sql
  CREATE DATABASE coaching_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'coaching_saas'@'localhost' IDENTIFIED BY '<strong random password>';
  GRANT ALL PRIVILEGES ON coaching_saas.* TO 'coaching_saas'@'localhost';
  FLUSH PRIVILEGES;
  ```
  Confirm the server is MySQL 8.0.16+ (`SELECT VERSION();`) — `php artisan app:preflight`
  (see §5) refuses to pass on an older version, since the `users.email`/`phone` CHECK
  constraint is silently unenforced below that (SECURITY.md).
- **Node.js** (for `npm run build` at deploy time — see §4) — **LTS ≥ 22.12**. Vite 8.3.1's
  `engines` field is `^20.19.0 || >=22.12.0`, so an older LTS fails `npm ci` with
  `EBADENGINE`; and Node 20 reached end-of-life on 2026-04-30, so don't pin it. Install it
  **as the site user** (nvm, per-user) following CloudPanel's own "Node.js for PHP Sites"
  guide, then `node -v` in a fresh shell — see docs/DEPLOY_RUNBOOK.md §2.6. Nothing at
  runtime needs Node; it is only used for `npm run build`.

## 3. `.env` for production

Base it on `.env.example`, then set at minimum:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://PLATFORM_DOMAIN
APP_KEY=                          # php artisan key:generate --force
PLATFORM_DOMAIN=PLATFORM_DOMAIN
TENANT_BASE_DOMAIN=PLATFORM_DOMAIN
CENTRAL_DOMAINS=PLATFORM_DOMAIN,platform.PLATFORM_DOMAIN
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=coaching_saas
DB_USERNAME=coaching_saas
DB_PASSWORD=<the password set above>
SESSION_DRIVER=database
SESSION_LIFETIME=43200            # 30 days — students aren't logged out every 2 hours
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=                   # leave EMPTY/unset — never set this (see SECURITY.md:
                                   # a non-null SESSION_DOMAIN would share one tenant's
                                   # session cookie with every other tenant subdomain)
VIDEO_DRIVER=bunny                # `fake` is refused by app:preflight in production
BUNNY_STREAM_ACCOUNT_API_KEY=     # platform account key only — never a per-tenant key
```

Replace `PLATFORM_DOMAIN` with your real domain throughout. `SESSION_DOMAIN` staying unset
(host-only cookies), `SESSION_SECURE_COOKIE=true`, and the `VIDEO_DRIVER`/account-key pair
are all checked by `php artisan app:preflight`.

## 3a. Bunny Stream (protected lesson video)

One Bunny Stream **account**, with per-tenant **libraries** provisioned automatically by the
app on each tenant's first video upload (VIDEO.md, AGENT_RULES.md invariant #13 — never a
shared library). Only the account-level API key lives in `.env`
(`BUNNY_STREAM_ACCOUNT_API_KEY`, from your Bunny account's API page); each tenant's own
library API key is created and stored encrypted by the app itself — never set it in `.env`.
That same key doubles as the embed-token signing key (verified against
https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the token security key is
your Video Library API Key") — there is no separate token security key to configure.

**Per-library settings to enable** (Bunny dashboard → Stream → the tenant's library →
Security), for every library, ideally by having these as the account-level defaults new
libraries inherit if Bunny's dashboard supports that, otherwise set on each library after
creation:

- **Embed view token authentication** — on. This is what makes the signed `token`/`expires`
  query parameters on the embed URL required (`App\Support\Video\BunnyEmbedTokenSigner`,
  verified against https://bunny.net/docs/stream-embed-token-authentication). Without this,
  the embed URL would play for anyone who has it, with no expiry.
- **CDN token authentication** — on. Same reasoning, for the underlying HLS/MP4 delivery URLs
  the player itself requests, not just the embed page.
- **Allowed referrers** — restricted to `PLATFORM_DOMAIN` and `*.PLATFORM_DOMAIN` (and
  `coaching.test`/`*.coaching.test` only while testing against a real Bunny library from a
  local machine, never left enabled in production). Prevents the embed URL from being
  iframed on an unrelated site even if a token leaked.


## 4. Deploy steps

Run from the app directory on the server, as the deploy/site user:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan app:preflight --require-production
```

`app:preflight` is the last step deliberately — it's a smoke test that the deploy actually
left the app in a safe state (see §5) and exits non-zero if not, so a CI/deploy script
should treat a non-zero exit here as "stop, do not consider this deploy done."

**Never run `php artisan db:seed` in production** — the demo seeder (including the local-only
`admin@coaching.test` platform admin, README "Local credentials") is guarded to local/testing
only (Phase 4.2 / Phase P1), but there's no seeder for real institutes or a real platform
admin anyway. Create both explicitly, in this order:

1. **First platform admin** (used to sign in on the central domain), once, interactively:

   ```bash
   php artisan platform-admin:create
   ```

2. **First (and every) real institute**, either the same way as every later one — the admin
   UI (`/admin/institutes/create`, once signed in) — or via the command line:

   ```bash
   php artisan tenant:create "Institute Name" subdomain --owner-name="Owner Name" --owner-email=owner@example.com
   ```

   Both paths run the exact same validation and creation logic
   (`App\Actions\CreateInstituteAction` — see docs/specs/phase-p1-platform.md). Either way,
   the owner's temporary password is shown once — copy it immediately and hand it to the
   owner through a secure channel; it is not stored or shown again (`must_change_password`
   is set, so they're forced to change it on first login).

A platform admin can **suspend** an institute from its detail page in the admin UI
(`/admin/institutes/{id}`) — a suspended institute's domain returns HTTP 503 with a
friendly translated page for every visitor (guest or logged-in), and any user already
logged in there is logged out on their next request. **Reactivate** from the same page to
restore it.

## 5. `php artisan app:preflight --require-production`

Fails (non-zero exit) in production if any of: `APP_DEBUG=true`, `APP_KEY` empty, `APP_URL`
not `https://`, `PLATFORM_DOMAIN`/`TENANT_BASE_DOMAIN`/`CENTRAL_DOMAINS` still at the local
dev default, `SESSION_SECURE_COOKIE` not `true`, `SESSION_DOMAIN` set to anything but
empty/null (host-only session cookies — SECURITY.md), `storage/` or `bootstrap/cache/` not
writable, the database unreachable, MySQL/MariaDB below the CHECK-constraint-enforcing
minimum (8.0.16 / 10.2.1), a demo tenant/account exists, the local-only `demo.localhost`
PWA-testing domain exists (Phase 6 — see README.md "PWA testing locally"), the GD PHP
extension or its FreeType support is missing (Phase 6 — needed to generate the default
institute icon and re-encode uploaded logos), `VIDEO_DRIVER=fake`, or `VIDEO_DRIVER=bunny`
with no `BUNNY_STREAM_ACCOUNT_API_KEY`. Per-tenant Bunny library
credentials are *not* checked here — they don't exist yet for a tenant that has never
uploaded video — and are instead validated lazily at first use, surfacing a teacher-facing
error if library creation fails. Without options, outside production it's a no-op
(informational only) — safe to run locally without it blocking anything. **Pass
`--require-production` (as every deploy step above and in the runbook does) to turn that
no-op into a hard failure instead**: outside production it then prints
`APP_ENV is "…", not "production" — this command was run with --require-production.` and
exits 2, so a deploy that forgot `APP_ENV=production` cannot get a green preflight.

## 6. Cron and the queue worker

**Scheduler** — add one cron entry (CloudPanel: site → Cron Jobs, or the server crontab
directly) running every minute:

```
* * * * * cd /home/<site-user>/htdocs/PLATFORM_DOMAIN && php artisan schedule:run >> /dev/null 2>&1
```

This is what actually fires the daily backup (`backup:run` at 02:00, `backup:clean` at
01:30 — `routes/console.php`), the daily `devices:prune` (removes device rows unseen for
60+ days), and any other scheduled task; nothing runs on its own without this cron entry.

**Queue worker** — this app doesn't dispatch any queued jobs as of this phase, so there is
no worker to run yet. When a future phase adds one: CloudPanel has a Supervisor/process
manager section in newer versions for exactly this (**VERIFY** against your CloudPanel
version — this wasn't confirmed hands-on for this runbook); the portable fallback is a
standard `supervisor` config running `php artisan queue:work --tries=3`, restarted via
`supervisorctl` and set to auto-restart on deploy.

## 7. Backups

`spatie/laravel-backup` (confirmed compatible with this app's Laravel 13.33/PHP 8.3 — see
`composer.json`) backs up the MySQL database and the private notes storage
(`storage/app/private/`, where lesson attachment PDFs live) daily, to a dedicated `backups`
disk (`storage/app/backups/` — deliberately not the same disk being backed up), with a
14-day retention (`config/backup.php` → `cleanup.default_strategy`). Scheduled via §6's
cron; see `routes/console.php` for the exact times.

**Copying backups off the server** (do this — a backup that only lives on the server it's
protecting against isn't a real backup): the simplest option is a periodic
`rsync`/`scp` pull from a separate machine, e.g. a cron entry *on a different host* (your
own machine, or another server) running:

```bash
rsync -avz --delete your-user@your-server:/home/<site-user>/htdocs/PLATFORM_DOMAIN/storage/app/backups/ /path/to/local/backup/mirror/
```

Alternatively, configure a second disk in `config/filesystems.php` (e.g. S3-compatible
object storage) and add it to `backup.backup.destination.disks` in `config/backup.php` —
`spatie/laravel-backup` will then write to both disks on every run. Either way, verify
restores periodically; an untested backup is not a working backup.

## 8. Monitoring the backup itself

`config/backup.php` → `monitor_backups` is configured to alert (via the `mail` notification
channel) if the newest backup is more than a day old or the backup disk exceeds 5000MB. Run
`php artisan backup:monitor` from the same cron as `schedule:run` handles automatically
(it's not separately scheduled here — add it if you want a distinct alert path from
`backup:run` itself failing).

**The alert address is configurable from `.env`: `BACKUP_NOTIFY_EMAIL`.**
`backup.notifications.mail.to` reads that key and falls back to `MAIL_FROM_ADDRESS` when it is
blank (`.env.example` ships `BACKUP_NOTIFY_EMAIL=` empty, plus `MAIL_FROM_ADDRESS` — and ships
`MAIL_MAILER=log`, which writes mail to `storage/logs/laravel.log` instead of sending it). So
to actually receive these alerts you need **both**: a real SMTP transport in `.env`, and
`BACKUP_NOTIFY_EMAIL` set to an address you read. Until then, treat the absence of a backup
file as the alert — see docs/DEPLOY_RUNBOOK.md §1.7 item 5.
