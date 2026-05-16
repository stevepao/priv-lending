# Manual validation checklist

## Structure

1. `app/controllers/ChecksController.php` exists; `app/views/checks.php` exists.
2. `app/controllers/LoansController.php` exists; `app/views/loans.php` and `app/views/loans_new.php` exist.
3. `public/index.php` bootstraps env, optional Composer `vendor/autoload.php`, session, csrf, security headers, `e()`, `db`, helpers, `render()`.
4. `ChecksController` and `LoansController` are each required once from `public/index.php` before the route table; `PayoffController` is required for payoff routes.

## Login (email OTP + SMTP)

- **Setup** — Run `composer install` at project root (PHPMailer). Apply migration **`0010_login_otps.sql`**. Configure `.env` (see `.env.example`): `AUTH_ALLOWED_EMAILS` (comma-separated), `OTP_TTL_SECONDS`, SMTP (`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`), `MAIL_FROM_ADDRESS`, optional `MAIL_FROM_NAME`, optional rate-limit keys.
1. **GET /login** — Email field; **Email me a code** posts to **POST /login/request-otp** (CSRF). Allowed address receives 6-digit code; same neutral copy if address is not allowed.
2. **GET /login** (after code sent) — OTP field; **Sign in** posts to **POST /login/verify** (CSRF). Success redirects `/`. **Use a different email** → **GET /login/cancel** clears pending OTP session state.
3. **POST /logout** — CSRF; session cleared; redirect **GET /login**.

## GET /checks (ChecksController + view)

Run logged in, with migrations applied if you use posting / Posted status.

1. **GET /checks** — Page loads; title “Monthly Check Batches”; Tailwind CDN present.
2. **Month control** — Default month is current calendar month; changing month and **Show** updates the list (same query params as before).
3. **Copy / banners** — User-facing intro (bullets), **Monthly payments** / **Prepaid loans** section headings, plain-language success/failure messages, **Payment date** + **Save selected payments**; optional amber banners when migrations 0005/0006/0007 missing (friendly text, migration id in code); short footer under save button.
4. **Nav links** — Dashboard, Loans, Cash events (same classes as before).
5. **Flash** — `?posted=1` shows green success; `?posted=0` shows amber “nothing posted” (after POST redirect).
6. **Monthly table** — Rows show funding source (loan), loan name, method, expected payment, Post/Status (Posted / Not posted / Paid off / No payment), empty state when no rows; default sort **funding source** then **loan name** (ascending); column headers still re-sort client-side; **interest-only**, **amortizing**, and **post-prepaid** rows only when the viewed month is **strictly after** the loan’s origin calendar month (first monthly check is the month after origin).
7. **Prepaid table** — Same funding source column, loan name, **origin** date, prepaid amount (formatted), Post/Status, empty state when none in window (colspan 6); same default sort as monthly table; **prepaid** loans still appear in the **prepaid** table through the origin month when inside the prepaid-through window.
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

17. **GET /cash-events** — Date range filter (default **Last 3 months**): presets last full year, YTD, calendar quarter to date, last 3 months, custom start/end; **Show** submits GET; invalid custom range shows amber error and empty table body; summary line shows inclusive dates; table includes **Edit** (no entity column); amount comma-formatted and right-aligned; date column `whitespace-nowrap`; events in range ordered ascending by date; empty state colspan 8.
18. **GET /cash-events/edit?id=…** — Loads the event; form matches **New cash event** fields with current values; optional banner when `scheduled_check_ym` is set (migration applied); invalid query shows same amber message as new.
19. **POST /cash-events/edit** — Same validation rules as **POST /cash-events/new**; **LOC interest** and **principal out** amounts must be **negative**; **interest** and **principal in** must be **positive**. Successful save updates the row (leaves `scheduled_check_ym` unchanged); redirects to **GET /cash-events**; changing loan on a posted event fails validation if it would duplicate `(loan_id, scheduled_check_ym, category)`.
20. **GET /cash-events/new** and **POST /cash-events/new** — Unchanged.

## Bank (`public/index.php`)

21. **GET /bank** — Intro explains LOC interest for a month; form shows bank selector (JPM / NTRS), statement date, interest amount; CSRF field; **Save** posts to **POST /bank**; **Cancel** to `/`; invalid query shows amber banner; **Recent LOC interest** table shows up to six `loc_interest` rows (`loan_id` NULL) with bank, statement date, formatted interest (newest date first, then bank A→Z).
22. **POST /bank** — Valid submission inserts one `cash_events` row with `loan_id` NULL, `deposit_to` equal to selected bank, `event_date` equal to statement date, `category = loc_interest`, amount **negative** of the entered interest. `scheduled_check_ym` NULL when that column exists. CSRF enforced. Invalid input redirects to **GET /bank?invalid=1** with no inserts. (Bank LOC `principal_out` is not on this form; use **Cash events** if needed.)

## Report (`public/index.php`)

23. **GET /report** — **Report type** dropdown (default By month): By bank | By month, by bank | By loan | By entity; date range presets (default last 3 months) + custom; **Show report** GET. By month lists every calendar month in range with zeros empty; others list groups with activity. Metrics: Interest in, LOC out, Net income, Principal paid — **Bank** dimensions use cash event **Deposit to**; **loan** aggregates by loan (**Not on a loan** for no `loan_id`); **entity** via loan→entity (**Not on an entity loan** when no loan/entity); **Total** row. **By loan** and **By entity**: LOC out and net income are computed allocations (italic)—for **each calendar month** in range, that month’s LOC is split using **principal ledger balance as of the end of that month within the report** (by loan **Funding source** vs same-bank total); monthly pieces are summed; a note appears if some LOC cannot be allocated. Amber when custom start &gt; end. Dashboard links to **Report**.

## Other routes

24. **GET /** — **Dashboard** intro explains the app; two-column layout on large screens (**JPM** | **NTRS**): each column lists **open** loans only for that funding source (origin date ascending) with **Balance** (cash principal ledger); below, **Recent months** (three rows: two prior full calendar months + current month through today) with **Interest**, **LOC interest** (positive expense), **Principal in** from cash events with matching **Deposit to**. Stacked on small screens. **Quick links** (same order as main nav, excluding Dashboard) below. Nav includes **Bank**, **Payoff**, and **Report** when present.
25. **GET /payoff** — Logged-in only; **Payoff statement** form with loan dropdown (entity — loan, sorted like cash-events new), **Date quoted** and **Payoff good through** defaulting to today; CSRF on POST path; **Generate statement** posts to **POST /payoff**; **Cancel** to `/`. Empty loan list: only disabled placeholder option and disabled submit. Main nav lists **Payoff** after **Report**. Payoff good through should be ≥ date quoted (server rejects otherwise).
26. **POST /payoff** — CSRF required; **payoff good through** ≥ **date quoted**. Valid **`origin_date`** on loan; prepared **`SELECT`** joins **entities** and **borrowers** (borrower address columns require migration **`0011_borrowers_address.sql`**), and selects loan + entity + borrower fields, rates, optional **`monthly_interest`**, **`interest_calc_method`**, **`principal_payment_monthly`**, **`l.notes`** (with safe fallbacks for optional loan checklist columns). **Principal** = `compute_principal_balance($loan, date_quoted)`: contract **`principal_amount`**, except **`declining_balance`** loans with a positive **`principal_payment_monthly`** use the same modeled linear paydown as checks (`principal_amount` − monthly × cycles before the **`date_quoted`** cycle month, clamped ≥ 0); ledger / `cash_events` are not used for payoff principal. **Monthly interest** = stored **`monthly_interest`** when set, else `loan_simple_monthly_interest(principal_balance, annual_rate)`. **Cycle** from **`date_quoted`** and **D** = day of month(`origin_date`): if day(`date_quoted`) ≥ **D**, anchor is **D** in that month (clamped); else **D** in previous month (clamped). **full_start** = same **D** one month earlier (clamped); **full_end** = day before cycle anchor. **Per-diem** from anchor through **payoff good through** (inclusive calendar days); **daily rate** = monthly ÷ 30 (bcmath 8 dp); per-diem = daily × days, half-up to 2 dp. **Total** = `checks_add_money_2` chain. **Statement layout:** header uses **`LENDER_*`** from `.env` (name, street, city/state/ZIP line, email, phone); addressee block shows **entity** name, **borrower** street + city/state/ZIP, **Attn:** borrower name; title **LOAN PAYOFF STATEMENT**; body lines **Date quoted:**, **Payoff good to:**, **Property:** (loan **name**, else **notes**), **Principal**, two **Interest -** lines with **M/D/YYYY – M/D/YYYY** ranges, **Total amount due:**, **Daily interest rate**; currency **`$`#,##0.00**; dates **M/D/YYYY**. Invalid POST → **GET /payoff?invalid=1**.

27. **Worked example (POST /payoff)** — Loan **origin_date** `2023-01-09` ⇒ **D = 9**. **date_quoted** `2026-03-15` (15 ≥ 9) ⇒ **cycle_start** `2026-03-09`. **full_start** `2026-02-09`, **full_end** `2026-03-08`. **payoff_good_thru** `2026-03-20` ⇒ per-diem window **3/9/2026 – 3/20/2026** (12 days). **Fixed** loan (or non-declining / no monthly paydown): if **`principal_amount`** = `100000.00`, **annual_interest_rate** = `12.000`, no stored monthly interest ⇒ principal **$100,000.00**; first interest line range **2/9/2026 – 3/8/2026** for **$1,000.00**; second **3/9/2026 – 3/20/2026** for **$400.00**; **Total amount due:** **$101,400.00**; daily rate **$33.33** (verify in app with same inputs).

28. **GET /payoff/pdf** — Logged-in only (same gate as other app routes). Query **`loan_id`**, **`date_quoted`** (`Y-m-d`), **`payoff_good_thru`** (`Y-m-d`); invalid or **`payoff_good_thru`** &lt; **`date_quoted`** redirects **GET /payoff?invalid=1**. Recomputes payoff on the server (same path as **POST /payoff**), renders **`payoff_statement_pdf`** for Dompdf, streams **Content-Disposition** attachment **`Loan Payoff Statement - &lt;sanitized loan name&gt; - &lt;date_quoted&gt;.pdf`**. Requires **`composer install`** so **`dompdf/dompdf`** is present. HTML statement shows **Download PDF** (same params).

29. **`php -l public/index.php`**, **`php -l app/controllers/AuthController.php`**, **`php -l app/lib/auth_email.php`**, **`php -l app/controllers/ChecksController.php`**, **`php -l app/views/checks.php`**, **`php -l app/controllers/LoansController.php`**, **`php -l app/views/loans.php`**, **`php -l app/views/loans_new.php`**, **`php -l app/lib/payoff_helpers.php`**, **`php -l app/controllers/PayoffController.php`**, **`php -l app/views/payoff_form.php`**, **`php -l app/views/payoff_statement.php`**, **`php -l app/views/payoff_statement_pdf.php`** — No syntax errors.

## Payoff principal (manual checks)

- **Fixed / interest-only on statement** — With **`interest_calc_method`** fixed (or missing column) or declining but **no** positive **`principal_payment_monthly`**, statement **Principal** equals formatted **`principal_amount`** on the loan (not cash ledger).
- **Declining balance** — With **`declining_balance`** and positive **`principal_payment_monthly`**, statement **Principal** matches `principal_amount − principal_payment_monthly × N` where **N** uses **`loan_months_elapsed_to_calendar_month`** for the calendar month of **`payoff_cycle_start_for_date(origin, date_quoted)`** (same cycle rules as interest window). Changing **date quoted** to a prior month should lower or equal remaining vs a later month (until clamped at 0).

## Payoff statement layout (manual checks)

- **Lender header** — With **`LENDER_*`** set in `.env`, statement shows non-empty lines only (name bold, street, “City, ST ZIP”, email, phone).
- **Addressee** — Entity name, borrower street, “City, ST ZIP”, and **Attn:** borrower individual name align with **Borrowers** / **Entities** data (after **`0011`**).
- **Labels** — **Date quoted:** and **Payoff good to:** use **M/D/YYYY**; both **Interest -** rows show hyphenated range and amounts as **`$`#,##0.00**; **Property** matches loan name (or **notes** if name were empty).
- **PDF** — **GET /payoff/pdf** with the same dates and loan as the HTML statement produces a PDF whose line items and amounts match the on-screen statement; filename uses **`Loan Payoff Statement - … - &lt;date_quoted&gt;.pdf`** (`Y-m-d` segment from the query).
