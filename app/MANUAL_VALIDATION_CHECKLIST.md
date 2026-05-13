# Manual validation checklist (controller/view scaffold)

Use this after the `render()` helper and `app/controllers/` + `app/views/` layout are added. No routes were refactored yet; behavior should match the prior commit.

## Structure

1. Confirm directories exist: `app/controllers/`, `app/views/` (each may contain only `.gitkeep`).
2. Confirm `public/index.php` still starts with the same bootstrap (env, session, csrf, security headers, view `e()`, db).

## Smoke tests (unchanged behavior)

Run against a configured database and logged-in session where applicable.

1. **GET /** — Dashboard loads; nav links present.
2. **GET /login** — Login form loads when logged out.
3. **POST /login** — Still signs in (or verify your auth flow).
4. **GET /borrowers**, **GET /entities**, **GET /loans** — List pages render.
5. **GET /checks** — Month picker, monthly and prepaid tables, post form (if migrations applied).
6. **POST /checks** — Still redirects and posts only when intended (no new `render()` usage yet).
7. **GET /cash-events** — Ledger loads.
8. **GET /cash-events/new** — Form loads; **POST** still saves when valid.
9. **Loan create/edit** — No change to forms or saves.
10. **`php -l public/index.php`** — No syntax errors.

## Future (when views are adopted)

- Replace inline `echo` in a single route with `render('name', $data)` and add `app/views/name.php`; re-run this checklist for that route only.
