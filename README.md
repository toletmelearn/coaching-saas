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

`php artisan migrate:fresh --seed` creates a demo tenant with a ready-to-use owner account:

| Field           | Value                          |
|-----------------|---------------------------------|
| Tenant domain   | `demo.coaching.test` (add it to your hosts file, see above) |
| Email           | `owner@demo.coaching.test`     |
| Password        | `password`                     |

Log in at `http://demo.coaching.test/login`. This seeded owner does **not** require a
password change on first login (unlike a user created through the app's "add person" flow,
which always generates a temporary password and forces a change — see
[docs/specs/phase-3-tenant-auth.md](docs/specs/phase-3-tenant-auth.md)).

There is no seeded platform admin account; create one via `php artisan tinker`:

```php
\App\Models\PlatformAdmin::create([
    'name' => 'Local Admin',
    'email' => 'admin@coaching.test',
    'password' => Hash::make('password'),
]);
```

Log in at `http://coaching.test/admin/login`.

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
