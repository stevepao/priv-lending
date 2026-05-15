# Manual validation checklist

## Structure

1. `app/controllers/ChecksController.php` exists; `app/views/checks.php` exists.
2. `app/controllers/LoansController.php` exists; `app/views/loans.php` and `app/views/loans_new.php` exist.
3. `public/index.php` bootstraps unchanged (env, session, csrf, security headers, `e()`, `db`, helpers, `render()`).
4. `ChecksController` and `LoansController` are each required once from `public/index.php` before the route table.

## GET /checks (ChecksController + view)

Run logged in, with migrations applied if you use posting / Posted status.

1. **GET /checks** — Page loads; title “Monthly Check Batches”; Tailwind CDN present.
2. **Month control** — Default month is current calendar month; changing month and **Show** updates the list (same query params as before).
3. **Copy / banners** — Intro paragraph, optional amber banner when `scheduled_check_ym` is missing (0005), optional amber when split-posting index is missing (0007) but 0005 applied, optional amber when `prepaid_interest_received` is missing (0006), footer note under the post button describes monthly + prepaid posting.
4. **Nav links** — Dashboard, Loans, Cash events (same classes as before).
5. **Flash** — `?posted=1` shows green success; `?posted=0` shows amber “nothing posted” (after POST redirect).
6. **Monthly table** — Rows show funding source (loan), loan name, method, expected payment, Post/Status (Posted / Not posted / Paid off / No payment), empty state when no rows; **interest-only**, **amortizing**, and **post-prepaid** rows only when the viewed month is **strictly after** the loan’s origin calendar month (first monthly check is the month after origin).
7. **Prepaid table** — Same funding source column, loan name, amounts, Post/Status, empty state when none in window; **prepaid** loans still appear in the **prepaid** table through the origin month when inside the prepaid-through window.
8. **POST form** — CSRF field, hidden `month`, `event_date` defaulting to today, **Post cash events** submits to **POST /checks** (unchanged handler in `index.php`).

## POST /checks (still in index.php)

9. **POST /checks (monthly)** — With migrations through **0007**, declining-balance loans insert two `cash_events` for the same `scheduled_check_ym` (category `interest` and `principal_in`) whose amounts match the split shown on GET /checks; fixed-calculation loans still insert a single `interest` event. Prepaid path unchanged (one interest event, `scheduled_check_ym` null).
10. Duplicate / invalid submissions behave as before; duplicate key on a second portion rolls back that loan’s savepoint so the ledger stays consistent.

## Loans (LoansController + views)

11. **GET /loans** — Page loads; title “Loans”; **New loan** link; Dashboard link; **Open loans** and **Closed loans** sections (each table sorted by origin date ascending); per-section empty states (“No open loans.” / “No closed loans.”); with data: columns match prior list (principal tooltip on IO/amortizing, implied annual cell when applicable, Edit links to `/loans/edit?id=…`); closed loans have `closed_date` set.
12. **GET /loans/new** — Form fields, copy, entity dropdown, CSRF field, optional **Create funding transaction (principal_out) on save** when migration **0008** is applied (otherwise amber migration note); **Save** posts to **POST /loans/new**; **Cancel** to `/loans`; empty-entities message and link to `/entities/new` when no entities.
13. **GET /loans/new?invalid=1** — Amber validation banner; includes guidance when funding checkbox was used without migration or without positive principal.
14. **POST /loans/new** — Valid submissions insert and redirect to **GET /loans**; invalid submissions redirect to **GET /loans/new?invalid=1**. With **0008** and funding checkbox checked and principal &gt; 0: one `cash_events` row (`principal_out`, negative amount, `loan_id` set, `event_date` = origin, `deposit_to` = funding source) and `funding_principal_out_posted = 1` on the new loan (same transaction as loan insert).
15. **GET /loans/edit** — Shows **Funding transaction (principal_out): Posted** when `funding_principal_out_posted` is set; otherwise optional **Post funding transaction now** checkbox when **0008** applied; amber migration note when column missing.
16. **POST /loans/edit** — Same loan validation as before; with checkbox and not yet posted and principal &gt; 0: inserts funding `cash_events` row and sets `funding_principal_out_posted = 1` in the same transaction as the loan **UPDATE**.

## Cash events (`public/index.php`)

17. **GET /cash-events** — Table includes **Actions** with **Edit** per row; list shows the **500 most recent** events by `event_date`/`id`, ordered **ascending** on screen (oldest of that set first, newest last); empty state colspan matches column count.
18. **GET /cash-events/edit?id=…** — Loads the event; form matches **New cash event** fields with current values; optional banner when `scheduled_check_ym` is set (migration applied); invalid query shows same amber message as new.
19. **POST /cash-events/edit** — Same validation rules as **POST /cash-events/new**; **LOC interest** and **principal out** amounts must be **negative**; **interest** and **principal in** must be **positive**. Successful save updates the row (leaves `scheduled_check_ym` unchanged); redirects to **GET /cash-events**; changing loan on a posted event fails validation if it would duplicate `(loan_id, scheduled_check_ym, category)`.
20. **GET /cash-events/new** and **POST /cash-events/new** — Unchanged.

## Bank (`public/index.php`)

21. **GET /bank** — Form shows bank selector (JPM / NTRS), statement date, interest amount; CSRF field; **Save** posts to **POST /bank**; **Cancel** to `/`; invalid query shows amber banner.
22. **POST /bank** — Valid submission inserts one `cash_events` row with `loan_id` NULL, `deposit_to` equal to selected bank, `event_date` equal to statement date, `category = loc_interest`, amount **negative** of the entered interest. `scheduled_check_ym` NULL when that column exists. CSRF enforced. Invalid input redirects to **GET /bank?invalid=1** with no inserts. (Bank LOC `principal_out` is not on this form; use **Cash events** if needed.)

## Report (`public/index.php`)

23. **GET /report** — Date range form (start / end, GET); defaults to current calendar month when a bound is missing or invalid; **Run report** submits to same path. Shows Interest In, LOC Interest Out, Net Income, and FYI Principal Paid from `cash_events` with inclusive `event_date` range; amber message when start &gt; end (no query). Dashboard links to **Report**.

## Other routes

24. **GET /** — Dashboard link row includes **Bank** and **Report** when present.
25. **`php -l public/index.php`**, **`php -l app/controllers/ChecksController.php`**, **`php -l app/views/checks.php`**, **`php -l app/controllers/LoansController.php`**, **`php -l app/views/loans.php`**, **`php -l app/views/loans_new.php`** — No syntax errors.
