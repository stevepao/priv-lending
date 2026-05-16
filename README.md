# priv-lending

A small PHP application for **direct private lending** workflows: borrowers, borrowing entities, loans, cash events, monthly checks, reporting, and payoff statements. It is meant for **personal use by the operator**, not as a multi-tenant product.

**Copyright © 2026 Hillwork, LLC.** Licensed under the [MIT License](LICENSE).

**Version history:** see [CHANGELOG.md](CHANGELOG.md).

---

## What you need

- **PHP** 8.1+ with extensions `pdo`, `pdo_mysql`, `session`, `json`, and `mbstring` (recommended for mail/CSS libs). In hosting panels, select this version for the **site** (not only CLI).
- **Composer** on the machine where you install dependencies (or run `composer install` locally and deploy `vendor/`).
- **MySQL** 8.x (or compatible) and a database you control.
- A web server whose **document root** is the `public/` directory (see below). For clean URLs, configure rewrites like `public/.htaccess` (Apache) or an equivalent `try_files` rule (nginx).

---

## Install and run

### 1. Get the application

Use a release archive, or clone/pull your copy of the repository into a directory on the server (or your local machine).

### 2. Dependencies

From the project root:

```bash
composer install --no-dev
```

(Use `composer install` if you want dev tooling.) This pulls PHP libraries used for email sign-in and PDF payoff statements, among other things.

### 3. Environment

```bash
cp .env.example .env
```

Edit `.env`. At minimum set **database** credentials:

| Variable   | Description   |
|------------|---------------|
| `DB_HOST`  | MySQL host    |
| `DB_NAME`  | Database name |
| `DB_USER`  | MySQL user    |
| `DB_PASS`  | MySQL password |

Useful optional settings:

| Variable | Purpose |
|----------|---------|
| `APP_DEBUG` | `true` only while debugging (shows PHP errors in the browser). |
| `DISABLE_CSP` | `true` if your host breaks pages due to Content-Security-Policy. |
| `LENDER_*` | Name, address, phone, email for payoff statement letterhead (see `.env.example`). |

**Sign-in** is email OTP: set `AUTH_ALLOWED_EMAILS`, SMTP variables, and `MAIL_FROM_*` as documented in `.env.example`.

### 4. Database

Create an empty schema (example):

```sql
CREATE DATABASE priv_lending CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Grant your `DB_USER` full rights on that database.

Apply migrations from the project root:

```bash
php bin/migrate.php
```

This records applied files in `schema_migrations` and runs `migrations/*.sql` in filename order. Re-run after upgrades to pick up new migration files.

### 5. Web server

**Document root must be `public/`** so `app/`, `bin/`, `migrations/`, and `.env` are not directly web-accessible.

- **Apache:** point `DocumentRoot` at `.../priv-lending/public` and allow `.htaccess` overrides (see `public/.htaccess`).
- **nginx:** `root .../priv-lending/public;` and forward non-file requests to `index.php` (same intent as `.htaccess`).

PHP’s **built-in server** (`php -S`) does not read `.htaccess`; use Apache, nginx, or another stack with proper rewrites for local testing.

### 6. Use the app

1. Open the site (e.g. `https://your-domain.example/`).
2. **Login** — request a one-time code to an allowed email, then verify (see `.env.example`).
3. Create data in a sensible order: **Borrowers** → **Entities** → **Loans** (parents must exist for foreign keys).
4. Use **Checks** for scheduled monthly postings, **Bank** for LOC interest, **Report** for period rollups, **Payoff** for payoff statements and PDFs.

---

## Upgrading

- Pull or unpack the new version, run `composer install --no-dev` if dependencies changed, then `php bin/migrate.php` again.
- If you have an **old** database that predates certain columns, you may need one-off SQL (see comments in older migrations or your DBA notes). Fresh installs only need `php bin/migrate.php`.

Detailed manual test scenarios live in **[app/MANUAL_VALIDATION_CHECKLIST.md](app/MANUAL_VALIDATION_CHECKLIST.md)** (optional for day-to-day use).

---

## Troubleshooting

1. Confirm **document root** is **`public/`** (the folder that contains `index.php`).
2. Set **`APP_DEBUG=true`** briefly, reproduce the issue, read the error, then set it back to **`false`**.
3. Check your host’s **PHP error / access logs** for the same timestamp.
4. Optional: open **`public/health.php`** once to verify PHP and `pdo_mysql`; remove or protect it if you expose the site publicly.

**Architecture (short):** routing and boot in `public/index.php`; PDO in `app/lib/db.php`; loan/check/payoff money logic largely in `app/lib/lending_domain.php` and `app/lib/payoff_helpers.php`; sessions and CSRF in `app/lib/session.php` and `app/lib/csrf.php`; security headers in `app/lib/security_headers.php`.

---

## Disclaimer

This software is provided **as-is** for personal operational support. It is **not** legal, tax, or investment advice. You are responsible for compliance with applicable laws and regulations in your jurisdiction.
