# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-05-15

### Added

- **Core workflow:** Borrowers, entities, loans (open/closed, funding source JPM/NTRS), cash events ledger, bank statement LOC interest entry, monthly **Checks** expected payments (fixed / declining balance / prepaid), and **Report** with date ranges (by month, bank, loan, entity) including LOC allocation weighting by loan balances.
- **Dashboard:** Bank-level snapshot of open loans (with per-bank total balance) and last three months of cash activity by deposit bank.
- **Authentication:** Email OTP sign-in via SMTP (PHPMailer), allowed-address list and rate limits (see `.env.example`).
- **Operations:** MySQL migrations under `migrations/`, `php bin/migrate.php`, Tailwind-styled UI, CSRF/session hardening, optional `public/health.php` for quick diagnostics.

### Changed

- Internal refactors for shared SQL and validation (principal ledger subqueries, `cash_events` inserts, loan save form parsing, funding-bank helpers, dashboard bank column partial).

### Documentation

- README corrections: domain helpers live in `app/lib/lending_domain.php`, not `public/index.php`.

[0.1.0]: https://github.com/YOUR_USERNAME/priv-lending/releases/tag/v0.1.0
