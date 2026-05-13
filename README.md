# priv-lending

A small PHP application to support **direct private lending** workflows (borrowers, entities, loans, and related records). It is intended for personal use by the operator, not as a multi-tenant SaaS product.

**Copyright © 2026 Hillwork, LLC.** Licensed under the [MIT License](LICENSE).

---

## Requirements

- **PHP** 8.1+ with extensions `pdo`, `pdo_mysql`, `session`, and `json`. In the control panel, pick that version for the **domain** (not only for SSH/CLI).
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

`APP_ENV` is optional. **`APP_DEBUG=true`** turns on on-screen PHP errors (use only while debugging). **`DISABLE_CSP=true`** skips the Content-Security-Policy header if your host objects to it.

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

- **Apache:** set `DocumentRoot` to `.../priv-lending/public` and ensure `AllowOverride` allows `.htaccess` (only needs `FileInfo` for the small rewrite block in `public/.htaccess`).
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

### Simple debugging

1. **Document root** = the **`public/`** folder (the one that contains `index.php` and `.htaccess`).
2. **`public/.htaccess`** should only send unknown URLs to `index.php` (no long rule chains).
3. Put **`APP_DEBUG=true`** in `.env`, reload `/login`, read any **PHP message** on the page, then set **`APP_DEBUG=false`** again.
4. In the IONOS panel, open the **error / access log** for the domain and find the line for the same second you hit the site.
5. Optional: open **`/health.php`** once to confirm PHP version and `pdo_mysql`; delete that file when finished.

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
