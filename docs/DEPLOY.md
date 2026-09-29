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
- **Node.js** (for `npm run build` at deploy time — see §4) — any current LTS.

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
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=                   # leave EMPTY/unset — never set this (see SECURITY.md:
                                   # a non-null SESSION_DOMAIN would share one tenant's
                                   # session cookie with every other tenant subdomain)
```

Replace `PLATFORM_DOMAIN` with your real domain throughout. `SESSION_DOMAIN` staying unset
(host-only cookies) and `SESSION_SECURE_COOKIE=true` are both checked by
`php artisan app:preflight`.

## 4. Deploy steps

Run from the app directory on the server, as the deploy/site user:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan app:preflight
```

`app:preflight` is the last step deliberately — it's a smoke test that the deploy actually
left the app in a safe state (see §5) and exits non-zero if not, so a CI/deploy script
should treat a non-zero exit here as "stop, do not consider this deploy done."

**Never run `php artisan db:seed` in production** — the demo seeder is guarded to
local/testing only (Phase 4.2), but there's no seeder for real institutes anyway. Create the
first (and every) real institute with:

```bash
php artisan tenant:create "Institute Name" subdomain --owner-name="Owner Name" --owner-email=owner@example.com
```

This prints the owner's temporary password once — copy it immediately and hand it to the
owner through a secure channel; it is not stored or shown again (`must_change_password` is
set, so they're forced to change it on first login).

Create the platform admin account (used to sign in on the central domain) once, interactively:

```bash
php artisan platform-admin:create
```

## 5. `php artisan app:preflight`

Fails (non-zero exit) in production if any of: `APP_DEBUG=true`, `APP_KEY` empty, `APP_URL`
not `https://`, `PLATFORM_DOMAIN`/`TENANT_BASE_DOMAIN`/`CENTRAL_DOMAINS` still at the local
dev default, `SESSION_SECURE_COOKIE` not `true`, `storage/` or `bootstrap/cache/` not
writable, the database unreachable, MySQL/MariaDB below the CHECK-constraint-enforcing
minimum (8.0.16 / 10.2.1), or a demo tenant/account exists. Outside production it's a no-op
(informational only) — safe to run locally without it blocking anything.

## 6. Cron and the queue worker

**Scheduler** — add one cron entry (CloudPanel: site → Cron Jobs, or the server crontab
directly) running every minute:

```
* * * * * cd /home/<site-user>/htdocs/PLATFORM_DOMAIN && php artisan schedule:run >> /dev/null 2>&1
```

This is what actually fires the daily backup (`backup:run` at 02:00, `backup:clean` at
01:30 — `routes/console.php`) and any other scheduled task; nothing runs on its own without
this cron entry.

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
channel — set `backup.notifications.mail.to` to a real address you actually read) if the
newest backup is more than a day old or the backup disk exceeds 5000MB. Run
`php artisan backup:monitor` from the same cron as `schedule:run` handles automatically
(it's not separately scheduled here — add it if you want a distinct alert path from
`backup:run` itself failing).
