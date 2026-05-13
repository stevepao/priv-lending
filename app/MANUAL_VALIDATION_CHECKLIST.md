# Manual validation checklist

## Structure

1. `app/controllers/ChecksController.php` exists; `app/views/checks.php` exists.
2. `public/index.php` bootstraps unchanged (env, session, csrf, security headers, `e()`, `db`, helpers, `render()`).
3. `ChecksController` is required once from `public/index.php` before the route table.

## GET /checks (ChecksController + view)

Run logged in, with migrations applied if you use posting / Posted status.

1. **GET /checks** — Page loads; title “Interest checks”; Tailwind CDN present.
2. **Month control** — Default month is current calendar month; changing month and **Show** updates the list (same query params as before).
3. **Copy / banners** — Intro paragraph, optional amber banner when `scheduled_check_ym` is missing, optional amber banner when `prepaid_interest_received` is missing, footer note under the post button match prior behavior.
4. **Nav links** — Dashboard, Loans, Cash events (same classes as before).
5. **Flash** — `?posted=1` shows green success; `?posted=0` shows amber “nothing posted” (after POST redirect).
6. **Monthly table** — Rows, expected payment math, Post/Status (Posted / Not posted / Paid off / No payment), empty state message when no rows.
7. **Prepaid table** — Rows, amounts, Post/Status, empty state when none in window.
8. **POST form** — CSRF field, hidden `month`, `event_date` defaulting to today, **Post cash events** submits to **POST /checks** (unchanged handler in `index.php`).

## POST /checks (still in index.php)

9. Posting monthly and/or prepaid selections still creates cash events and redirects with `posted=` as before.
10. Duplicate / invalid submissions behave as before.

## Other routes (unchanged)

11. **GET /**, **GET /loans**, **GET /cash-events** — Still work.
12. **`php -l public/index.php`**, **`php -l app/controllers/ChecksController.php`**, **`php -l app/views/checks.php`** — No syntax errors.
