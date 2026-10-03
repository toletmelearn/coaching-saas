# Deployment Runbook — first production deploy (tenant #1)

This is the ordered, copy-pasteable procedure for the first live deployment: the pilot
institute (tenant #1) going in front of a real customer. It is the *execution* document;
[docs/DEPLOY.md](DEPLOY.md) stays the *reference* document (CloudPanel vhost layout, Bunny
per-library settings, backup/monitoring theory). Where the two disagree, or where a step was
ambiguous, §1.7 says so and gives a concrete answer.

Written against `main` = `e605cc5` = tag `phase-11`.

**Corrections applied 2026-10-02 against `main` = `9173eb9`.** Three items that were open
when this was written are now closed: the backup compressor defect and the configurable
backup alert address (§1.7 item 5, §1.8 — both fixed in `c859e74`), the
`EmbedTokenPlaybackTest` wall-clock flake (fixed in `b9063a3`), and the missing
`VIDEO_DRIVER` / `BUNNY_STREAM_ACCOUNT_API_KEY` keys in `.env.example` (§1.7 item 7 — fixed
in `9173eb9`). Original text is preserved below wherever the history has value; resolved
items are marked, not deleted.

**Corrections applied 2026-10-03 against `main` = `c61a8bc`.** CI did not exist when this
runbook was written and now does (`.github/workflows/ci.yml`, `9ff5f11`), which has already
paid for itself: its first red run (`37036620403`) caught the device tie-order bug fixed in
`c61a8bc`. This pass refreshed §1.1's expected HEAD, gate-output table and run-time
estimate, added the engine caveat there, fixed §1.2's now-stale "same sha" dependency,
refreshed §1.7 item 3 and §4.1's expected HEAD, and added §14 item 6 describing both
workflows. Same rule as before: originals are preserved, resolved items are marked.

---

## How to use this runbook

**You run every command.** The agent that wrote this has no SSH access to your server and did
not execute anything remote. If a value isn't in these docs, it's listed in §13 — supply it
before you start rather than improvising mid-run.

**Placeholders.** Replace every `<...>` before pasting:

| Placeholder | Meaning | Example |
|---|---|---|
| `<DOMAIN>` | the bare registered domain | `padhai.example` |
| `<SERVER_IP>` | the VPS public IPv4 | `203.0.113.10` |
| `<SITE_USER>` | the CloudPanel site user | `padhai` |
| `<APP_DIR>` | absolute path to the app | `/home/padhai/htdocs/padhai.example` |
| `<DB_PASSWORD>` | the MySQL password you generate | — |
| `<BUNNY_ACCOUNT_KEY>` | Bunny account-level API key | — |
| `<PREV_SHA>` / `<STAMP>` | git sha / backup timestamp | — |

**Who runs what.** Commands are run on the VPS as `<SITE_USER>` unless the block says
*as root*. Commands run on your own laptop are marked **(local)**.

**Every step ends with a `Confirm:` line.** Don't skip it — each later step assumes the
previous one actually worked.

---

## 1. Preconditions

Nothing below starts until all six are true.

### 1.1 All five quality gates green on `main` (local)

**(local)**

```bash
git fetch --tags origin
git checkout main
git pull --ff-only
git log --oneline -1
```

**Confirm:** the line equals `origin/main`'s current HEAD. Last verified **2026-10-03**:
`c61a8bc fix(devices): deterministic tie-break in enforceLimit victim selection`. Do **not**
expect this to equal the `phase-11` tag — that pins an older commit (see §1.2).

Then run the gates **one at a time, never concurrently** — Composer's default
`process-timeout` is 300 s, and running `composer test` (≈152 s) alongside `composer analyse`
has previously made Composer kill Pest with a process-timeout abort. That looks like a test
failure and isn't one.

```bash
composer test
composer test:mysql
composer test:js
composer lint
composer analyse
```

**Expected raw output** (each command's last meaningful line, captured on `c61a8bc`,
2026-10-03 — `duration_ms` varies by machine, the test/assertion counts should not):

| Command | Expected final line |
|---|---|
| `composer test` | `{"tool":"pest","result":"passed","tests":825,"passed":824,"assertions":2436,"duration_ms":151980,"skipped":1}` |
| `composer test:mysql` | `{"tool":"pest","result":"passed","tests":793,"passed":793,"assertions":2396,"duration_ms":113000}` |
| `composer test:js` | `ℹ tests 29` … `ℹ pass 29` … `ℹ fail 0` |
| `composer lint` | `{"tool":"pint","result":"passed"}` |
| `composer analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |

**Confirm:** all five report `passed` / `pass 29` / `errors: 0`, none report `failed`. Save
the five outputs — §10 asks you to compare against a known-good baseline.

Three caveats:

- **The 1 skipped test is expected.** It is the MySQL-only unreachable-database preflight
  test, skipped on the SQLite run by design.
- **Your local MySQL may not be MySQL.** `composer test:mysql` runs against whatever
  `DB_TEST_*` points at — on this machine that is **MariaDB 10.4**, while CI's gate 5 runs
  against a real **`mysql:8.4`** service. They agree on every assertion count, but the
  *row order* a query returns can differ between them (that is exactly how the device
  tie-order bug in `c61a8bc` passed locally and failed in CI). Treat CI's gate 5 as the
  authoritative MySQL result.
- **Former flake — resolved in `b9063a3`.** `tests/Feature/Video/EmbedTokenPlaybackTest.php`
  used to compare a token it signed with its own `now()+10min` against the token the server
  rendered with *its* `now()`, so a second-boundary crossing between the two made the strings
  differ (roughly once per full-suite run; 3/3 in isolation). The test now asserts shape and
  provenance against the `expires` the server actually used, which cannot race. **A failure
  in that file is a real failure now — any failure is a hard stop.**

**Why this runs locally, not on the server:** step 5.1 installs Composer with `--no-dev`,
which removes Pest, Pint and PHPStan from `vendor/`. Running the gates on the production box
would mean installing dev dependencies in production. Run them here and paste the output into
your deploy notes. **CI now runs these same five gates on every push** (§14 item 6), so a
green run on the commit you are about to deploy is a second, independent confirmation —
check it with `gh run list --limit 1` before you start step 5.

### 1.2 Tag `phase-11` exists and is pushed

**(local)**

```bash
git fetch --tags origin
git tag -l phase-11
git ls-remote --tags origin phase-11
```

**Confirm:** both commands print `phase-11`, and `git rev-list -n1 phase-11` resolves to
`e605cc5`. That tag intentionally pins an **older** commit than §1.1's `main` HEAD — it marks
the end of Phase 11, not the current tip. It only has to exist and be pushed.

### 1.3 A domain is registered and Cloudflare is configured (name servers pointed)

- The domain is registered and you control its registrar records.
- A Cloudflare zone exists for `<DOMAIN>`, and the registrar's name servers have been changed
  to the two Cloudflare name servers Cloudflare assigned.

**Confirm (dashboard):** Cloudflare → the zone → Overview shows **Active** and lists your two
`*.ns.cloudflare.com` name servers.

**Confirm (command line, local):**

```bash
nslookup -type=NS <DOMAIN>
```

**Expected:** two `....ns.cloudflare.com` answers. (On macOS/Linux, `dig NS <DOMAIN> +short`
does the same thing.)

Do **not** continue until the zone is Active — every later TLS step assumes Cloudflare is
answering for the name.

### 1.4 A Hostinger VPS is provisioned with the chosen OS

**Pinned OS: Ubuntu 24.04 LTS.** CloudPanel CE supports Ubuntu 26.04 / 24.04 / 22.04 and
Debian 13 / 12 (verified against `cloudpanel.io/docs/v2/requirements`), and needs ≥1 core,
≥2 GB RAM, ≥10 GB disk. Ubuntu 24.04 is the safe, widely-documented choice; 22.04 also works
if that's what your plan offers.

SSH in **as root** (CloudPanel installs as root):

```bash
ssh root@<SERVER_IP>
```

**Confirm:**

```bash
lsb_release -d
free -h | head -2
df -h /
```

**Expected:** `Ubuntu 24.04`, ≥2 GB total RAM, ≥10 GB on `/`.

You need root for §2 and §3. From §4 onward, everything runs as `<SITE_USER>`.

### 1.5 A Bunny Stream account exists (account-level API key in hand)

You need the **account-level** API key — bunny.net → Account → API page. This is *not* a
per-tenant Stream Library API key (Stream → Library → API keys); those are created and stored
encrypted by the app itself and must never go in `.env` (docs/DEPLOY.md §3a).

**Confirm:** you have a key you can paste into `<BUNNY_ACCOUNT_KEY>` below, and you know it
came from the account API page, not from a library page.

### 1.6 A backup destination is decided (local disk + offsite)

Two destinations, both required:

1. **On-server:** `storage/app/backups/` (the `backups` disk, `config/backup.php`), 14-day
   retention. This happens automatically once §8's cron is in place.
2. **Offsite:** a *different machine* pulling those files off the VPS. A backup that only
   lives on the server it protects against isn't a backup (docs/DEPLOY.md §7).

**Confirm:** write down, before you start:

- `OFFSITE_HOST` — your laptop, or a second server
- `OFFSITE_PATH` — where the mirrored copies land
- how often (daily is what the app assumes)

The rsync line for #2 is in §8.3. Decide it *now* so that when §10.11 says "backup produced a
file at the configured destination", you know where to look.

### 1.7 Documentation discrepancies found while writing this

Each of these is a place where the docs disagree with the code, or are too loose to execute.
Nothing here is a stop — but read them so you don't act on the stale version.

| # | Where | Says | Reality | What to do |
|---|---|---|---|---|
| 1 | `docs/DEPLOY.md` §3, §5 | `SESSION_DOMAIN` is checked by `app:preflight` | **Now true.** PROJECT_BRIEF §7 flagged it as claimed-but-missing; Phase 11 added `checkSessionDomain()` (`app/Console/Commands/AppPreflightCommand.php:111`) plus tests | Nothing. This runbook repeats the claim legitimately, and `docs/DEPLOY.md` needed no change for it |
| 2 | `README.md:123` | `Mini security pass before deploy \| Not run as a discrete pass` | **Corrected in `c21ca36`** — README now reads `Run (Phase 11) — docs/SECURITY_PASS.md`, which is true | Nothing. Kept here as history: the runbook's original observation was right when written |
| 3 | `README.md` gate table | 762 / 730 tests | Still stale — README was corrected once in `c21ca36` to 824 / 792, and `c61a8bc` added one more test, so current is **825 / 793** | Use §1.1's table, not README's |
| 4 | `docs/DEPLOY.md` §2 | Node.js: "any current LTS" | Too loose — Vite 8.3.1's `engines` field is `^20.19.0 \|\| >=22.12.0`; an older LTS fails `npm ci` with `EBADENGINE` | Pin per §2.6; `docs/DEPLOY.md` §2 is updated by this runbook |
| 5 | `docs/DEPLOY.md` §8 | "set `backup.notifications.mail.to` to a real address" | **Resolved in `c859e74`.** `config/backup.php` now reads `env('BACKUP_NOTIFY_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com')`, and `.env.example` ships `BACKUP_NOTIFY_EMAIL=` (blank → falls back to `MAIL_FROM_ADDRESS`) | Set `BACKUP_NOTIFY_EMAIL=<an-address-you-read>` in the production `.env`, and give `MAIL_MAILER` a real transport — otherwise alerts still only reach `storage/logs/laravel.log`. Don't edit `config/backup.php` on the server (it would drift from git) |
| 6 | `CENTRAL_DOMAINS` | implied to be flexible | Central routing is `Route::domain()` over exactly the listed hostnames. A `www.<DOMAIN>` DNS record would reach the app, fail tenant resolution and 404 (fail-closed, TENANCY.md) | Do **not** create a `www` record. Add `www.<DOMAIN>` to `CENTRAL_DOMAINS` only if you actually want it served |
| 7 | `.env.example` | (was absent) | **Resolved in `9173eb9`** — both keys are present now: `VIDEO_DRIVER=fake` and `BUNNY_STREAM_ACCOUNT_API_KEY=`. `fake` is the deliberate local default (copying `bunny` would fail 11 playback tests on a fresh clone); production must override it, which is exactly what `app:preflight` enforces | Set `VIDEO_DRIVER=bunny` and paste the key — step 4.4 |
| 8 | `.env.example:48` | `SESSION_SECURE_COOKIE=false` | Ships **false**, not true | Flip it to `true` by hand — step 4.4 |

### 1.8 Backup compressor defect — **resolved in `c859e74`**

**Resolved.** `php artisan backup:run` works; §8 (the nightly backup), §10.11 and the §11
restore path are all unblocked. The entire fix was one line:

```diff
- use Spatie\Backup\Compressors\GzipCompressor;
+ use Spatie\DbDumper\Compressors\GzipCompressor;
```

Two guards landed with it, so this cannot silently regress again:

1. a config canary — `expect(class_exists(config('backup.backup.database_dump_compressor')))->toBeTrue()`;
2. an execution canary — `backup:run --only-db` is actually run, which is what proves the
   pipeline rather than just the class name. It is in Pest's `backup` group
   (`vendor/bin/pest --group=backup`) because it needs a real dump binary, so it runs on
   demand rather than in the per-push suite.

The same commit made the alert address configurable (§1.7 item 5) and added
`SQLITE_DUMP_BINARY_PATH` / `MYSQL_DUMP_BINARY_PATH` to `.env.example` for hosts where those
binaries exist but are not on `PATH`.

*Original description below, preserved verbatim for history. Everything after the "Cause"
list describes the pre-`c859e74` state — including its "**all 822 tests are green**" figure,
which was true on `e605cc5` and is now **825** (§1.1). The number is historical, not a live
claim.*

**Do not skip this.** It will silently break §8 (the nightly backup), §10.11 and the §11
restore path.

```
php artisan backup:run
   Error
  Class "Spatie\Backup\Compressors\GzipCompressor" not found
  at vendor/spatie/laravel-backup/src/Tasks/Backup/BackupJob.php:325
```

Cause, verified on this checkout:

- `config/backup.php:3` has `use Spatie\Backup\Compressors\GzipCompressor;` and `:109` uses
  `GzipCompressor::class`.
- `spatie/laravel-backup` **10.3.3** ships **no** `src/Compressors/` directory —
  `class_exists('Spatie\Backup\Compressors\GzipCompressor')` is `false`.
- The real class is `Spatie\DbDumper\Compressors\GzipCompressor` (`class_exists` → `true`) —
  which is what `config/backup.php`'s *own comment on line 102* already says.
- `tests/Feature/Production/BackupConfigurationTest.php` asserts config values and the
  schedule but never executes `backup:run`, which is why all 822 tests are green while the
  command is broken.

Per this task's rules I did **not** change it — this is a stop-and-report item. The one-line
fix and the test that would have caught it are in §14, open question 4.

**Until it is fixed:** every step in this runbook still runs, but treat §10.11 as
*expected to fail* and do not consider the deploy "backup-complete". `backup:list`,
`backup:clean` and `backup:monitor` are unaffected (they never touch the compressor).

> **Obsolete as of `c859e74`.** The two paragraphs immediately above ("Per this task's rules
> I did not change it…" and "Until it is fixed…") are the record of the stop-and-report item
> as it stood at `e605cc5`. The fix was applied, §14 open question 4 no longer exists, and
> §10.11 is expected to *succeed*. Kept verbatim because the reasoning — "an untested backup
> is not a working backup" — is what the canaries above now enforce.

---

## 2. Server provisioning

### 2.1 OS

Pinned in §1.4: **Ubuntu 24.04 LTS**.

```bash
lsb_release -d
```

**Confirm:** `Description: Ubuntu 24.04…`

### 2.2 CloudPanel (NGINX + PHP-FPM + panel)

CloudPanel installs on an **empty** server, as root. Hostinger VPS images are close to empty;
if you've already installed packages you don't need, start from a fresh image.

Update first:

```bash
apt update && apt -y upgrade && apt -y install curl wget sudo
```

Then, **copied from `cloudpanel.io/docs/v2/getting-started/other/`** (do not trust this
runbook's copy over the live page — the SHA256 changes as CloudPanel releases):

```bash
curl -sS https://installer.cloudpanel.io/ce/v2/install.sh -o install.sh; \
echo "8146dbe0a488e7088b04071b0c34d59aa0ab1fe9dcec382d395fd155c9e6c476 install.sh" | \
sha256sum -c && sudo DB_ENGINE=MYSQL_8.4 bash install.sh
```

- If `sha256sum -c` prints `FAILED`, **stop. Do not run the installer.** Re-copy the whole
  block from the docs page above.
- `DB_ENGINE` options documented today: `MYSQL_8.4`, `MARIADB_12_3`, `MARIADB_11_8`. Either
  engine is fine (§2.4); pick `MYSQL_8.4` if you have no preference.

**Confirm:**

```bash
which clpctl && systemctl is-active nginx
```

**Expected:** a path to `clpctl`, and `active`.

**Then, immediately:** open `https://<SERVER_IP>:8443` and create the CloudPanel admin user.
CloudPanel's own docs warn there is a window during which bots can create that user — do it
now, and restrict port 8443 to your IP in the firewall.

### 2.3 PHP 8.3 (per site)

1. CloudPanel → **+ Add Site → Create a PHP Site**.
2. Application: the **Laravel** template. Domain: `<DOMAIN>`. Site user: `<SITE_USER>`.
   Set a password.
3. Site → **PHP Settings** → select **PHP 8.3**.

**Pin: PHP 8.3.x.** `composer.json` requires `php: ^8.3`, so 8.4/8.5 also *satisfy* the
constraint — but this repo's five gates have only ever been run on PHP 8.3.29, and CloudPanel's
own Laravel example uses `--phpVersion=8.5`. **[VERIFY]** that 8.3 is offered in your
CloudPanel version's dropdown; if it isn't, say so before proceeding (§14 open question 2).

**Confirm:**

```bash
php -m | grep -Ei '^(pdo_mysql|curl|dom|fileinfo|xml|bcmath|zip|gd|mbstring|openssl|tokenizer|ctype|filter|hash|session)$' | sort -u | wc -l
```

**Expected:** `16` (all 16 present).

**Confirm GD has FreeType** (needed to draw the default institute icon and re-encode logos —
preflight checks both):

```bash
php -r "var_dump(extension_loaded('gd'), gd_info()['FreeType Support'] ?? false);"
```

**Expected:** `bool(true)` and `bool(true)`.

> Note: `php` on PATH may be the system PHP, not the site's PHP. Run the two commands above
> **after** selecting the site's PHP version, and if the output disagrees, re-run them through
> the site's PHP binary (CloudPanel shows it in the PHP Settings screen). The authoritative
> check is step 6.1 anyway.

### 2.4 Database engine

Chosen at §2.2 install time. **Pin: MySQL 8.4 (or MariaDB 11.8/12.3).**

The floor is not cosmetic: below **MySQL 8.0.16** or **MariaDB 10.2.1** the
`users.email`/`phone` CHECK constraint is parsed but never enforced
(`App\Support\DatabaseVersionCheck`, SECURITY.md), and `app:preflight` refuses to pass.

```bash
mysql -V
mysql -uroot -p -e "SELECT VERSION();"
```

**Expected:** `8.x.y` ≥ 8.0.16, or a `...-MariaDB` string ≥ 10.2.1.

### 2.5 NGINX

Ships with CloudPanel — no separate install.

```bash
nginx -v
```

**Confirm:** prints a version, no error. The wildcard-vhost work (docs/DEPLOY.md §0) happens
in §7, not here.

### 2.6 Node.js — pin 24, not 20

**Requested pin: Node 20. Actual pin: Node 24 LTS (via nvm).**

Why the deviation: Vite 8.3.1's `engines` field is `^20.19.0 || >=22.12.0`, so Node 20.19+
*would* satisfy it — but Node 20 ("Iron") reached **end-of-life on 2026-04-30** per the
official `nodejs/Release` `schedule.json`, and today is 2026-10-02. Node 24 is Active LTS
(supported to 2028-04-30) and satisfies `>=22.12.0`. CloudPanel's own "Node.js for PHP Sites"
guide uses `nvm install 24`. See §14 open question 1 — if you'd rather follow the brief
literally, `nvm install 20.19` also satisfies Vite, but you'd be shipping an EOL runtime on a
brand-new production box.

Run **as `<SITE_USER>`** (nvm installs per-user; nothing at runtime needs Node — Node is used
only for `npm run build`):

```bash
ssh <SITE_USER>@<SERVER_IP>
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.5/install.sh | bash
source ~/.bashrc
nvm install 24
nvm use 24
node -v
npm -v
```

**Expected:** `v24.x.x` and `npm 11.x`. (Any `>=22.12.0` satisfies Vite 8.)

**Confirm:** `node -v` succeeds in a *fresh* shell (`ssh` in again and run it) — if it doesn't,
nvm's init line isn't in `~/.bashrc`, and §5.2 will fail with `vite: not found`.

> **Never run the build as root.** nvm is installed for `<SITE_USER>` only, so `npm` won't
> exist in a root shell. Every command from §4 onward is explicitly `<SITE_USER>`.

### 2.7 Composer

```bash
composer -V
```

**Pin: Composer 2.x** (this repo's gates were last verified on 2.9.5).

CloudPanel normally ships `/usr/local/bin/composer`. If the command isn't found, install it
**as root**:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer -V
```

**Confirm:** prints `Composer version 2.x`.

### 2.8 Version pins — the whole table

| Component | Pin | Why | How to confirm |
|---|---|---|---|
| OS | Ubuntu 24.04 LTS | CloudPanel-supported, current LTS | `lsb_release -d` |
| CloudPanel | current CE v2 | — | `which clpctl` |
| PHP | **8.3.x** | `composer.json` `php: ^8.3`; gates only ever run on 8.3.29 | site → PHP Settings |
| Database | MySQL **8.4** (≥8.0.16) or MariaDB ≥10.2.1 | CHECK-constraint enforcement | `mysql -e "SELECT VERSION();"` |
| NGINX | whatever CloudPanel ships | panel-managed | `nginx -v` |
| Node.js | **24** (≥22.12) | Vite 8.3.1 `engines`; Node 20 is EOL | `node -v` |
| nvm | v0.40.5 | CloudPanel's documented version | the install script URL |
| Composer | **2.x** (verified 2.9.5) | — | `composer -V` |

---

## 3. Database creation

Two databases, and a dedicated non-root user.

- **`coaching_saas`** — production. Required.
- **`coaching_saas_test`** — only if you intend to run `composer test:mysql` on this server.
  **Skip it otherwise** (see the note at the end of this section).

Run **as root** (or use CloudPanel → Databases → Add):

```bash
mysql -uroot -p <<'SQL'
CREATE DATABASE coaching_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'coaching_saas'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
GRANT ALL PRIVILEGES ON coaching_saas.* TO 'coaching_saas'@'localhost';
FLUSH PRIVILEGES;
SELECT VERSION();
SQL
```

Generate a real password first, e.g. `openssl rand -base64 24`.

**Expected:** four `Query OK` lines plus one row containing the server version.

**Confirm** the app user sees *only* its own database:

```bash
mysql -ucoaching_saas -p -e "SHOW DATABASES;"
```

**Expected:** `coaching_saas`, plus the engine's own `information_schema` / `mysql` /
`performance_schema`. It must **not** list any other application database.

Optional test database — **only** if you'll run `composer test:mysql` on the server:

```bash
mysql -uroot -p <<'SQL'
CREATE DATABASE coaching_saas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON coaching_saas_test.* TO 'coaching_saas'@'localhost';
FLUSH PRIVILEGES;
SQL
```

…and then set `DB_TEST_USERNAME=coaching_saas` / `DB_TEST_PASSWORD=<DB_PASSWORD>` in `.env`.

**Why not root:** `app:preflight` doesn't check this, but a runtime DB user that owns exactly
one database turns a hypothetical SQL-injection bug into one-database damage instead of
whole-server damage. Root credentials in `.env` are an unnecessary multiplier.

**Note on running the tests here at all:** you almost certainly shouldn't. §1.1 runs the gates
locally; step 5.1 installs Composer `--no-dev`, so `composer test:mysql` won't even be
installed on the server. Create `coaching_saas_test` only if you have a deliberate reason
(e.g. a CI runner on this box).

---

## 4. Repository clone and `.env`

Everything below runs **as `<SITE_USER>`**.

### 4.1 Clone into CloudPanel's document root

CloudPanel created a placeholder at `~/htdocs/<DOMAIN>`. Replace it with the app, exactly as
CloudPanel's own Laravel guide does:

```bash
cd ~/htdocs
rm -rf <DOMAIN>
git clone https://github.com/toletmelearn/coaching-saas.git <DOMAIN>
cd <DOMAIN>
git fetch --tags
git checkout main
git pull --ff-only
git log --oneline -1
```

**Expected / Confirm:** `git log --oneline -1` matches `origin/main`'s current HEAD (last
verified 2026-10-03: `c61a8bc fix(devices): deterministic tie-break in enforceLimit victim
selection`), and:

```bash
ls -d ~/htdocs/<APP_DIR_basename>/public && git tag -l phase-11 && git rev-list -n1 phase-11
```

…prints the `public/` directory, then `phase-11` and `e605cc5` — which is what proves
`git fetch --tags` actually brought the tags over. Note that `git tag --points-at HEAD`
prints **nothing** on `main`: the tag deliberately pins an older commit than the branch tip
(see §1.2), so that was never a check that `main` is at the tag.

> Cloning into `~/htdocs/<DOMAIN>` keeps CloudPanel's vhost docroot (which points at
> `~/htdocs/<DOMAIN>/public`) valid — that's why we replace the placeholder rather than clone
> somewhere else.

### 4.2 First `composer install` (so `php artisan` exists)

```bash
cd <APP_DIR>
composer install --no-dev --optimize-autoloader
```

**Expected:** `Generating optimized autoload files`, exit 0.

**Confirm:**

```bash
php artisan --version
```

**Expected:** `Laravel Framework 13.33.0`.

(This is deliberately repeated as step 5.1 — `docs/DEPLOY.md` §4's block starts with it, so a
repeat deploy runs the identical sequence. Running it twice is a no-op.)

### 4.3 Copy `.env.example` — **not** your local `.env`

```bash
cd <APP_DIR>
cp .env.example .env
```

**Why not your local `.env`:**

- `.env` is gitignored, so your local one isn't in the repo anyway — and copying it by hand is
  how local DB credentials and a local `APP_KEY` end up in production.
- `.env.example` is the file that carries the production-correct session defaults:
  `SESSION_LIFETIME=43200` (30 days), `SESSION_DOMAIN=null` (host-only cookies — a shared
  cookie domain would defeat tenant session isolation, SECURITY.md), and `SESSION_SECURE_COOKIE`
  present with its explanatory comment (you flip it in §4.4).
- `.env.example` has the `PLATFORM_DOMAIN` / `TENANT_BASE_DOMAIN` / `CENTRAL_DOMAINS` keys.
  Your local `.env` may not have them at all, and preflight refuses to pass while they hold
  the `coaching.test` dev default.

**Confirm:**

```bash
test -f .env && grep -c '^SESSION_LIFETIME=43200' .env
```

**Expected:** `1`.

### 4.4 Every production key you must set by hand

Edit `.env`. Nothing here can be left to defaults.

| Key | `.env.example` ships | Set to | Checked by preflight |
|---|---|---|---|
| `APP_NAME` | `Coaching SaaS` | your product name (cosmetic — also names the backup archive) | — |
| `APP_ENV` | `local` | **`production`** | yes — preflight is a **no-op unless this is `production`** |
| `APP_KEY` | empty | set by step 4.5, never by hand | yes |
| `APP_DEBUG` | `true` | **`false`** | yes |
| `APP_URL` | `http://localhost:8000` | **`https://<DOMAIN>`** (bare, no trailing slash) | yes |
| `PLATFORM_DOMAIN` | `coaching.test` | **`<DOMAIN>`** | yes |
| `TENANT_BASE_DOMAIN` | `coaching.test` | **`<DOMAIN>`** | yes |
| `CENTRAL_DOMAINS` | `localhost,127.0.0.1,coaching.test,platform.coaching.test` | **`<DOMAIN>,platform.<DOMAIN>`** | yes (must be non-empty) |
| `DB_USERNAME` | `root` | **`coaching_saas`** | — |
| `DB_PASSWORD` | empty | **`<DB_PASSWORD>`** (step 3) | — |
| `DB_DATABASE` | `coaching_saas` | leave as-is | — |
| `LOG_LEVEL` | `debug` | `warning` (recommended; not preflight-checked) | — |
| `SESSION_SECURE_COOKIE` | **`false`** (line 48) | **`true`** | yes |
| `SESSION_DOMAIN` | `null` | **leave exactly `null`** — never widen it | yes (rejects anything but null/empty) |
| `SESSION_LIFETIME` | `43200` | **leave as-is** | — |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | **leave as-is** — `migrate --force` creates those tables | — |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | an address you own | — |
| `MAIL_MAILER` | `log` | keep `log` unless you have real SMTP — see the note below | — |
| `VIDEO_DRIVER` | `fake` (since `9173eb9`) | **change to** `VIDEO_DRIVER=bunny` | yes (`fake` is refused in production) |
| `BUNNY_STREAM_ACCOUNT_API_KEY` | empty (since `9173eb9`) | **set** `BUNNY_STREAM_ACCOUNT_API_KEY=<BUNNY_ACCOUNT_KEY>` | yes |
| `DB_TEST_*` | set | leave, unless you created `coaching_saas_test` (§3) | — |

**Two lines you must set** (both keys now exist in `.env.example` as of `9173eb9`, but with
local defaults preflight will reject — §1.7 item 7):

```
VIDEO_DRIVER=bunny
BUNNY_STREAM_ACCOUNT_API_KEY=<BUNNY_ACCOUNT_KEY>
```

**Mail note.** `MAIL_MAILER=log` writes outgoing mail to `storage/logs/laravel.log` instead of
sending it. That is a *safe* production default — nothing in this app emails a user today —
but it means backup-health notifications (docs/DEPLOY.md §8) go to the log, not your inbox.
And if you *do* configure SMTP, set `BACKUP_NOTIFY_EMAIL=<an-address-you-read>` alongside it —
`backup.notifications.mail.to` reads that key and falls back to `MAIL_FROM_ADDRESS` when it is
blank (resolved in `c859e74`; §1.7 item 5).

**Confirm the whole edit in one shot:**

```bash
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|PLATFORM_DOMAIN|TENANT_BASE_DOMAIN|CENTRAL_DOMAINS|DB_USERNAME|SESSION_SECURE_COOKIE|SESSION_DOMAIN|SESSION_LIFETIME|VIDEO_DRIVER|BUNNY_STREAM_ACCOUNT_API_KEY)=' .env
```

**Expected, line by line:**

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<DOMAIN>
PLATFORM_DOMAIN=<DOMAIN>
TENANT_BASE_DOMAIN=<DOMAIN>
CENTRAL_DOMAINS=<DOMAIN>,platform.<DOMAIN>
DB_USERNAME=coaching_saas
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
SESSION_LIFETIME=43200
VIDEO_DRIVER=bunny
BUNNY_STREAM_ACCOUNT_API_KEY=<non-empty>
```

Any line missing, or a value still showing `coaching.test` / `local` / `true` for
`APP_DEBUG` / `false` for `SESSION_SECURE_COOKIE` → go back and fix it before continuing.

### 4.5 Generate the application key

```bash
php artisan key:generate --force
```

**Expected:** `INFO  Application key set successfully.`

**Why `--force`:** `KeyGenerateCommand` only asks for confirmation when an `APP_KEY` is
*already* present (`vendor/laravel/framework/.../KeyGenerateCommand.php:83` →
`ConfirmableTrait`), so a brand-new `.env` copied from `.env.example` (which has `APP_KEY=`)
would work either way. But in production a *re*-run would stop and prompt
`Application in production! Do you really wish to continue?`, which hangs an unattended
session. `--force` makes the command correct on both paths, and it's what `docs/DEPLOY.md` §3
shows.

**Confirm:**

```bash
grep -c '^APP_KEY=base64:' .env
```

**Expected:** `1`

---

## 5. Build and migrate

All **as `<SITE_USER>`**, in `<APP_DIR>`, with Node active (`node -v` works — §2.6).

### 5.1 Composer install (production dependencies)

```bash
cd <APP_DIR>
composer install --no-dev --optimize-autoloader
```

**Expected:** `Generating optimized autoload files`, exit 0.
**Confirm:** `php artisan --version` prints the Laravel version.

> **`--no-dev` removes Pest, Pint and PHPStan.** After this line you can no longer run the
> five gates on this box (§1.1). That's intended.

### 5.2 Front-end build

```bash
npm ci && npm run build
```

**Expected:** `npm ci` installs exactly `package-lock.json`; `npm run build` ends with a Vite
`✓ built in …` line and exit 0.

**Do NOT add `--omit=dev`.** Vite, Tailwind and `laravel-vite-plugin` are all in
`devDependencies`. `npm ci --omit=dev` produces a tree with no `vite` binary and
`npm run build` fails with `vite: not found`.

**Confirm:**

```bash
test -f public/build/manifest.json && echo BUILD_OK
```

**Expected:** `BUILD_OK`

### 5.3 Migrate

```bash
php artisan migrate --force
```

**Expected:** `INFO  Running migrations…` on a fresh database, exit 0. On a re-run:
`Nothing to migrate.`

`--force` is mandatory — without it Laravel stops and asks before running in production.

**Confirm:**

```bash
php artisan migrate --force
```

**Expected:** `Nothing to migrate.`

### 5.4 Optimize

```bash
php artisan optimize
```

**Expected:** four lines, all `DONE`:

```
 INFO  Caching framework bootstrap, configuration, and metadata.
 config .. DONE
 events .. DONE
 routes .. DONE
 views .. DONE
```

(`views` takes ~12 s on a cold cache — that's normal.)

**`route:cache` is part of this command.** The Phase P1 fix
(`docs/specs/phase-p1-platform.md`) exists specifically so this doesn't fail. It has been
verified to pass on this checkout.

**Confirm:** exit 0 and all four `DONE`.

**If `routes` fails: stop and report.** Do not paper over it with `php artisan route:clear`
and carry on — a route-cache failure here means something regressed to a pre-P1 state, and
that is exactly the kind of thing this step exists to catch.

---

## 6. Preflight

### 6.1 Run it — it must exit 0

```bash
cd <APP_DIR>
php artisan app:preflight
echo "exit=$?"
```

**Expected:**

```
INFO  All preflight checks passed.
exit=0
```

If it instead prints `Not running in production — preflight checks are informational only
here.` with `exit=0`, your `.env` still says `APP_ENV=local` — go back to §4.4. **A no-op
preflight is not a passing preflight.**

`app:preflight` is the last build step deliberately (docs/DEPLOY.md §4): it's the smoke test
that the deploy left the app in a safe state, and a non-zero exit means "stop — this deploy is
not done."

**What it checks (13 checks):** `APP_DEBUG` off · `APP_KEY` set · `APP_URL` starts
`https://` · `PLATFORM_DOMAIN`/`TENANT_BASE_DOMAIN`/`CENTRAL_DOMAINS` not at the local dev
default · `SESSION_SECURE_COOKIE=true` · `SESSION_DOMAIN` null/empty · `storage/` and
`bootstrap/cache/` writable · database reachable · MySQL/MariaDB ≥ minimum · no demo tenant /
`demo.<base>` domain / `admin@coaching.test` platform admin · no `demo.localhost` domain ·
GD + FreeType · `VIDEO_DRIVER`.

### 6.2 Common failures and their fixes

The first column quotes the command's real output verbatim.

| Symptom | Cause | Fix |
|---|---|---|
| `Not running in production — preflight checks are informational only here.` | `APP_ENV` is still `local` | §4.4: `APP_ENV=production` |
| `APP_DEBUG is true — must be false in production.` | `.env.example` ships `true` | §4.4: `APP_DEBUG=false` |
| `APP_KEY is empty — run php artisan key:generate.` | step 4.5 skipped or failed | re-run `php artisan key:generate --force` |
| `APP_URL must start with https:// (got: "…").` | still `http://localhost:8000` or missing scheme | §4.4: `APP_URL=https://<DOMAIN>` |
| `PLATFORM_DOMAIN / TENANT_BASE_DOMAIN / CENTRAL_DOMAINS are not configured for production (still at the local dev default).` | still `coaching.test`, or `CENTRAL_DOMAINS` empty | §4.4: all three keys |
| `SESSION_SECURE_COOKIE is not true — session cookies would be sent over plain HTTP.` | `.env.example:48` ships `false` | §4.4: `SESSION_SECURE_COOKIE=true` |
| `SESSION_DOMAIN is set (config: …) — it must be empty/null so session cookies stay host-only.` | someone set `SESSION_DOMAIN=.example` "to make it work" | §4.4: `SESSION_DOMAIN=null`. **Never widen it** — a shared cookie domain sends one tenant's session cookie to every other tenant subdomain (SECURITY.md). *(This check was added by Phase 11; `docs/DEPLOY.md` §5's claim about it is now accurate.)* |
| `Not writable: …storage` / `…bootstrap/cache` | ownership after cloning as a different user | `chown -R <SITE_USER>:<SITE_USER> <APP_DIR>/storage <APP_DIR>/bootstrap/cache` |
| `Database is unreachable: …` | wrong `DB_*`, or MySQL not running | §3 + §4.4; `mysql -ucoaching_saas -p -e 'SELECT VERSION();'` |
| `MySQL 8.0.15 is below the minimum 8.0.16 — the users.email/phone CHECK constraint is silently ignored…` (or the MariaDB equivalent) | engine too old | §2.4 — reinstall/select a supported engine |
| `A demo tenant/account exists — never seed demo data in production …` | a `Demo Institute` tenant, a `demo.<base>` domain row, or an `admin@coaching.test` platform admin exists — i.e. someone ran `php artisan db:seed` (docs/DEPLOY.md §4 forbids it) or the DB was copied from dev | Do **not** seed. If it already happened, delete those demo-only rows or restore a clean database |
| `The demo.localhost domain exists — this is a local PWA-testing-only domain and must never exist in production.` | same cause | delete that `tenant_domains` row |
| `The GD PHP extension is not loaded…` / `…was built without FreeType support…` | site's PHP has no `gd`, or a build without FreeType | CloudPanel → site → PHP Settings → enable `gd`; re-check with §2.3's `php -r` line |
| `VIDEO_DRIVER=fake is never allowed in production.` | `.env.example` ships `VIDEO_DRIVER=fake` (the local default) and the production `.env` never overrode it | set `VIDEO_DRIVER=bunny` (§4.4) |
| `VIDEO_DRIVER=bunny but BUNNY_STREAM_ACCOUNT_API_KEY is not set.` | the key line wasn't added | add `BUNNY_STREAM_ACCOUNT_API_KEY=<BUNNY_ACCOUNT_KEY>` (§4.4) |

---

## 7. Cloudflare + TLS

This whole section runs in the Cloudflare dashboard (and CloudPanel's vhost editor), not the
shell — except the `curl` verifications at the end.

### 7.1 DNS — proxied, not DNS-only

Cloudflare → DNS → Records:

| Type | Name | Content | Proxy |
|---|---|---|---|
| `A` | `@` | `<SERVER_IP>` | **Proxied** (orange cloud) |
| `A` | `*` | `<SERVER_IP>` | **Proxied** (orange cloud) |

The wildcard record is what serves every `*. <DOMAIN>` tenant subdomain. Do **not** also create
a `www` record (§1.7 item 6).

**Confirm:** both records show the orange cloud.

### 7.2 SSL/TLS mode and origin certificate

1. SSL/TLS → Overview → mode: **Full (strict)**. Not "Full", not "Flexible" — the origin must
   present a certificate Cloudflare will actually verify.
2. SSL/TLS → Origin Server → **Create Certificate**. Hostnames: `<DOMAIN>` and `*.<DOMAIN>`.
   Generate a private key (or paste yours).
3. Install the returned certificate + key on the server so **both** the CloudPanel-managed
   site for `<DOMAIN>` **and** the hand-written wildcard server block from docs/DEPLOY.md §0
   present the same wildcard cert.

CloudPanel → site → **SSL/TLS** is where the CloudPanel-managed half goes; the wildcard
server block references the same files. docs/DEPLOY.md §0 marks the exact file paths
**VERIFY** — CloudPanel's vhost layout has changed between major versions, so check them once
you're on the box.

**Confirm:** the cert covers both `<DOMAIN>` and `*.<DOMAIN>`.

### 7.3 HTTPS is mandatory — and the app does **not** enforce it

There is no HTTPS-redirect middleware anywhere in this codebase (verified: no
`forceScheme`/`forceHttps`/`HTTP_X_FORWARDED_PROTO` handling in `app/`, `bootstrap/` or
`config/app.php`). If you skip this step, `http://` keeps working — and the PWA **cannot
install at all**, because service workers only register in a secure context.

Cloudflare → SSL/TLS → Edge Certificates → **Always Use HTTPS: On**.

(nginx's `80 → 443` redirect from docs/DEPLOY.md §0's wildcard block is the belt-and-braces
version of the same thing.)

**Confirm:**

```bash
curl -sI http://<DOMAIN>/ | head -3
```

**Expected:** `HTTP/1.1 301 Moved Permanently` with a `location: https://<DOMAIN>/` header.

### 7.4 Cache rule for `/sw.js` and `/manifest.webmanifest`

Cloudflare → Caching → Cache Rules → create a rule matching

```
(http.request.uri.path eq "/sw.js") or (http.request.uri.path eq "/manifest.webmanifest")
```

with **Cache eligibility: Bypass cache** (docs/DEPLOY.md §1.5). An owner changing their
institute's name, colour or logo must take effect for visitors within minutes. Static assets
under `/build/…` can stay on Cloudflare's normal long cache — they're content-hashed by Vite.

**Confirm:** the rule exists and is **deployed** (rules show as Active).

### 7.5 Response security headers

The application deliberately does **not** set these itself — Cloudflare does, because it
already terminates TLS and answers before the origin (SECURITY.md "Response security headers",
docs/DEPLOY.md §1.6):

- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`

Use a **short `max-age` on the first deploy** (e.g. `300`) and raise it to `31536000` once
you're certain the origin is HTTPS-only for good — HSTS is painful to walk back.

An app-level `Content-Security-Policy` is **out of scope** and deferred: SECURITY.md records
the exact inline-handler/script/style counts (17 inline handlers, 3 inline `<script>` blocks,
201 `style="…"` attributes across 29 templates) that would have to be nonced first, and a
strict CSP would break the Phase 4 YouTube preview.

**[UNVERIFIED]** the exact Cloudflare dashboard labels above are not confirmed against a live
account (`docs/SECURITY_PASS.md` flags this). Check them in your dashboard and correct
docs/DEPLOY.md §1.6 if they've moved.

**Confirm:** §7.7's `curl` shows all three headers.

### 7.6 Trusted proxies

Nothing to configure. `config/cloudflare.php` already enumerates Cloudflare's published IP
ranges, and the app only honours `X-Forwarded-For` / `X-Forwarded-Proto` from those ranges.
Cloudflare rotates them occasionally — refresh via
`curl -s https://www.cloudflare.com/ips-v4` and `ips-v6` if you ever suspect drift (comment at
the top of that file explains when).

### 7.7 WAF rate limits at the edge (optional but recommended)

Cloudflare → Security → WAF → Rate limiting rules → create one per endpoint:

| Matching | Requests | Period | Action |
|---|---|---|---|
| `http.request.uri.path eq "/login"` | e.g. 5 | 60 s | Managed Challenge (or Block) |
| `http.request.uri.path eq "/admin/login"` | e.g. 5 | 60 s | Managed Challenge |

This is **in addition to**, not instead of, the application's own `throttle` middleware on
those routes (SECURITY.md) — the edge rule stops the flood before it reaches PHP. **[VERIFY]**
how many rate-limiting rules your Cloudflare plan allows; the free tier's quota has changed
more than once.

### 7.8 Verify everything, including `Referrer-Policy`

The `SetReferrerPolicy` middleware (`app/Http/Middleware/SetReferrerPolicy.php:22`) sets
`strict-origin-when-cross-origin` on every web response. Cloudflare must not strip or override
it — the Phase 4 YouTube iframe embed depends on the referrer behaviour.

```bash
curl -sI https://<DOMAIN>/ | grep -Ei '^(HTTP/|strict-transport-security|x-frame-options|x-content-type-options|referrer-policy)'
```

**Expected:**

```
HTTP/2 200
strict-transport-security: max-age=…; includeSubDomains
x-frame-options: SAMEORIGIN
x-content-type-options: nosniff
referrer-policy: strict-origin-when-cross-origin
```

If `referrer-policy` is missing or differs, something in Cloudflare (usually a Transform Rule
or a header-optimisation setting) is overriding the app — fix Cloudflare, not the app.

**Also confirm the central home page and the health route:**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/up
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/admin/login
```

**Expected:** `200`, `200`, `200`. (`/up` is Laravel's health route and needs no tenant.)

---

## 8. Scheduled tasks

### 8.1 Run the scheduler by hand once, first

Before trusting cron, see its output once:

```bash
cd <APP_DIR>
php artisan schedule:run
```

**Expected:** it reports the due tasks (nothing is due at most times of day, so "No scheduled
commands are due" is a *passing* answer — this step only proves the artisan call works in this
environment).

Then install the cron entry (CloudPanel → site → **Cron Jobs**, or the root crontab):

```
* * * * * cd <APP_DIR> && php artisan schedule:run >> /dev/null 2>&1
```

**Confirm:**

```bash
crontab -l -u <SITE_USER>
```

**Expected:** the line above, exactly once.

> **Recommendation:** `docs/DEPLOY.md` §6 redirects output to `/dev/null`, which means a
> failing scheduled command is invisible. Consider `>> <APP_DIR>/storage/logs/scheduler.log
> 2>&1` instead — that is how the §1.8 compressor defect would have surfaced hours sooner.
> Your call; both are correct, only one is debuggable.

### 8.2 Verify all four tasks are registered

```bash
cd <APP_DIR>
php artisan schedule:list
```

**Expected** (times/`Next Due` will differ; the four command lines must all be present):

```
 0 2 * * *  php artisan backup:run
 30 1 * * * php artisan backup:clean
 * * * * *  php artisan videos:sync
 0 0 * * *  php artisan devices:prune
```

**Confirm:** all four appear. If `backup:run` or `backup:clean` is missing, `routes/console.php`
didn't load — that's a stop.

What they do: `backup:run` = daily DB + private-storage archive at 02:00;
`backup:clean` = 14-day retention at 01:30; `videos:sync` = polls Bunny every minute for
still-processing videos (no webhooks exist yet); `devices:prune` = drops device rows unseen for
60+ days.

> **`videos:sync` runs every minute.** That is by design (docs/specs/phase-5-video.md), but
> it means one HTTP request per minute to Bunny forever. If you'd rather throttle it, that's a
> code change — raise it as a finding, don't edit it during deploy.

### 8.3 Offsite backup pull (the second destination from §1.6)

Run **on `<OFFSITE_HOST>`**, not on the VPS — a backup that only lives on the server it
protects against isn't a backup (docs/DEPLOY.md §7):

```bash
rsync -avz --delete <SITE_USER>@<SERVER_IP>:<APP_DIR>/storage/app/backups/ <OFFSITE_PATH>/
```

Add it to `<OFFSITE_HOST>`'s crontab for daily execution.

**Confirm:**

```bash
ls -la <OFFSITE_PATH>/
```

**Expected:** at least one `*.zip` after §10.11 runs.

---

## 9. First tenant

### 9.1 Create the platform admin (once, interactively)

```bash
cd <APP_DIR>
php artisan platform-admin:create
```

It prompts for name / email / password. Choose a real address you control.

**Confirm:** it reports success; you can reach `https://<DOMAIN>/admin/login` and sign in.

> **Never seed a platform admin.** `php artisan db:seed` is forbidden in production
> (docs/DEPLOY.md §4) and preflight actively fails if `admin@coaching.test` exists.

### 9.2 Create the institute (tenant #1)

**Preferred path — the admin UI**, because it's the same path you'll use for every later
institute:

1. Sign in at `https://<DOMAIN>/admin/login`
2. **Institutes → Add** (`/admin/institutes/create`)
3. Institute name, subdomain (lowercase, digits, hyphens: `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$`),
   owner name, and **at least one** of owner email or owner phone (`CreateInstituteAction`
   requires one of the two).

**Fallback — the CLI** (identical validation, same `App\Actions\CreateInstituteAction`):

```bash
cd <APP_DIR>
php artisan tenant:create "Institute Name" subdomain --owner-name="Owner Name" --owner-email=owner@example.com
```

**Do NOT use `TenantSeeder`.** It only runs in local/testing.

**Confirm:** either path ends by showing the owner's **temporary password once** — copy it
immediately and hand it over through a secure channel. It is not stored or shown again
(`must_change_password` is set, so the owner is forced to change it on first login).

**Confirm the domain resolves:**

```bash
nslookup subdomain.<DOMAIN>
```

**Expected:** `<SERVER_IP>` (through Cloudflare).

### 9.3 Owner logs in for the first time

Give the owner the URL `https://subdomain.<DOMAIN>`, the identifier and the temporary
password.

**Confirm:** they sign in, are forced to change the password, and land on the dashboard.

### 9.4 Run the 360px walkthrough

Now hand them the phone and work through [docs/PILOT_CHECKLIST.md](PILOT_CHECKLIST.md) —
rows 1–17 on a 360px viewport, then rows 18–25 (the deploy-specific ones this runbook added).

**Confirm:** every row's "Problem?" column is either empty or has a note you've triaged.

Then go straight to §10.

---

## 10. Post-deploy verification checklist

Run this **on a real phone, in front of the real tenant**, after §9. It is the acceptance
test for the deploy. Work top to bottom; each row is independent enough to retry.

**First, the universal failure handler** — for every row below, if something fails:

```bash
tail -n 80 <APP_DIR>/storage/logs/laravel.log
```

Read the last error, then decide: **roll forward** (the site works but one feature misbehaves →
fix on `main`, redeploy) or **roll back** (the site is broken/500 → §11). Data loss or a
corrupted database is the only case where you reach for a restore.

| # | Check | What to do | What success looks like | If it fails |
|---|---|---|---|---|
| 10.1 | PWA installs on a real Android phone | On the phone, open `https://<tenant-hostname>` in Chrome → menu → **Add to Home screen** / install prompt | The app installs with the institute's icon and opens in its own window with **no browser URL bar** | Most common cause is not a secure context: re-check §7.3 (`curl -sI http://<tenant>/ ` → `301`) and that `sw.js`/`manifest.webmanifest` are served with `200` on the `https://` host. Also check §7.4's bypass-cache rule is Active. Then `tail` the log |
| 10.2 | Owner profile: name, logo, UPI id | As owner → Settings → set display name, upload a logo, save the institute **UPI id** (Phase 10 shipped) | All three persist after a reload; the logo appears in the header/PWA icon; the UPI id is stored (it is re-read server-side, never trusted from the browser — AGENT_RULES #10) | Check `storage/logs/laravel.log` for an upload/GD error; if GD is missing, §6.2's GD row applies. Confirm `.env` has no stale `FILESYSTEM_DISK` override |
| 10.3 | Course → chapter → lesson → PDF attachment | As owner: create a course, add a chapter, add a lesson, attach a PDF to the lesson | Each step saves and the PDF is downloadable **only by a logged-in member of that tenant** | A 403/404 on the attachment usually means a tenant-resolution problem — check `PLATFORM_DOMAIN`/`TENANT_BASE_DOMAIN` (§4.4). Never "fix" this by making storage public (AGENT_RULES #11) |
| 10.4 | Real Bunny video upload + playback | As owner: upload a small (<50 MB) lesson video. As a student on a second phone, open the lesson and play it | Upload reaches 100%, the lesson shows a finished state, and playback starts for the student | First ever upload for a tenant provisioned its Bunny library automatically — a failure here surfaces as a teacher-facing error. Check `BUNNY_STREAM_ACCOUNT_API_KEY` (§4.4) and `VIDEO_DRIVER=bunny`. Then `tail` the log |
| 10.5 | Bulk import + WhatsApp credential + student login | As owner: People → Import from spreadsheet → upload a small CSV → create → share a credential over WhatsApp → log in as that student **on a second phone** | Preview shows row statuses, the credentials sheet opens, the WhatsApp button opens `wa.me` pre-filled, and the student logs in on phone #2 | A bad CSV row fails at preview, not silently. If login fails, check the credential hasn't expired (15-minute window) and that `SESSION_SECURE_COOKIE=true` (§4.4) — a `false` here means the cookie is never sent over HTTPS |
| 10.6 | Student: watch video, progress heartbeat, mark a note-lesson complete | As that student: play the video, leave it running a few seconds, then open a text ("note") lesson and mark it complete | The teacher's progress data updates; the note lesson shows completed | Progress heartbeat needs `CACHE_STORE=database` + the `cache` table (created by §5.3). If it's missing, `php artisan migrate:status` |
| 10.7 | Student installs the PWA on their own phone | Same as §10.1, but on the student's phone, on `https://<tenant-hostname>` | Installs and opens standalone | Same causes as §10.1 |
| 10.8 | Second login evicts the first (Phase 8 device limit) | Log in as the same student on a **second** device while the first is still logged in | The **first** device is signed out on its next request with a clear "device revoked" message — not a silent session break | Check `user_devices` rows exist and `EnforceDeviceLimit` is in the middleware stack (`bootstrap/app.php`). `tail` the log |
| 10.9 | Owner password reset signs out the student's devices | As owner → People → a student → **Reset password**. Then look at the student's phone | A temporary password is shown **once**; the student's other devices are signed out on their next request; the student logs back in with the temporary password and is forced to change it | This revocation happens only when the reset target's role is `Student` (`UserController::resetPassword`). Resetting a *staff* account does **not** revoke devices — that's intended, not a bug |
| 10.10 | Teacher's progress page reflects the student's activity | As owner/teacher, open the course's progress page | The video progress and the note-lesson completion from §10.6 appear | Stale data usually means the page is being cached at the edge — check §7.4's cache rules only cover `sw.js`/`manifest.webmanifest`; if progress HTML is cached, that's an unintended Cloudflare cache setting to remove |
| 10.11 | `backup:run` produces a file | `cd <APP_DIR> && php artisan backup:run` | A new `*.zip` in `<APP_DIR>/storage/app/backups/`, and (after §8.3) in `<OFFSITE_PATH>/` | **Expected to succeed** since `c859e74` (§1.8). If it fails for any *other* reason, `php artisan backup:list` shows disk reachability, and `mysqldump` being absent from `PATH` is the next most likely cause — on a host where the binary exists but is not on `PATH`, set `MYSQL_DUMP_BINARY_PATH` (documented in `.env.example`) |
| 10.12 | `app:preflight` passes on the real environment | `cd <APP_DIR> && php artisan app:preflight; echo "exit=$?"` | `INFO  All preflight checks passed.` and `exit=0` | Use §6.2's table. Remember a no-op "informational only" message means `APP_ENV` isn't `production` |

**Acceptance:** all twelve rows pass. Anything else means the deploy isn't finished —
either roll forward with a fix or execute §11.

---

## 11. Rollback plan

### 11.1 Before you deploy anything: take these two things

Do this **before** step 5, every time:

```bash
cd <APP_DIR>
git rev-parse HEAD | tee ~/PREDEPLOY_SHA

mkdir -p ~/backup/predeploy
mysqldump -ucoaching_saas -p coaching_saas | gzip > ~/backup/predeploy/coaching_saas-$(date +%Y%m%d-%H%M%S).sql.gz
ls -la ~/backup/predeploy/
```

**Confirm:** `~/PREDEPLOY_SHA` holds a 40-hex sha, and a `.sql.gz` file exists.

> A `mysqldump` is the *only* reliable rollback for a schema change. The spatie archive from
> §10.11 is a daily snapshot, not a pre-deploy one — don't rely on it to undo a migration you
> ran twenty minutes ago.

### 11.2 Revert a bad deploy (code problem, schema unchanged)

```bash
cd <APP_DIR>
git log --oneline -5
git reset --hard <PREV_SHA>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
php artisan app:preflight
```

**Confirm:** `app:preflight` exits 0 and §7.8's `curl` checks are green again.

This is safe when no migration ran — old code, same schema.

### 11.3 Restore after a bad migration (schema changed)

If step 5.3 ran a migration that broke things, **old code + new schema is the dangerous
combination**. Restore the schema too, into a fresh database so tables created by the bad
migration are definitely gone:

```bash
php artisan down
mysql -uroot -p -e "DROP DATABASE coaching_saas; CREATE DATABASE coaching_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON coaching_saas.* TO 'coaching_saas'@'localhost';"
gunzip -c ~/backup/predeploy/coaching_saas-<STAMP>.sql.gz | mysql -ucoaching_saas -p coaching_saas
git reset --hard <PREV_SHA>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
php artisan up
php artisan app:preflight
```

**`DROP DATABASE` is destructive.** Confirm `~/backup/predeploy/` actually contains a file
before you run it, and confirm with the tenant's owner first if there is live data on the box.

**Confirm:** `migrate:status` shows the pre-deploy set of migrations, preflight exits 0, and
§10.1–§10.3 still work.

### 11.4 Restore from a scheduled backup (disaster recovery)

`spatie/laravel-backup` **ships no restore command** —
`vendor/spatie/laravel-backup/src/Commands/` contains only `Backup`, `Base`, `Cleanup`,
`List` and `Monitor`. Restoration is manual:

```bash
mkdir -p /tmp/restore
unzip -l <APP_DIR>/storage/app/backups/<ARCHIVE>.zip
unzip -o <APP_DIR>/storage/app/backups/<ARCHIVE>.zip -d /tmp/restore
```

Look at the listing first — exact layout varies by spatie version. You are looking for:

- the **MySQL dump**: a member ending `*.sql.gz` — produced by the gzip compressor that
  §1.8 covers (working since `c859e74`), and
- the **private storage**: members whose path ends `storage/app/private/...` — the lesson
  attachment PDFs.

Then:

```bash
php artisan down
gunzip -c /tmp/restore/<DB_DUMP>.sql.gz | mysql -ucoaching_saas -p coaching_saas
# re-create any tables the dump doesn't contain, then:
rsync -a /tmp/restore/<path-to>/storage/app/private/ <APP_DIR>/storage/app/private/
php artisan up
php artisan optimize
php artisan app:preflight
```

**Confirm:** preflight exits 0, an attachment PDF downloads for a logged-in student, and §10.12
passes.

### 11.5 Roll forward or roll back?

| Situation | Action |
|---|---|
| Site 500s / white screen / can't log in | **Roll back** — §11.2 (or §11.3 if a migration ran) |
| Site works, one feature misbehaves | **Roll forward** — fix on `main`, run the gates, redeploy |
| A migration corrupted or lost data | **Restore** — §11.3 or §11.4 |
| Unclear | Look at `storage/logs/laravel.log` first; don't roll back "just in case", because rolling back across a schema change without a restore is how you make it worse |

**Rollback in one paragraph:** to undo a bad deploy, note the current `git rev-parse HEAD`,
then `git reset --hard <PREV_SHA>` in `<APP_DIR>` and re-run the build block
(`composer install --no-dev --optimize-autoloader && npm ci && npm run build &&
php artisan optimize && php artisan app:preflight`) — that alone is enough whenever no
migration ran. If a migration *did* run, the old code plus the new schema is the failure mode
you're trying to escape, so take the app down (`php artisan down`), drop and recreate
`coaching_saas`, pipe the pre-deploy `mysqldump` from §11.1 back in, check out the previous
sha, rebuild, `php artisan up`, and re-run preflight; for total loss use the newest
`storage/app/backups/*.zip` from §11.4 (manual unzip → SQL dump + private files, since
spatie ships no restore command). Roll **forward** instead whenever the site is fundamentally
healthy and only one feature is wrong — fixes are cheaper and safer than restores.

---

## 12. Quick reference — every command, in order

Substitute placeholders first. `[local]` = your laptop; everything else = the VPS.

```bash
# ── §1 Preconditions ─────────────────────────────────────────────── [local]
git fetch --tags origin
git checkout main && git pull --ff-only
git log --oneline -1
composer test
composer test:mysql
composer test:js
composer lint
composer analyse
git tag -l phase-11
git ls-remote --tags origin phase-11
nslookup -type=NS <DOMAIN>

# ── §2 Provisioning ─────────────────────────────────────────────── as root
ssh root@<SERVER_IP>
lsb_release -d
apt update && apt -y upgrade && apt -y install curl wget sudo
curl -sS https://installer.cloudpanel.io/ce/v2/install.sh -o install.sh; \
echo "8146dbe0a488e7088b04071b0c34d59aa0ab1fe9dcec382d395fd155c9e6c476 install.sh" | \
sha256sum -c && sudo DB_ENGINE=MYSQL_8.4 bash install.sh
which clpctl && systemctl is-active nginx
mysql -V
mysql -uroot -p -e "SELECT VERSION();"
nginx -v
composer -V

# ── §2.6 Node (as <SITE_USER>) ──────────────────────────────────── as site user
ssh <SITE_USER>@<SERVER_IP>
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.5/install.sh | bash
source ~/.bashrc
nvm install 24
nvm use 24
node -v
npm -v

# ── §3 Database ─────────────────────────────────────────────────── as root
mysql -uroot -p <<'SQL'
CREATE DATABASE coaching_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'coaching_saas'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
GRANT ALL PRIVILEGES ON coaching_saas.* TO 'coaching_saas'@'localhost';
FLUSH PRIVILEGES;
SELECT VERSION();
SQL
mysql -ucoaching_saas -p -e "SHOW DATABASES;"

# ── §4 Clone + .env ─────────────────────────────────────────────── as site user
cd ~/htdocs
rm -rf <DOMAIN>
git clone https://github.com/toletmelearn/coaching-saas.git <DOMAIN>
cd <DOMAIN>
git fetch --tags && git checkout main && git pull --ff-only
git log --oneline -1
composer install --no-dev --optimize-autoloader
php artisan --version
cp .env.example .env
# ... edit .env by hand per §4.4 ...
php artisan key:generate --force
grep -c '^APP_KEY=base64:' .env

# ── §5 Build and migrate ────────────────────────────────────────── as site user
cd <APP_DIR>
composer install --no-dev --optimize-autoloader
php artisan --version
npm ci && npm run build
test -f public/build/manifest.json && echo BUILD_OK
php artisan migrate --force
php artisan migrate --force            # expect: Nothing to migrate.
php artisan optimize

# ── §6 Preflight ────────────────────────────────────────────────── as site user
php artisan app:preflight
echo "exit=$?"

# ── §7 Verification (Cloudflare configured first) ───────────────── from any host
curl -sI http://<DOMAIN>/ | head -3
curl -sI https://<DOMAIN>/ | grep -Ei '^(HTTP/|strict-transport-security|x-frame-options|x-content-type-options|referrer-policy)'
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/up
curl -s -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/admin/login

# ── §8 Scheduler ────────────────────────────────────────────────── as site user
cd <APP_DIR>
php artisan schedule:run
crontab -l -u <SITE_USER>
php artisan schedule:list

# ── §8.3 Offsite pull ───────────────────────────────────────────── on <OFFSITE_HOST>
rsync -avz --delete <SITE_USER>@<SERVER_IP>:<APP_DIR>/storage/app/backups/ <OFFSITE_PATH>/

# ── §9 First tenant ────────────────────────────────────────────── as site user
cd <APP_DIR>
php artisan platform-admin:create
php artisan tenant:create "Institute Name" subdomain --owner-name="Owner Name" --owner-email=owner@example.com
nslookup subdomain.<DOMAIN>

# ── §11.1 Pre-deploy snapshot (before EVERY subsequent deploy) ──── as site user
cd <APP_DIR>
git rev-parse HEAD | tee ~/PREDEPLOY_SHA
mkdir -p ~/backup/predeploy
mysqldump -ucoaching_saas -p coaching_saas | gzip > ~/backup/predeploy/coaching_saas-$(date +%Y%m%d-%H%M%S).sql.gz
```

**Repeat-deploy block** (§4.1 onward is first-deploy only; from the second deploy on, a
deploy is just):

```bash
cd <APP_DIR>
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan app:preflight
```

**Do not run** `php artisan db:seed` at any point (docs/DEPLOY.md §4).

---

## 13. Values you must supply by hand

Nothing below has a usable default. Have all of it before step 1 starts.

| # | Value | Used in | Notes |
|---|---|---|---|
| 1 | `<DOMAIN>` | §1.3, §4.4, §7 | The registered domain; also becomes `PLATFORM_DOMAIN` and `TENANT_BASE_DOMAIN` |
| 2 | Cloudflare account + zone | §1.3, §7 | Zone must be **Active** (name servers pointed) |
| 3 | `<SERVER_IP>` | §7.1, §12 | VPS public IPv4 |
| 4 | Hostinger plan / OS choice | §1.4 | **Ubuntu 24.04 LTS** recommended |
| 5 | Root SSH access | §2, §3 | Password or key; Hostinger's web terminal works |
| 6 | CloudPanel admin username/password | §2.2 | Create **immediately** after install (bot window); lock port 8443 to your IP |
| 7 | `<SITE_USER>` + password | §2.3, §4 | CloudPanel site user |
| 8 | PHP version offered by your CloudPanel | §2.3 | **[VERIFY]** 8.3 is in the dropdown; if not, stop and ask |
| 9 | `<DB_PASSWORD>` | §3, §4.4 | Generate: `openssl rand -base64 24` |
| 10 | Whether to create `coaching_saas_test` | §3 | Only if you'll run `composer test:mysql` on the server — probably no |
| 11 | `<BUNNY_ACCOUNT_KEY>` | §4.4 | Account-level API key, **not** a library key |
| 12 | `MAIL_FROM_ADDRESS` (+ SMTP, optional) | §4.4 | `MAIL_MAILER=log` is a safe default |
| 13 | UPI id for the pilot institute | §10.2 | Supplied by the tenant, entered in Settings |
| 14 | `<OFFSITE_HOST>` / `<OFFSITE_PATH>` | §1.6, §8.3 | A machine that is **not** the VPS |
| 15 | Subdomain for tenant #1 | §9.2 | `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$` |
| 16 | Owner contact (email **or** phone) | §9.2 | `CreateInstituteAction` requires at least one |
| 17 | WAF rate-limit quota on your plan | §7.7 | **[VERIFY]** in your Cloudflare dashboard |

---

## 14. Open questions — answer these before step 1

1. **Node version: 20 (as briefed) or 24 (as pinned)?** Node 20 reached end-of-life on
   2026-04-30; Node 24 is Active LTS and satisfies Vite 8.3.1's
   `^20.19.0 || >=22.12.0`. §2.6 pins 24. Confirm or override.

2. **Is PHP 8.3 available in your CloudPanel build?** `composer.json` allows `^8.3` (so 8.4 and
   8.5 also satisfy it), but the gates have only ever run on 8.3.29. If CloudPanel only offers
   8.4/8.5, decide knowingly — don't discover it at step 2.3.

3. **Backup alert email — RESOLVED in `c859e74`, no repo change needed.** `config/backup.php`
   now reads `env('BACKUP_NOTIFY_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com')`, and
   `.env.example` ships `BACKUP_NOTIFY_EMAIL=` (blank → falls back to `MAIL_FROM_ADDRESS`).
   What remains *your* job at deploy time: set `BACKUP_NOTIFY_EMAIL=<an-address-you-read>` and
   give `MAIL_MAILER` a real transport — otherwise alerts still only reach
   `storage/logs/laravel.log` (§1.7 item 5, §4.4).

4. **Documentation drift in `README.md` — RESOLVED in `c21ca36`.** `README.md:123` now reads
   "Run (Phase 11) — docs/SECURITY_PASS.md", and the gate table has been corrected twice
   (762/730 → 822/790 → 824/792, the last from the two `backup` canaries added in `c859e74`).
   It has drifted one further behind since — `c61a8bc` added a test, so current is 825/793
   (§1.7 item 3). Recorded here rather than deleted so the drift history stays visible.

5. **Two `[UNVERIFIED]` items carried from `docs/SECURITY_PASS.md`:** the exact Cloudflare
   dashboard labels for §7.5's security headers, and the rate-limiting rule quota in §7.7.
   Neither is confirmed against a live account — check both in your dashboard at deploy time.

6. **CI already runs every gate in §1.1 — check it before you start step 5.** *(Not an open
   question — an addition to this list on 2026-10-03.)* Two workflows were added after this
   runbook was written and it did not originally reference them:

   - `.github/workflows/ci.yml` (since `9ff5f11`) runs on **every push** and on every PR
     targeting `main`. One job, five gates run **sequentially** (the same
     process-timeout reasoning as §1.1), against a `mysql:8.4` service container:
     Gate 1 lint → Gate 2 analyse → Gate 3 SQLite suite (`backup` group excluded) → Gate 4 JS
     → Gate 5 MySQL suite. This is the only place the suite runs against real MySQL 8.4 —
     see the engine caveat in §1.1.
   - `.github/workflows/backup-canary.yml` (since `b6d2e61`) runs nightly at **03:00 UTC**
     and on `workflow_dispatch`. It does not call `backup:run` directly — it runs
     `composer test -- --group=backup`, i.e. the execution canary from §1.8, which shells out
     to `backup:run --only-db` against SQLite (ubuntu-latest ships `sqlite3`). The MySQL side
     of that same pipeline is exercised by CI gate 5.

   What to do: `gh run list --limit 1` and require `completed / success` on the exact commit
   you are deploying. Do **not** treat a red gate as a local-only problem — the first red run
   (`37036620403`) found a real ordering bug in `DeviceRegistrar::enforceLimit()` that every
   local run had passed, fixed in `c61a8bc`. **A gate failure in CI that passed locally is
   reported raw and fixed at the root, never worked around by weakening the gate.**

*Removed from this list on 2026-10-02 (both now closed): the backup compressor defect — fixed
in `c859e74`, full write-up preserved in §1.8 — and the uncommitted
`tests/Feature/Video/EmbedTokenPlaybackTest.php` flake fix — committed as `b9063a3`.*
