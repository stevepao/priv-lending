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

The `loans` table includes `principal_amount`, `annual_interest_rate` (annual percent, up to three decimal places, e.g. `12.500`), optional `monthly_interest` and `interest_calc_method` (`fixed` or `declining_balance`) and optional `principal_payment_monthly` for paydown on declining-balance checks, and `payment_type` (`interest_only`, `prepaid`, `amortizing`). There is no amortization schedule yet; interest-only and amortizing loans use the same simple monthly estimate on full principal: `principal_amount * (annual_interest_rate / 100) / 12` (see `loan_simple_monthly_interest()` in `public/index.php`). The **Checks** page (`GET /checks`) shows an **expected payment** column. **Fixed** method: stored `monthly_interest` when set, otherwise the same simple full-principal monthly interest as above. **Declining balance**: interest on beginning-of-month remaining principal (half-up to 2 decimals) plus constant `principal_payment_monthly` as the principal portion; the primary figure is the sum (both rounded to 2 decimals before display).

#### Upgrading an existing database (old `interest_type` / `monthly_interest`)

Only run this if your `loans` table still has `interest_type` (for example you applied an older `0002_core_tables.sql` before principal fields existed). If you already have `payment_type` and `principal_amount`, skip this block. If you have `interest_rate` but not `annual_interest_rate`, use `CHANGE COLUMN` (see MySQL docs) instead of adding a duplicate.

```sql
ALTER TABLE loans ADD COLUMN principal_amount DECIMAL(12, 2) NULL AFTER name;
ALTER TABLE loans ADD COLUMN annual_interest_rate DECIMAL(6, 3) NULL AFTER principal_amount;
ALTER TABLE loans ADD COLUMN payment_type ENUM('interest_only', 'prepaid', 'amortizing') NULL AFTER annual_interest_rate;
UPDATE loans SET payment_type = CASE interest_type WHEN 'prepaid' THEN 'prepaid' ELSE 'interest_only' END;
UPDATE loans SET principal_amount = 0.00 WHERE principal_amount IS NULL;
UPDATE loans SET annual_interest_rate = 0.000 WHERE annual_interest_rate IS NULL;
UPDATE loans SET payment_type = 'interest_only' WHERE payment_type IS NULL;
ALTER TABLE loans MODIFY principal_amount DECIMAL(12, 2) NOT NULL;
ALTER TABLE loans MODIFY annual_interest_rate DECIMAL(6, 3) NOT NULL;
ALTER TABLE loans MODIFY payment_type ENUM('interest_only', 'prepaid', 'amortizing') NOT NULL;
ALTER TABLE loans DROP COLUMN monthly_interest;
ALTER TABLE loans DROP COLUMN interest_type;
```

Afterward, edit migrated loans in the app to set real principal and annual rate where payment type is `interest_only` or `amortizing`.

### Manual validation checklist (loans)

1. **Fresh schema:** On a new database, run `php bin/migrate.php` and confirm `SHOW CREATE TABLE loans` lists `principal_amount`, `annual_interest_rate`, and `payment_type`, and does not list `interest_type` or `monthly_interest`.
2. **Interest only:** Create a loan with payment type **Interest only**, principal `100000.00`, annual rate `12.00`, valid entity and dates. Save succeeds; list shows principal `100000.00`, rate `12.00`, payment type `interest_only`. Hover the principal cell: tooltip text should report estimated monthly interest `1000.00` (because \(100000 \times 0.12 / 12 = 1000\)).
3. **Amortizing:** Create a loan with payment type **Amortizing** and the same principal/rate rules as interest-only. Save succeeds; list shows `amortizing`. Tooltip on principal uses the same formula (full principal; amortization not implemented yet).
4. **Prepaid:** Create a loan with payment type **Prepaid**, prepaid amount `5000.00`, prepaid date set, leave principal/rate blank or zero. Save succeeds; list shows `prepaid` and principal/rate `0.00` / `0.00` (stored placeholders).
5. **Validation rejects bad input:** Interest-only with zero principal or zero rate should redirect back to the form without creating a row. Prepaid with missing date or zero/negative prepaid amount should reject similarly.
6. **Edit:** Open an existing loan, change payment type or principal/rate, save; list and DB reflect changes. `cash_events` unchanged (no FK or table changes there).

### 5. Point the web server at `public/`

**Document root** must be the `public/` directory so that `app/`, `bin/`, `migrations/`, and `.env` are **not** directly web-accessible.

- **Apache:** set `DocumentRoot` to `.../priv-lending/public` and ensure `AllowOverride` allows `.htaccess` (only needs `FileInfo` for the small rewrite block in `public/.htaccess`).
- **nginx:** use `root .../priv-lending/public;` and a `try_files` rule that forwards non-files to `index.php` (mirror the intent of `public/.htaccess`).

### 6. Sign in and use the app

1. Open the site in a browser (e.g. `https://localhost/priv-lending/` depending on your vhost).
2. Go to **Login** and submit the form (single-user demo: user id `1`).
3. Use **Borrowers → Entities → Loans** to create records in order (foreign keys require parents to exist first).
4. Open **Checks** (`/checks`) from the dashboard for a read-only monthly expected-payment view (no writes).

### Upgrading `loans` for `/checks` (existing databases)

If `SHOW COLUMNS FROM loans` does not list `monthly_interest`, `interest_calc_method`, or `principal_payment_monthly`, run (adjust or skip lines if a column already exists):

```sql
ALTER TABLE loans ADD COLUMN monthly_interest DECIMAL(12, 2) NULL AFTER annual_interest_rate;
ALTER TABLE loans ADD COLUMN interest_calc_method ENUM('fixed', 'declining_balance') NOT NULL DEFAULT 'fixed' AFTER monthly_interest;
ALTER TABLE loans ADD COLUMN principal_payment_monthly DECIMAL(12, 2) NULL AFTER interest_calc_method;
```

### Manual validation checklist (GET /checks)

1. **Month parameter:** Open `/checks` with no query string; the month control defaults to the current calendar month. Submit an invalid `?month=13-2026` or `?month=2026-13` via URL tampering; the page should fall back to the current month (server-side validation only).
2. **Column header:** The main table lists **Expected payment** (not “Expected interest”).
3. **Fixed:** With `interest_calc_method = 'fixed'`, the primary amount equals stored `monthly_interest` when set (two decimal places). Clear `monthly_interest`; the primary amount equals full principal × annual rate ÷ 12 (`loan_simple_monthly_interest`). Checkbox stays enabled.
4. **Declining balance — payment:** With `interest_calc_method = 'declining_balance'`, non–paid-off rows: compute expected interest as today (`remaining × (annual_interest_rate / 100) / 12`, half-up to 2 decimals on interest). Expected principal portion equals `principal_payment_monthly` normalized to 2 decimals. **Expected payment** (primary) equals interest + principal (sum at 2 decimals). Under the primary amount, two small lines appear: `interest: $…` and `principal: $…` matching those components.
5. **Declining balance — paid off:** When remaining principal at the start of the selected month is ≤ 0, the primary cell shows "—", secondary line "Paid off", checkbox disabled. Notes column stays empty.
6. **Prepaid:** Loans with `payment_type = 'prepaid'` appear only in the prepaid table at the bottom with the informational note; they are not included in the main expected-payment table.
7. **No writes:** Confirm no POST from this page and no `INSERT`/`UPDATE` in the `GET /checks` handler; checkboxes have no `name` attribute so they are not submitted.
8. **Rounding:** Interest portion uses **half-up** to two decimals (`checks_declining_monthly_interest`). Principal portion is shown at two decimals. Total expected payment is the sum at two decimal places (`checks_add_money_2`).
9. **Interest-only declining (edge):** If a loan uses `declining_balance` with a non-zero `principal_payment_monthly`, the principal line should match that monthly amount even when payment type is interest-only (unusual but allowed).
10. **POST /checks/submit:** If implemented elsewhere, it should continue to persist the **total** expected amount (payment), not a split; this change does not alter that contract.

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
