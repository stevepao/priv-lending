<?php

declare(strict_types=1);

if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This app needs the pdo and pdo_mysql PHP extensions enabled for the web site.' . "\n";
    exit;
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'env.php';
$showErrorDetail = filter_var((string) env('APP_DEBUG', ''), FILTER_VALIDATE_BOOLEAN);
if ($showErrorDetail) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
bootstrap_session();

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'csrf.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'security_headers.php';
security_headers();

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'view.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'db.php';

/**
 * Monthly interest on full principal at the stated annual rate (no amortization).
 * Formula: principal_amount * (annual_interest_rate / 100) / 12
 */
function loan_simple_monthly_interest(string $principalAmount, string $annualRatePercent): string
{
    if (extension_loaded('bcmath')) {
        if (bccomp($principalAmount, '0', 2) <= 0 || bccomp($annualRatePercent, '0', 2) <= 0) {
            return '0.00';
        }
        $monthly = bcdiv(bcmul($principalAmount, bcdiv($annualRatePercent, '100', 8), 8), '12', 8);

        return bcadd($monthly, '0', 2);
    }

    $p = (float) $principalAmount;
    $r = (float) $annualRatePercent;
    if ($p <= 0.0 || $r <= 0.0) {
        return '0.00';
    }

    return number_format($p * ($r / 100.0) / 12.0, 2, '.', '');
}

/** True when stored annual rate is absent or effectively zero. */
function loan_annual_interest_rate_is_blank_or_zero(string $annualRatePercent): bool
{
    $s = trim($annualRatePercent);
    if ($s === '') {
        return true;
    }
    if (extension_loaded('bcmath')) {
        return bccomp($s, '0', 3) <= 0;
    }

    return (float) $s <= 0.0;
}

/**
 * Implied annual interest percent from fixed monthly interest on full principal:
 * (monthly_interest / principal) * 12 * 100. Inverse of loan_simple_monthly_interest for positive inputs.
 *
 * @return string|null null when inputs are missing or not positive
 */
function loan_implied_annual_percent_from_monthly_interest(string $principalAmount, string $monthlyInterestAmount): ?string
{
    $p = trim($principalAmount);
    $m = trim($monthlyInterestAmount);
    if ($p === '' || $m === '') {
        return null;
    }
    if (extension_loaded('bcmath')) {
        if (bccomp($p, '0', 2) !== 1 || bccomp($m, '0', 2) !== 1) {
            return null;
        }
        $raw = bcdiv(bcmul(bcmul($m, '12', 8), '100', 8), $p, 8);

        return number_format((float) $raw, 3, '.', '');
    }
    $pF = (float) $p;
    $mF = (float) $m;
    if ($pF <= 0.0 || $mF <= 0.0) {
        return null;
    }

    return number_format($mF * 12 * 100 / $pF, 3, '.', '');
}

/** Normalize user-entered decimals: trim, strip leading $, US thousands with commas, else comma as decimal, strip trailing % for rates. */
function loan_normalize_decimal_input(string $s, bool $stripPercentSuffix = false): string
{
    $s = trim($s);
    $s = ltrim($s, '$');
    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,4})?$/', $s)) {
        $s = str_replace(',', '', $s);
    } elseif (str_contains($s, ',') && !str_contains($s, '.')) {
        $s = str_replace(',', '.', $s);
    }
    if ($stripPercentSuffix) {
        $s = rtrim($s, " \t\n\r\0\x0B%");
    }

    return $s;
}

/** Count of scheduled principal paydowns applied before the start of the selected calendar month (day-of-month ignored). The loan’s origin month has no paydown yet (first payment is modeled from the following calendar month), then one paydown per month through the month before the selected month. Equivalently: max(0, full_calendar_month_span(origin_month, selected_month) - 1). */
function loan_months_elapsed_to_calendar_month(string $originYmd, string $selectedYm): int
{
    $o = DateTimeImmutable::createFromFormat('Y-m-d', $originYmd);
    if (!$o instanceof DateTimeImmutable || $o->format('Y-m-d') !== $originYmd) {
        return 0;
    }
    $s = DateTimeImmutable::createFromFormat('Y-m', $selectedYm);
    if (!$s instanceof DateTimeImmutable || $s->format('Y-m') !== $selectedYm) {
        return 0;
    }
    $oMonth = $o->modify('first day of this month');
    $sMonth = $s->modify('first day of this month');
    if ($sMonth < $oMonth) {
        return 0;
    }
    $y1 = (int) $oMonth->format('Y');
    $m1 = (int) $oMonth->format('n');
    $y2 = (int) $sMonth->format('Y');
    $m2 = (int) $sMonth->format('n');

    $span = max(0, ($y2 - $y1) * 12 + ($m2 - $m1));

    return max(0, $span - 1);
}

/** Remaining principal after linear paydown; clamped to >= 0. */
function loan_remaining_principal_after_paydowns(string $principalAmount, string $monthlyPrincipalPayment, int $monthsElapsed): string
{
    if ($monthsElapsed < 0) {
        $monthsElapsed = 0;
    }
    $mpp = trim($monthlyPrincipalPayment) === '' ? '0.00' : $monthlyPrincipalPayment;
    if (extension_loaded('bcmath')) {
        $paid = bcmul($mpp, (string) $monthsElapsed, 2);
        $rem = bcsub($principalAmount, $paid, 2);
        if (bccomp($rem, '0', 2) <= 0) {
            return '0.00';
        }

        return $rem;
    }
    $rem = (float) $principalAmount - (float) $mpp * $monthsElapsed;

    return number_format(max(0.0, $rem), 2, '.', '');
}

/**
 * One month of interest on declining balance using beginning-of-month principal.
 * annual_interest_rate is stored as percent per year (e.g. 12 = 12%). Equivalent to
 * remaining_principal * ((annual_percent / 100) / 12). Rounded half-up to 2 decimals.
 */
function checks_declining_monthly_interest(string $remainingPrincipalBeginning, string $annualInterestRatePercent): string
{
    $p = (float) $remainingPrincipalBeginning;
    $r = (float) $annualInterestRatePercent;
    $raw = $p * ($r / 100.0) / 12.0;

    return number_format(round($raw, 2, PHP_ROUND_HALF_UP), 2, '.', '');
}

/** Normalize a decimal money string to exactly 2 fractional digits (for display and sums). */
function checks_normalize_money_2(string $amount): string
{
    $t = trim($amount);
    if ($t === '') {
        $t = '0';
    }
    if (extension_loaded('bcmath')) {
        return bcadd($t, '0', 2);
    }

    return number_format((float) $t, 2, '.', '');
}

/** Sum two money strings at 2 decimal places (bcmath scale 2; otherwise half-up). */
function checks_add_money_2(string $a, string $b): string
{
    if (extension_loaded('bcmath')) {
        return bcadd($a, $b, 2);
    }

    return number_format(round((float) $a + (float) $b, 2, PHP_ROUND_HALF_UP), 2, '.', '');
}

/**
 * Optional non-negative amount for loan money fields. Blank input → null (store NULL in DB).
 *
 * @return string|null|false null if blank, false if invalid
 */
function loan_parse_optional_non_negative_money_2(string $raw)
{
    $s = trim(loan_normalize_decimal_input($raw));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s)) {
        return false;
    }

    return checks_normalize_money_2($s);
}

/**
 * @return array{monthly_interest: ?string, interest_calc_method: string, principal_payment_monthly: ?string}|false
 */
function loan_parse_checks_fields_from_post(string $paymentType)
{
    if ($paymentType === 'prepaid') {
        return [
            'monthly_interest' => null,
            'interest_calc_method' => 'fixed',
            'principal_payment_monthly' => null,
        ];
    }
    $parsedMpp = loan_parse_optional_non_negative_money_2((string) ($_POST['principal_payment_monthly'] ?? ''));
    $parsedMInt = loan_parse_optional_non_negative_money_2((string) ($_POST['monthly_interest'] ?? ''));
    if ($parsedMpp === false || $parsedMInt === false) {
        return false;
    }
    $icmRaw = trim((string) ($_POST['interest_calc_method'] ?? ''));

    return [
        'monthly_interest' => $parsedMInt,
        'interest_calc_method' => in_array($icmRaw, ['fixed', 'declining_balance'], true) ? $icmRaw : 'fixed',
        'principal_payment_monthly' => $parsedMpp,
    ];
}

/** Normalize annual rate percent string to DECIMAL(6,3) scale for storage. */
function loan_format_annual_rate_db_string(string $rate): string
{
    if (extension_loaded('bcmath')) {
        return bcadd($rate, '0', 3);
    }

    return number_format((float) $rate, 3, '.', '');
}

/**
 * Validate principal + annual rate for interest_only / amortizing saves.
 * Fixed method with monthly_interest set: annual rate may be blank or zero (stored as 0.000).
 * Otherwise principal and annual rate must parse and both be > 0.
 *
 * @param array{monthly_interest: ?string, interest_calc_method: string, principal_payment_monthly: ?string} $checksFields
 *
 * @return array{principalStr: string, rateStr: string}|false
 */
function loan_principal_and_annual_for_io_amortizing_save(string $principalRaw, string $rateRaw, array $checksFields): array|false
{
    $p = trim(loan_normalize_decimal_input($principalRaw));
    if ($p === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $p)) {
        return false;
    }
    if (extension_loaded('bcmath')) {
        if (bccomp($p, '0', 2) !== 1) {
            return false;
        }
    } elseif ((float) $p <= 0.0) {
        return false;
    }

    $r = trim(loan_normalize_decimal_input($rateRaw, true));
    $fixedOverride = ($checksFields['interest_calc_method'] ?? '') === 'fixed'
        && $checksFields['monthly_interest'] !== null;

    if ($fixedOverride) {
        if ($r === '') {
            return ['principalStr' => $p, 'rateStr' => '0.000'];
        }
        if (!preg_match('/^\d{1,3}(\.\d{1,3})?$/', $r)) {
            return false;
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($r, '0', 3) < 0) {
                return false;
            }
        } elseif ((float) $r < 0.0) {
            return false;
        }

        return ['principalStr' => $p, 'rateStr' => loan_format_annual_rate_db_string($r)];
    }

    if ($r === '' || !preg_match('/^\d{1,3}(\.\d{1,3})?$/', $r)) {
        return false;
    }
    if (extension_loaded('bcmath')) {
        if (bccomp($r, '0', 3) !== 1) {
            return false;
        }
    } elseif ((float) $r <= 0.0) {
        return false;
    }

    return ['principalStr' => $p, 'rateStr' => loan_format_annual_rate_db_string($r)];
}

/**
 * Column names present on `loans` in the current database (cached per request).
 *
 * @return array<string, true>
 */
function loan_loans_column_name_index(): array
{
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $colRows = dbAll(
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['loans']
    );
    $idx = [];
    foreach ($colRows as $colRow) {
        $idx[(string) $colRow['c']] = true;
    }

    return $idx;
}

/**
 * Comma-separated SELECT expressions for optional checklist columns (always aliased as monthly_interest, interest_calc_method, principal_payment_monthly).
 *
 * @param string $aliasPrefix e.g. "l." or ""
 */
function loan_sql_select_checks_column_expressions(string $aliasPrefix): string
{
    $names = loan_loans_column_name_index();
    $p = $aliasPrefix;
    $monthlyExpr = isset($names['monthly_interest'])
        ? $p . 'monthly_interest'
        : 'CAST(NULL AS DECIMAL(12,2)) AS monthly_interest';
    $methodExpr = isset($names['interest_calc_method'])
        ? $p . 'interest_calc_method'
        : "'fixed' AS interest_calc_method";
    $ppmExpr = isset($names['principal_payment_monthly'])
        ? $p . 'principal_payment_monthly'
        : 'CAST(NULL AS DECIMAL(12,2)) AS principal_payment_monthly';

    return $monthlyExpr . ', ' . $methodExpr . ', ' . $ppmExpr;
}

/**
 * Loans for GET /checks: only reference optional columns when they exist so production DBs
 * that predate interest_calc_method (or other checklist fields) do not error.
 *
 * @return list<array<string, mixed>>
 */
function checks_fetch_loan_rows_for_checks_page(): array
{
    $sql = 'SELECT l.id, l.name, l.origin_date, l.principal_amount, l.annual_interest_rate, '
        . loan_sql_select_checks_column_expressions('l.')
        . ', l.payment_type, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
        . 'ORDER BY e.name ASC, l.name ASC';

    return dbAll($sql, []);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($rawPath) && $rawPath !== '' ? $rawPath : '/';
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/') ?: '/';
}

$routeKey = $method . ' ' . $path;

$routes = [
    'GET /login' => static function (): void {
        $title = 'Login';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<form method="post" action="/login">' . csrf_field() . '<button type="submit">Sign in</button></form>';
        echo '</body></html>';
    },
    'POST /login' => static function (): void {
        csrf_verify_or_die();
        login(1);
        header('Location: /');
        exit;
    },
    'POST /logout' => static function (): void {
        csrf_verify_or_die();
        logout();
        header('Location: /login');
        exit;
    },
    'GET /' => static function (): void {
        $title = 'Dashboard';
        $heading = 'Dashboard';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<p>' . e($heading) . '</p>';
        echo '<p><a href="/borrowers">Borrowers</a> · <a href="/entities">Entities</a> · <a href="/loans">Loans</a> · <a href="/checks">Checks</a></p>';
        echo '<form method="post" action="/logout">' . csrf_field() . '<button type="submit">Sign out</button></form>';
        echo '</body></html>';
    },
    'GET /borrowers' => static function (): void {
        $title = 'Borrowers';
        $rows = dbAll('SELECT id, name, notes FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/borrowers/new">New borrower</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="4">No borrowers yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($name) . '</td>';
                echo '<td class="px-3 py-2">' . e($notes) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/borrowers/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /borrowers/new' => static function (): void {
        $title = 'New borrower';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>';
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/new">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4"></textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /borrowers/new' => static function (): void {
        csrf_verify_or_die();
        $name = trim((string) ($_POST['name'] ?? ''));
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '') {
            header('Location: /borrowers/new');
            exit;
        }
        $notes = $notesRaw === '' ? null : $notesRaw;
        $stmt = db()->prepare('INSERT INTO borrowers (name, notes) VALUES (?, ?)');
        $stmt->execute([$name, $notes]);
        header('Location: /borrowers');
        exit;
    },
    'GET /borrowers/edit' => static function (): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /borrowers');
            exit;
        }
        $row = dbOne('SELECT id, name, notes FROM borrowers WHERE id = ?', [$id]);
        if ($row === null) {
            header('Location: /borrowers');
            exit;
        }
        $title = 'Edit borrower';
        $bid = (string) ($row['id'] ?? '');
        $nameVal = (string) ($row['name'] ?? '');
        $notesVal = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>';
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/edit">';
        echo csrf_field();
        echo '<input type="hidden" name="id" value="' . e($bid) . '">';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4">' . e($notesVal) . '</textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /borrowers/edit' => static function (): void {
        csrf_verify_or_die();
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        if ($id < 1) {
            header('Location: /borrowers');
            exit;
        }
        if ($name === '') {
            header('Location: /borrowers/edit?id=' . $id);
            exit;
        }
        $notes = $notesRaw === '' ? null : $notesRaw;
        $stmt = db()->prepare('UPDATE borrowers SET name = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $notes, $id]);
        header('Location: /borrowers');
        exit;
    },
    'GET /entities' => static function (): void {
        $title = 'Entities';
        $rows = dbAll(
            'SELECT e.id, e.name, e.borrower_id, b.name AS borrower_name FROM entities e INNER JOIN borrowers b ON b.id = e.borrower_id ORDER BY b.name ASC, e.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/entities/new">New entity</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Borrower</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="4">No entities yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $borrowerName = (string) ($row['borrower_name'] ?? '');
                $entityName = (string) ($row['name'] ?? '');
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($borrowerName) . '</td>';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/entities/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /entities/new' => static function (): void {
        $title = 'New entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>';
        if ($borrowers === []) {
            echo '<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/new">';
            echo csrf_field();
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>';
            foreach ($borrowers as $b) {
                $bid = (string) ($b['id'] ?? '');
                $bname = (string) ($b['name'] ?? '');
                echo '<option value="' . e($bid) . '">' . e($bname) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /entities/new' => static function (): void {
        csrf_verify_or_die();
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($borrowerId < 1 || $name === '') {
            header('Location: /entities/new');
            exit;
        }
        $check = db()->prepare('SELECT id FROM borrowers WHERE id = ?');
        $check->execute([$borrowerId]);
        if ($check->fetch() === false) {
            header('Location: /entities/new');
            exit;
        }
        $stmt = db()->prepare('INSERT INTO entities (borrower_id, name) VALUES (?, ?)');
        $stmt->execute([$borrowerId, $name]);
        header('Location: /entities');
        exit;
    },
    'GET /entities/edit' => static function (): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /entities');
            exit;
        }
        $row = dbOne('SELECT id, borrower_id, name FROM entities WHERE id = ?', [$id]);
        if ($row === null) {
            header('Location: /entities');
            exit;
        }
        $title = 'Edit entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        $eid = (string) ($row['id'] ?? '');
        $curBorrowerId = (string) ($row['borrower_id'] ?? '');
        $nameVal = (string) ($row['name'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>';
        if ($borrowers === []) {
            echo '<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/edit">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . e($eid) . '">';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>';
            foreach ($borrowers as $b) {
                $bid = (string) ($b['id'] ?? '');
                $bname = (string) ($b['name'] ?? '');
                $sel = $bid === $curBorrowerId ? ' selected' : '';
                echo '<option value="' . e($bid) . '"' . $sel . '>' . e($bname) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /entities/edit' => static function (): void {
        csrf_verify_or_die();
        $id = (int) ($_POST['id'] ?? 0);
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id < 1) {
            header('Location: /entities');
            exit;
        }
        if ($borrowerId < 1 || $name === '') {
            header('Location: /entities/edit?id=' . $id);
            exit;
        }
        $check = db()->prepare('SELECT id FROM borrowers WHERE id = ?');
        $check->execute([$borrowerId]);
        if ($check->fetch() === false) {
            header('Location: /entities/edit?id=' . $id);
            exit;
        }
        $stmt = db()->prepare('UPDATE entities SET borrower_id = ?, name = ? WHERE id = ?');
        $stmt->execute([$borrowerId, $name, $id]);
        header('Location: /entities');
        exit;
    },
    'GET /loans' => static function (): void {
        $title = 'Loans';
        $idx = loan_loans_column_name_index();
        $monthlySel = isset($idx['monthly_interest'])
            ? 'l.monthly_interest'
            : 'CAST(NULL AS DECIMAL(12,2)) AS monthly_interest';
        $icmSel = isset($idx['interest_calc_method'])
            ? 'l.interest_calc_method'
            : "'fixed' AS interest_calc_method";
        $rows = dbAll(
            'SELECT l.id, l.name, l.funding_source, l.origin_date, l.maturity_date, l.payment_type, l.principal_amount, l.annual_interest_rate, '
            . $monthlySel . ', ' . $icmSel
            . ', l.prepaid_interest_amount, l.prepaid_interest_date, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-6xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/loans/new">New loan</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Name</th>';
        echo '<th class="px-3 py-2 font-medium">Funding</th><th class="px-3 py-2 font-medium">Origin</th><th class="px-3 py-2 font-medium">Maturity</th>';
        echo '<th class="px-3 py-2 font-medium">Principal</th><th class="px-3 py-2 font-medium">Rate %</th><th class="px-3 py-2 font-medium">Payment type</th>';
        echo '<th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="10">No loans yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $entityName = (string) ($row['entity_name'] ?? '');
                $loanName = (string) ($row['name'] ?? '');
                $funding = (string) ($row['funding_source'] ?? '');
                $origin = (string) ($row['origin_date'] ?? '');
                $maturity = $row['maturity_date'] !== null && $row['maturity_date'] !== '' ? (string) $row['maturity_date'] : '';
                $ptype = (string) ($row['payment_type'] ?? '');
                $principal = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '';
                $rate = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '';
                $monthlyIntStr = isset($row['monthly_interest']) && $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';
                $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
                if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
                    $calcMethod = 'fixed';
                }
                $impliedAnnual = null;
                if ($calcMethod === 'fixed'
                    && in_array($ptype, ['interest_only', 'amortizing'], true)
                    && loan_annual_interest_rate_is_blank_or_zero($rate)
                    && $monthlyIntStr !== '') {
                    $impliedAnnual = loan_implied_annual_percent_from_monthly_interest($principal, $monthlyIntStr);
                }
                if (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                    if ($impliedAnnual !== null) {
                        $estMonthly = checks_normalize_money_2($monthlyIntStr);
                    } else {
                        $estMonthly = loan_simple_monthly_interest($principal, $rate);
                    }
                } else {
                    $estMonthly = loan_simple_monthly_interest($principal, $rate);
                }
                $principalTitle = in_array($ptype, ['interest_only', 'amortizing'], true)
                    ? 'Est. monthly interest (full principal, not amortized): ' . $estMonthly
                    : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2">' . e($loanName) . '</td>';
                echo '<td class="px-3 py-2">' . e($funding) . '</td>';
                echo '<td class="px-3 py-2">' . e($origin) . '</td>';
                echo '<td class="px-3 py-2">' . e($maturity) . '</td>';
                echo '<td class="px-3 py-2"' . ($principalTitle !== '' ? ' title="' . e($principalTitle) . '"' : '') . '>' . e($principal) . '</td>';
                if ($impliedAnnual !== null) {
                    echo '<td class="px-3 py-2 italic text-slate-800" title="Implied annual %: monthly interest × 12 ÷ principal × 100 (stored annual rate is blank or zero).">' . e($impliedAnnual) . '</td>';
                } else {
                    echo '<td class="px-3 py-2">' . e($rate !== '' ? $rate : '0') . '</td>';
                }
                echo '<td class="px-3 py-2">' . e($ptype) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/loans/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /checks' => static function (): void {
        $title = 'Interest checks';
        $monthParam = $_GET['month'] ?? '';
        $selectedYm = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $parsedMonth = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($parsedMonth instanceof DateTimeImmutable && $parsedMonth->format('Y-m') === $monthParam) {
                $selectedYm = $monthParam;
            }
        }

        $rows = checks_fetch_loan_rows_for_checks_page();
        $monthlyRows = [];
        $prepaidRows = [];
        foreach ($rows as $row) {
            $ptype = (string) ($row['payment_type'] ?? '');
            if ($ptype === 'prepaid') {
                $prepaidRows[] = $row;

                continue;
            }
            if (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                $monthlyRows[] = $row;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-6xl space-y-6">';
        echo '<div class="flex flex-wrap items-end justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<form class="flex flex-wrap items-end gap-2" method="get" action="/checks">';
        echo '<div><label class="mb-1 block text-xs font-medium text-slate-600" for="month">Calendar month</label>';
        echo '<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="month" name="month" type="month" value="' . e($selectedYm) . '"></div>';
        echo '<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show</button>';
        echo '</form></div>';
        echo '<p class="text-sm text-slate-600">Read-only expected payment for <strong>interest-only</strong> and <strong>amortizing</strong> loans. For <strong>declining balance</strong>, the total is interest on the remaining balance plus the scheduled monthly principal (<code class="text-xs">principal_payment_monthly</code>). Paydown count for the selected month excludes the loan’s origin month (first modeled paydown is the month after the origin month). Prepaid loans are listed separately (unchanged). No data is saved from this page.</p>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a> · <a class="text-sm text-slate-600 underline" href="/loans">Loans</a>';

        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Method</th>';
        echo '<th class="px-3 py-2 font-medium">Expected payment</th><th class="px-3 py-2 font-medium">Done</th><th class="px-3 py-2 font-medium">Notes</th>';
        echo '</tr></thead><tbody>';
        if ($monthlyRows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="6">No interest-only or amortizing loans.</td></tr>';
        } else {
            foreach ($monthlyRows as $row) {
                $entityName = (string) ($row['entity_name'] ?? '');
                $loanName = (string) ($row['name'] ?? '');
                $origin = (string) ($row['origin_date'] ?? '');
                $principalStr = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '0.00';
                $annualStr = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '0.000';
                $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
                if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
                    $calcMethod = 'fixed';
                }
                $mppStr = $row['principal_payment_monthly'] !== null && $row['principal_payment_monthly'] !== '' ? (string) $row['principal_payment_monthly'] : '0.00';
                $monthlyIntStr = $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';

                $expectedCellHtml = '<span class="text-slate-400">—</span>';
                $notes = '';
                $checkAttrs = ' type="checkbox" class="h-4 w-4 rounded border-slate-300"';

                if ($calcMethod === 'fixed') {
                    if ($monthlyIntStr !== '') {
                        $paymentStr = checks_normalize_money_2($monthlyIntStr);
                    } else {
                        $paymentStr = loan_simple_monthly_interest($principalStr, $annualStr);
                    }
                    $expectedCellHtml = '<div class="font-medium text-slate-900">' . e($paymentStr) . '</div>';
                } else {
                    $monthsElapsed = loan_months_elapsed_to_calendar_month($origin, $selectedYm);
                    $remainingStr = loan_remaining_principal_after_paydowns($principalStr, $mppStr, $monthsElapsed);
                    if (extension_loaded('bcmath')) {
                        $paidOff = bccomp($remainingStr, '0', 2) <= 0;
                    } else {
                        $paidOff = (float) $remainingStr <= 0.0;
                    }
                    if ($paidOff) {
                        $expectedCellHtml = '<div class="font-medium text-slate-400">—</div><div class="text-xs text-slate-500">Paid off</div>';
                        $checkAttrs .= ' disabled';
                    } else {
                        $interestStr = checks_declining_monthly_interest($remainingStr, $annualStr);
                        $principalPortionStr = checks_normalize_money_2($mppStr);
                        $paymentStr = checks_add_money_2($interestStr, $principalPortionStr);
                        $expectedCellHtml = '<div class="font-medium text-slate-900">' . e($paymentStr) . '</div>'
                            . '<div class="text-xs text-slate-500">interest: $' . e($interestStr) . '</div>'
                            . '<div class="text-xs text-slate-500">principal: $' . e($principalPortionStr) . '</div>';
                    }
                }

                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2">' . e($loanName) . '</td>';
                echo '<td class="px-3 py-2">' . e($calcMethod) . '</td>';
                echo '<td class="px-3 py-2">' . $expectedCellHtml . '</td>';
                echo '<td class="px-3 py-2"><label class="inline-flex items-center gap-2"><input' . $checkAttrs . '> <span class="sr-only">Received</span></label></td>';
                echo '<td class="px-3 py-2 text-slate-600">' . e($notes) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';

        echo '<h2 class="text-lg font-semibold text-slate-800">Prepaid loans</h2>';
        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Notes</th>';
        echo '</tr></thead><tbody>';
        if ($prepaidRows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="3">No prepaid loans.</td></tr>';
        } else {
            foreach ($prepaidRows as $row) {
                $entityName = (string) ($row['entity_name'] ?? '');
                $loanName = (string) ($row['name'] ?? '');
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2">' . e($loanName) . '</td>';
                echo '<td class="px-3 py-2 text-slate-600">Prepaid — not part of monthly interest checklist.</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /loans/new' => static function (): void {
        $title = 'New loan';
        $entities = dbAll('SELECT id, name FROM entities ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The loan was not saved. For interest-only or amortizing: principal must be greater than zero. Annual rate must be greater than zero unless you use <strong>fixed</strong> with <strong>monthly interest</strong> filled in (then annual rate may be blank or zero). Use a dot or comma as the decimal separator (e.g. 100000.00 or 100000,00), optional US thousands, or a trailing % on the rate. Prepaid: amount and date required. Optional monthly amounts must be non-negative with at most two decimal places.</p>';
        }
        if ($entities === []) {
            echo '<p class="text-sm text-slate-600">No entities yet. <a class="underline" href="/entities/new">Create an entity</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/loans/new">';
            echo csrf_field();
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="entity_id">Entity</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="entity_id" name="entity_id" required>';
            foreach ($entities as $ent) {
                $eid = (string) ($ent['id'] ?? '');
                $ename = (string) ($ent['name'] ?? '');
                echo '<option value="' . e($eid) . '">' . e($ename) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="funding_source">Funding source</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="funding_source" name="funding_source" required>';
            echo '<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="origin_date">Origin date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="origin_date" name="origin_date" type="date" required></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="maturity_date">Maturity date (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="maturity_date" name="maturity_date" type="date"></div>';
            echo '<fieldset class="space-y-2"><legend class="mb-1 text-sm font-medium text-slate-700">Payment type</legend>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="interest_only" required> Interest only</label>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="amortizing"> Amortizing</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="payment_type" value="prepaid"> Prepaid</label></fieldset>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="annual_interest_rate">Annual interest rate (%)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="annual_interest_rate" name="annual_interest_rate" type="text" inputmode="decimal" placeholder="e.g. 12.500"></div>';
            echo '<fieldset class="space-y-3 rounded border border-slate-200 p-3"><legend class="text-sm font-medium text-slate-700">Checks &amp; amortization</legend>';
            echo '<p class="text-xs text-slate-500">For <strong>interest-only</strong> or <strong>amortizing</strong> loans (ignored if you choose prepaid). Used on the Checks page: <strong>declining balance</strong> adds monthly principal below to expected payment.</p>';
            echo '<div><span class="mb-1 block text-sm font-medium text-slate-700">Interest calculation method</span>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="fixed" required checked> Fixed</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="declining_balance"> Declining balance</label></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="Leave blank to derive from principal and rate on Checks"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_payment_monthly">Monthly principal payment (paydown)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_payment_monthly" name="principal_payment_monthly" type="text" inputmode="decimal" placeholder="0.00 — typical for amortizing"></div>';
            echo '</fieldset>';
            echo '<p class="text-xs text-slate-500">Interest only and amortizing: principal required; annual rate required unless <strong>fixed</strong> with <strong>monthly interest</strong> set (then Checks uses that amount; leave monthly interest blank to derive from principal and rate on Checks). Prepaid: prepaid amount and date are required (principal/rate may be zero). Optional amounts: non-negative, up to two decimal places.</p>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'GET /loans/edit' => static function (): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /loans');
            exit;
        }
        $loan = dbOne(
            'SELECT id, entity_id, name, funding_source, origin_date, maturity_date, payment_type, principal_amount, annual_interest_rate, '
            . loan_sql_select_checks_column_expressions('')
            . ', prepaid_interest_amount, prepaid_interest_date FROM loans WHERE id = ?',
            [$id]
        );
        if ($loan === null) {
            header('Location: /loans');
            exit;
        }
        $title = 'Edit loan';
        $entities = dbAll('SELECT id, name FROM entities ORDER BY name ASC', []);
        $lid = (string) ($loan['id'] ?? '');
        $curEntityId = (string) ($loan['entity_id'] ?? '');
        $nameVal = (string) ($loan['name'] ?? '');
        $funding = (string) ($loan['funding_source'] ?? '');
        $origin = (string) ($loan['origin_date'] ?? '');
        $maturity = $loan['maturity_date'] !== null && $loan['maturity_date'] !== '' ? (string) $loan['maturity_date'] : '';
        $ptype = (string) ($loan['payment_type'] ?? '');
        $principalVal = $loan['principal_amount'] !== null && $loan['principal_amount'] !== '' ? (string) $loan['principal_amount'] : '';
        $rateVal = $loan['annual_interest_rate'] !== null && $loan['annual_interest_rate'] !== '' ? (string) $loan['annual_interest_rate'] : '';
        $pamtVal = $loan['prepaid_interest_amount'] !== null && $loan['prepaid_interest_amount'] !== '' ? (string) $loan['prepaid_interest_amount'] : '';
        $pdateVal = $loan['prepaid_interest_date'] !== null && $loan['prepaid_interest_date'] !== '' ? (string) $loan['prepaid_interest_date'] : '';
        $icm = (string) ($loan['interest_calc_method'] ?? 'fixed');
        if (!in_array($icm, ['fixed', 'declining_balance'], true)) {
            $icm = 'fixed';
        }
        $chkIcFixed = $icm === 'fixed' ? ' checked' : '';
        $chkIcDecl = $icm === 'declining_balance' ? ' checked' : '';
        $mIntVal = $loan['monthly_interest'] !== null && $loan['monthly_interest'] !== '' ? (string) $loan['monthly_interest'] : '';
        $mppVal = $loan['principal_payment_monthly'] !== null && $loan['principal_payment_monthly'] !== '' ? (string) $loan['principal_payment_monthly'] : '';
        $selJpm = $funding === 'JPM' ? ' selected' : '';
        $selNtrs = $funding === 'NTRS' ? ' selected' : '';
        $chkIo = $ptype === 'interest_only' ? ' checked' : '';
        $chkAm = $ptype === 'amortizing' ? ' checked' : '';
        $chkPre = $ptype === 'prepaid' ? ' checked' : '';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The loan was not saved. For interest-only or amortizing: principal must be greater than zero. Annual rate must be greater than zero unless you use <strong>fixed</strong> with <strong>monthly interest</strong> filled in (then annual rate may be blank or zero). Check number formats (use 100000.00 or 100000,00). Optional monthly amounts must be non-negative with at most two decimal places.</p>';
        }
        if ($entities === []) {
            echo '<p class="text-sm text-slate-600">No entities yet. <a class="underline" href="/entities/new">Create an entity</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/loans/edit">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . e($lid) . '">';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="entity_id">Entity</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="entity_id" name="entity_id" required>';
            foreach ($entities as $ent) {
                $eid = (string) ($ent['id'] ?? '');
                $ename = (string) ($ent['name'] ?? '');
                $sel = $eid === $curEntityId ? ' selected' : '';
                echo '<option value="' . e($eid) . '"' . $sel . '>' . e($ename) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="funding_source">Funding source</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="funding_source" name="funding_source" required>';
            echo '<option value="JPM"' . $selJpm . '>JPM</option><option value="NTRS"' . $selNtrs . '>NTRS</option></select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="origin_date">Origin date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="origin_date" name="origin_date" type="date" required value="' . e($origin) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="maturity_date">Maturity date (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="maturity_date" name="maturity_date" type="date" value="' . e($maturity) . '"></div>';
            echo '<fieldset class="space-y-2"><legend class="mb-1 text-sm font-medium text-slate-700">Payment type</legend>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="interest_only" required' . $chkIo . '> Interest only</label>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="amortizing"' . $chkAm . '> Amortizing</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="payment_type" value="prepaid"' . $chkPre . '> Prepaid</label></fieldset>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00" value="' . e($principalVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="annual_interest_rate">Annual interest rate (%)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="annual_interest_rate" name="annual_interest_rate" type="text" inputmode="decimal" placeholder="e.g. 12.500" value="' . e($rateVal) . '"></div>';
            echo '<fieldset class="space-y-3 rounded border border-slate-200 p-3"><legend class="text-sm font-medium text-slate-700">Checks &amp; amortization</legend>';
            echo '<p class="text-xs text-slate-500">For <strong>interest-only</strong> or <strong>amortizing</strong> loans (saved values are cleared if you switch to prepaid). Declining balance uses monthly principal below on the Checks page.</p>';
            echo '<div><span class="mb-1 block text-sm font-medium text-slate-700">Interest calculation method</span>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="fixed" required' . $chkIcFixed . '> Fixed</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="declining_balance"' . $chkIcDecl . '> Declining balance</label></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="Leave blank to derive from principal and rate on Checks" value="' . e($mIntVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_payment_monthly">Monthly principal payment (paydown)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_payment_monthly" name="principal_payment_monthly" type="text" inputmode="decimal" placeholder="0.00 — typical for amortizing" value="' . e($mppVal) . '"></div>';
            echo '</fieldset>';
            echo '<p class="text-xs text-slate-500">Interest only and amortizing: principal required; annual rate required unless <strong>fixed</strong> with <strong>monthly interest</strong> set. Prepaid: prepaid amount and date are required. Optional amounts: non-negative, up to two decimal places.</p>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00" value="' . e($pamtVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date" value="' . e($pdateVal) . '"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /loans/new' => static function (): void {
        csrf_verify_or_die();

        $parseDate = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $parseDecimal = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
                return null;
            }

            return $s;
        };

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $funding = (string) ($_POST['funding_source'] ?? '');
        $originRaw = trim((string) ($_POST['origin_date'] ?? ''));
        $maturityRaw = trim((string) ($_POST['maturity_date'] ?? ''));
        $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
        $principalRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $rateRaw = loan_normalize_decimal_input((string) ($_POST['annual_interest_rate'] ?? ''), true);
        $prepaidAmtRaw = loan_normalize_decimal_input((string) ($_POST['prepaid_interest_amount'] ?? ''));
        $prepaidDateRaw = trim((string) ($_POST['prepaid_interest_date'] ?? ''));

        $redirect = static function (): void {
            header('Location: /loans/new?invalid=1');
            exit;
        };

        if ($entityId < 1 || $name === '' || !in_array($funding, ['JPM', 'NTRS'], true) || !in_array($paymentType, ['interest_only', 'prepaid', 'amortizing'], true)) {
            $redirect();
        }

        $origin = $parseDate($originRaw);
        if ($origin === null) {
            $redirect();
        }

        $maturity = $maturityRaw === '' ? null : $parseDate($maturityRaw);
        if ($maturityRaw !== '' && $maturity === null) {
            $redirect();
        }

        $checksFields = loan_parse_checks_fields_from_post($paymentType);
        if ($checksFields === false) {
            $redirect();
        }

        $principalStr = '0.00';
        $rateStr = '0.00';
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($paymentType === 'prepaid') {
            $prepaidAmount = $parseDecimal($prepaidAmtRaw);
            $prepaidDate = $parseDate($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                $redirect();
            }
            if (extension_loaded('bcmath')) {
                if (bccomp($prepaidAmount, '0', 2) !== 1) {
                    $redirect();
                }
            } elseif ((float) $prepaidAmount <= 0.0) {
                $redirect();
            }
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect();
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            $redirect();
        }

        $idx = loan_loans_column_name_index();
        $insertCols = ['entity_id', 'name', 'principal_amount', 'annual_interest_rate'];
        $insertParams = [$entityId, $name, $principalStr, $rateStr];
        if (isset($idx['monthly_interest'])) {
            $insertCols[] = 'monthly_interest';
            $insertParams[] = $checksFields['monthly_interest'];
        }
        if (isset($idx['interest_calc_method'])) {
            $insertCols[] = 'interest_calc_method';
            $insertParams[] = $checksFields['interest_calc_method'];
        }
        if (isset($idx['principal_payment_monthly'])) {
            $insertCols[] = 'principal_payment_monthly';
            $insertParams[] = $checksFields['principal_payment_monthly'];
        }
        $insertCols = array_merge($insertCols, ['funding_source', 'origin_date', 'maturity_date', 'payment_type', 'prepaid_interest_amount', 'prepaid_interest_date', 'notes']);
        $insertParams = array_merge($insertParams, [$funding, $origin, $maturity, $paymentType, $prepaidAmount, $prepaidDate, null]);
        $ph = implode(', ', array_fill(0, count($insertParams), '?'));
        $sqlIns = 'INSERT INTO loans (' . implode(', ', $insertCols) . ') VALUES (' . $ph . ')';
        $stmt = db()->prepare($sqlIns);
        $stmt->execute($insertParams);
        header('Location: /loans');
        exit;
    },
    'POST /loans/edit' => static function (): void {
        csrf_verify_or_die();

        $parseDate = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $parseDecimal = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
                return null;
            }

            return $s;
        };

        $loanId = (int) ($_POST['id'] ?? 0);
        if ($loanId < 1) {
            header('Location: /loans');
            exit;
        }

        $redirect = static function (int $lid): void {
            header('Location: /loans/edit?id=' . $lid . '&invalid=1');
            exit;
        };

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $funding = (string) ($_POST['funding_source'] ?? '');
        $originRaw = trim((string) ($_POST['origin_date'] ?? ''));
        $maturityRaw = trim((string) ($_POST['maturity_date'] ?? ''));
        $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
        $principalRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $rateRaw = loan_normalize_decimal_input((string) ($_POST['annual_interest_rate'] ?? ''), true);
        $prepaidAmtRaw = loan_normalize_decimal_input((string) ($_POST['prepaid_interest_amount'] ?? ''));
        $prepaidDateRaw = trim((string) ($_POST['prepaid_interest_date'] ?? ''));

        if ($entityId < 1 || $name === '' || !in_array($funding, ['JPM', 'NTRS'], true) || !in_array($paymentType, ['interest_only', 'prepaid', 'amortizing'], true)) {
            $redirect($loanId);
        }

        $origin = $parseDate($originRaw);
        if ($origin === null) {
            $redirect($loanId);
        }

        $maturity = $maturityRaw === '' ? null : $parseDate($maturityRaw);
        if ($maturityRaw !== '' && $maturity === null) {
            $redirect($loanId);
        }

        $checksFields = loan_parse_checks_fields_from_post($paymentType);
        if ($checksFields === false) {
            $redirect($loanId);
        }

        $principalStr = '0.00';
        $rateStr = '0.00';
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($paymentType === 'prepaid') {
            $prepaidAmount = $parseDecimal($prepaidAmtRaw);
            $prepaidDate = $parseDate($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                $redirect($loanId);
            }
            if (extension_loaded('bcmath')) {
                if (bccomp($prepaidAmount, '0', 2) !== 1) {
                    $redirect($loanId);
                }
            } elseif ((float) $prepaidAmount <= 0.0) {
                $redirect($loanId);
            }
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect($loanId);
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            $redirect($loanId);
        }

        $exists = db()->prepare('SELECT id FROM loans WHERE id = ?');
        $exists->execute([$loanId]);
        if ($exists->fetch() === false) {
            header('Location: /loans');
            exit;
        }

        $idx = loan_loans_column_name_index();
        $setParts = ['entity_id = ?', 'name = ?', 'principal_amount = ?', 'annual_interest_rate = ?'];
        $updParams = [$entityId, $name, $principalStr, $rateStr];
        if (isset($idx['monthly_interest'])) {
            $setParts[] = 'monthly_interest = ?';
            $updParams[] = $checksFields['monthly_interest'];
        }
        if (isset($idx['interest_calc_method'])) {
            $setParts[] = 'interest_calc_method = ?';
            $updParams[] = $checksFields['interest_calc_method'];
        }
        if (isset($idx['principal_payment_monthly'])) {
            $setParts[] = 'principal_payment_monthly = ?';
            $updParams[] = $checksFields['principal_payment_monthly'];
        }
        $setParts = array_merge($setParts, ['funding_source = ?', 'origin_date = ?', 'maturity_date = ?', 'payment_type = ?', 'prepaid_interest_amount = ?', 'prepaid_interest_date = ?']);
        $updParams = array_merge($updParams, [$funding, $origin, $maturity, $paymentType, $prepaidAmount, $prepaidDate, $loanId]);
        $sqlUpd = 'UPDATE loans SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $stmt = db()->prepare($sqlUpd);
        $stmt->execute($updParams);
        header('Location: /loans');
        exit;
    },
];

if ($routeKey !== 'GET /login' && $routeKey !== 'POST /login') {
    require_login();
}

$handler = $routes[$routeKey] ?? null;
if (!is_callable($handler)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    $message = 'Not Found';
    echo e($message) . "\n";
    exit;
}

try {
    $handler();
} catch (Throwable $e) {
    error_log('priv-lending ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($showErrorDetail) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString();
        exit;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Application error.\n";
    exit;
}
