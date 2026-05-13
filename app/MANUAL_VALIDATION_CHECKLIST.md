# Manual validation checklist

## Structure

1. `app/controllers/ChecksController.php` exists; `app/views/checks.php` exists.
2. `app/controllers/LoansController.php` exists; `app/views/loans.php` and `app/views/loans_new.php` exist.
3. `public/index.php` bootstraps unchanged (env, session, csrf, security headers, `e()`, `db`, helpers, `render()`).
4. `ChecksController` and `LoansController` are each required once from `public/index.php` before the route table.

## GET /checks (ChecksController + view)

Run logged in, with migrations applied if you use posting / Posted status.

1. **GET /checks** — Page loads; title “Interest checks”; Tailwind CDN present.
2. **Month control** — Default month is current calendar month; changing month and **Show** updates the list (same query params as before).
3. **Copy / banners** — Intro paragraph, optional amber banner when `scheduled_check_ym` is missing (0005), optional amber when split-posting index is missing (0007) but 0005 applied, optional amber when `prepaid_interest_received` is missing (0006), footer note under the post button describes monthly + prepaid posting.
4. **Nav links** — Dashboard, Loans, Cash events (same classes as before).
5. **Flash** — `?posted=1` shows green success; `?posted=0` shows amber “nothing posted” (after POST redirect).
6. **Monthly table** — Rows, expected payment math, Post/Status (Posted / Not posted / Paid off / No payment), empty state message when no rows.
7. **Prepaid table** — Rows, amounts, Post/Status, empty state when none in window.
8. **POST form** — CSRF field, hidden `month`, `event_date` defaulting to today, **Post cash events** submits to **POST /checks** (unchanged handler in `index.php`).

## POST /checks (still in index.php)

9. **POST /checks (monthly)** — With migrations through **0007**, declining-balance loans insert two `cash_events` for the same `scheduled_check_ym` (category `interest` and `principal_in`) whose amounts match the split shown on GET /checks; fixed-calculation loans still insert a single `interest` event. Prepaid path unchanged (one interest event, `scheduled_check_ym` null).
10. Duplicate / invalid submissions behave as before; duplicate key on a second portion rolls back that loan’s savepoint so the ledger stays consistent.

## Loans (LoansController + views)

11. **GET /loans** — Page loads; title “Loans”; **New loan** link; Dashboard link; table headers and empty state “No loans yet.” when there are no rows; with data: columns match prior list (principal tooltip on IO/amortizing, implied annual cell when applicable, Edit links to `/loans/edit?id=…`).
12. **GET /loans/new** — Form fields, copy, entity dropdown, CSRF field, **Save** posts to **POST /loans/new**; **Cancel** to `/loans`; empty-entities message and link to `/entities/new` when no entities.
13. **GET /loans/new?invalid=1** — Amber validation banner text unchanged from before refactor.
14. **POST /loans/new** — Valid submissions insert and redirect to **GET /loans**; invalid submissions redirect to **GET /loans/new?invalid=1** with same rules as before (no change to validation or DB insert shape).
15. **GET /loans/edit** and **POST /loans/edit** — Still handled inline in `public/index.php` (unchanged).

## Cash events (`public/index.php`)

16. **GET /cash-events** — Table includes **Actions** with **Edit** per row; empty state colspan matches column count.
17. **GET /cash-events/edit?id=…** — Loads the event; form matches **New cash event** fields with current values; optional banner when `scheduled_check_ym` is set (migration applied); invalid query shows same amber message as new.
18. **POST /cash-events/edit** — Same validation rules as **POST /cash-events/new**; successful save updates the row (leaves `scheduled_check_ym` unchanged); redirects to **GET /cash-events**; changing loan on a posted event fails validation if it would duplicate `(loan_id, scheduled_check_ym, category)`.
19. **GET /cash-events/new** and **POST /cash-events/new** — Unchanged.

## Bank (`public/index.php`)

20. **GET /bank** — Form shows bank selector (JPM / NTRS), statement date, interest amount, principal amount (default 0.00); CSRF field; **Save** posts to **POST /bank**; **Cancel** to `/`; invalid query shows amber banner.
21. **POST /bank** — Valid submission inserts two `cash_events` with `loan_id` NULL, `deposit_to` equal to selected bank, `event_date` equal to statement date, amounts **negative** of the entered values: first row `category = loc_interest`, second `category = principal_out` (second row inserted even when principal is 0); `scheduled_check_ym` NULL when that column exists. CSRF enforced. Invalid input redirects to **GET /bank?invalid=1** with no inserts.

## Other routes

22. **GET /** — Dashboard link row includes **Bank** when present.
23. **`php -l public/index.php`**, **`php -l app/controllers/ChecksController.php`**, **`php -l app/views/checks.php`**, **`php -l app/controllers/LoansController.php`**, **`php -l app/views/loans.php`**, **`php -l app/views/loans_new.php`** — No syntax errors.
