# priv-lending

A small PHP application to support **direct private lending** workflows (borrowers, entities, loans, and related records). It is intended for personal use by the operator, not as a multi-tenant SaaS product.

**Copyright © 2026 Hillwork, LLC.** Licensed under the [MIT License](LICENSE).

---

## Requirements

- **PHP** 8.1+ for the **web** SAPI (Apache/mod_php or PHP-FPM), with extensions: `pdo`, `pdo_mysql`, `session`, `json`. The version shown by `php -v` or `php8.4-cli -v` in SSH is often **CLI only**—set the PHP version for the domain in your hosting control panel.
- **MySQL** 8.x (or compatible) with a database you control
- A web server that can run PHP and, for clean URLs, **rewrite** requests to `public/index.php` (Apache `mod_rewrite` is configured in `public/.htaccess`)

---

## Quick start (local)

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/priv-lending.git
cd priv-lending
```

### 2. Environment file

```bash
cp .env.example .env
```

Edit `.env` and set at least:

| Variable   | Description        |
|-----------|--------------------|
| `DB_HOST` | MySQL host         |
| `DB_NAME` | Database name      |
| `DB_USER` | MySQL user         |
| `DB_PASS` | MySQL password     |

`APP_ENV` / `APP_DEBUG` are present for future use; the app does not depend on them today.

### 3. Create the database

Create an empty schema in MySQL (example):

```sql
CREATE DATABASE priv_lending CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Grant your `DB_USER` full rights on that database.

### 4. Run migrations

From the project root:

```bash
php bin/migrate.php
```

This creates `schema_migrations` (if needed) and applies all `migrations/*.sql` files in lexicographic order (e.g. `0002_core_tables.sql`).

### 5. Point the web server at `public/`

**Document root** must be the `public/` directory so that `app/`, `bin/`, `migrations/`, and `.env` are **not** directly web-accessible.

- **Apache:** set `DocumentRoot` to `.../priv-lending/public` and ensure `AllowOverride` permits `.htaccess` (for rewrites and deny rules).
- **nginx:** use `root .../priv-lending/public;` and a `try_files` rule that forwards non-files to `index.php` (mirror the intent of `public/.htaccess`).

### 6. Sign in and use the app

1. Open the site in a browser (e.g. `https://localhost/priv-lending/` depending on your vhost).
2. Go to **Login** and submit the form (single-user demo: user id `1`).
3. Use **Borrowers → Entities → Loans** to create records in order (foreign keys require parents to exist first).

---

## Development notes

- **Sessions & CSRF:** Handled in `app/lib/session.php` and `app/lib/csrf.php`.
- **Database:** `app/lib/db.php` (PDO, prepared statements). No ORM.
- **Security headers:** `app/lib/security_headers.php` (including CSP tuned for Tailwind’s CDN on some pages).
- **Migrations:** Add new `*.sql` files under `migrations/`; filenames must sort in apply order. Re-run `php bin/migrate.php` to apply pending files.

### PHP’s built-in server

The built-in server (`php -S`) does **not** read `.htaccess`. Path-based routes like `/login` will not resolve unless you add a small router script or use Apache/nginx. For day-to-day local work, use Apache, nginx, or a tool that supports rewrite rules (e.g. Laravel Herd, MAMP, Docker with Apache/nginx).

### HTTP 500 on shared hosting (IONOS, etc.)

1. **Web PHP is not the same as CLI PHP.** A shell command like `php8.4-cli -v` only shows the **CLI** binary. Apache may still run an older PHP for `.php` files. In your host’s control panel, set the domain (or subdirectory) to **PHP 8.1 or newer** for the site that serves `priv-lending`.

2. **Document root** must be the **`public/`** folder inside the project (not the repo root). If Apache’s document root is wrong, rewrites and `index.php` may never run correctly.

3. **Read the real error.** In IONOS Linux hosting, check **Logs** in the panel or files such as `~/logs/error.log` (exact path varies). The log line immediately after your request usually names the cause (e.g. unknown function, parse error, `Options not allowed here`).

4. **Extensions.** Ensure **`pdo`** and **`pdo_mysql`** are enabled for the **web** PHP version (not only CLI). Many panels expose a separate “PHP extensions” or “modules” list per domain.

5. **Quick probe.** With the app deployed, open **`/health.php`** on the same host. You should see `PHP 8.x`, `SAPI ...` (often `fpm` or `apache2handler`), and both extensions `yes`. If `pdo_mysql` is `no`, enable it for the site PHP and reload. **Delete `public/health.php` when you are done** so it is not left exposed.

6. **Still HTTP 500 with extensions OK?** Set **`APP_DEBUG=true`** in `.env`, reload once, and read the **PHP error message** shown in the browser (then set **`APP_DEBUG=false`** again). Common causes: the default **session save path** is not writable on shared hosting (this app falls back to **`sys_get_temp_dir()`** when needed), or TLS is terminated in front of PHP so **`HTTP_X_FORWARDED_PROTO`** must be honored for secure cookies (handled in `session.php`).

---

## Publishing to GitHub (new public repository)

Do this once on your machine (replace `YOUR_USERNAME` with your GitHub username or org).

### A. Create an empty repo on GitHub

1. Log in at [https://github.com](https://github.com).
2. Click **+** → **New repository**.
3. **Repository name:** `priv-lending` (or another name; then adjust the remote URL below).
4. **Description:** e.g. “Personal private lending tracker (PHP).”
5. Choose **Public**.
6. Leave **Add a README** unchecked (this repo already has one).
7. Click **Create repository**.

GitHub will show you commands; you can use the following instead if you already have the code locally.

### B. Push your local copy

From the project root (after `git` is initialized and you have at least one commit):

```bash
git init
git add .
git commit -m "Initial commit: priv-lending"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/priv-lending.git
git push -u origin main
```

If you use **SSH**:

```bash
git remote add origin git@github.com:YOUR_USERNAME/priv-lending.git
git push -u origin main
```

### C. Confirm on GitHub

Refresh the repository page; you should see `README.md`, `LICENSE`, and the rest of the tree. Check **Settings → General** if you want to add a description, website, or topics (e.g. `php`, `private-lending`, `mysql`).

---

## Disclaimer

This software is provided **as-is** for personal operational support. It is **not** legal, tax, or investment advice. You are responsible for compliance with applicable laws and regulations in your jurisdiction.
