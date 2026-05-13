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
 * Load a PHP view from app/views/. Keys in $data are extracted as local variables for the template.
 *
 * @param array<string, mixed> $data
 */
function render(string $view, array $data = []): void
{
    extract($data);
    require __DIR__ . '/../app/views/' . $view . '.php';
}

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
function loan_parse_checks_fields_from_post()
{
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
 * Principal and annual rate for prepaid saves: principal may be zero during the prepaid window;
 * rate and checklist fields are still stored and used on Checks after prepaid_interest_date.
 *
 * @param array{monthly_interest: ?string, interest_calc_method: string, principal_payment_monthly: ?string} $checksFields
 *
 * @return array{principalStr: string, rateStr: string}|false
 */
function loan_principal_and_annual_for_prepaid_save(string $principalRaw, string $rateRaw, array $checksFields): array|false
{
    $p = trim(loan_normalize_decimal_input($principalRaw));
    if ($p === '') {
        $principalStr = '0.00';
    } elseif (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $p)) {
        return false;
    } else {
        if (extension_loaded('bcmath')) {
            if (bccomp($p, '0', 2) < 0) {
                return false;
            }
        } elseif ((float) $p < 0.0) {
            return false;
        }
        $principalStr = checks_normalize_money_2($p);
    }

    $fixedOverride = ($checksFields['interest_calc_method'] ?? '') === 'fixed'
        && $checksFields['monthly_interest'] !== null;

    $principalPositive = extension_loaded('bcmath')
        ? bccomp($principalStr, '0', 2) === 1
        : (float) $principalStr > 0.0;

    if ($fixedOverride) {
        $r = trim(loan_normalize_decimal_input($rateRaw, true));
        if ($r === '') {
            return ['principalStr' => $principalStr, 'rateStr' => '0.000'];
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

        return ['principalStr' => $principalStr, 'rateStr' => loan_format_annual_rate_db_string($r)];
    }

    $r = trim(loan_normalize_decimal_input($rateRaw, true));
    if (!$principalPositive) {
        if ($r === '') {
            return ['principalStr' => $principalStr, 'rateStr' => '0.000'];
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

        return ['principalStr' => $principalStr, 'rateStr' => loan_format_annual_rate_db_string($r)];
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

    return ['principalStr' => $principalStr, 'rateStr' => loan_format_annual_rate_db_string($r)];
}

/**
 * Whether the current database has a column (cached per request).
 */
function schema_table_has_column(string $table, string $column): bool
{
    static $cache = [];

    $key = $table . "\0" . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $row = dbOne(
        'SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
    $cache[$key] = $row !== null;

    return $cache[$key];
}

/**
 * True when migration 0007 applied: unique (loan_id, scheduled_check_ym, category) so a check can post interest and principal separately.
 */
function schema_cash_events_has_scheduled_category_unique(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!schema_table_has_column('cash_events', 'scheduled_check_ym')) {
        return $cache = false;
    }
    $row = dbOne(
        'SELECT 1 AS ok FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        ['cash_events', 'uq_cash_events_loan_scheduled_category']
    );

    return $cache = ($row !== null);
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
 * True when calendar month $selectedYm (Y-m) is still within the prepaid-interest window:
 * coverage runs through the month that contains prepaid_interest_date (inclusive).
 */
function checks_selected_month_within_prepaid_window(?string $prepaidInterestDate, string $selectedYm): bool
{
    if ($prepaidInterestDate === null) {
        return false;
    }
    $d = trim($prepaidInterestDate);
    if ($d === '') {
        return false;
    }
    if (strlen($d) >= 7 && preg_match('/^\d{4}-\d{2}/', $d) === 1) {
        $endYm = substr($d, 0, 7);
    } else {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $d);
        if (!$parsed instanceof DateTimeImmutable) {
            return false;
        }
        $endYm = $parsed->format('Y-m');
    }

    return strcmp($selectedYm, $endYm) <= 0;
}

/**
 * Loans for GET /checks: only reference optional columns when they exist so production DBs
 * that predate interest_calc_method (or other checklist fields) do not error.
 *
 * Rows are limited to loans active for the calendar month $selectedYm (Y-m): origin month must
 * be on or before that month, maturity (if set) must not end before that month, and status must
 * be active when the status column exists. Loans with a posted monthly check for that month are
 * still returned (checks_month_posted_event_id) so the UI can show Posted.
 *
 * @return list<array<string, mixed>>
 */
function checks_fetch_loan_rows_for_checks_page(string $selectedYm): array
{
    $names = loan_loans_column_name_index();
    $statusClause = isset($names['status'])
        ? " AND (l.status IS NULL OR l.status = 'active')"
        : '';

    $postedSelect = '';
    $params = [];
    if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
        $postedSelect = ', (SELECT ce.id FROM cash_events ce WHERE ce.loan_id = l.id AND ce.scheduled_check_ym = ? LIMIT 1) AS checks_month_posted_event_id';
        $params[] = $selectedYm;
    } else {
        $postedSelect = ', CAST(NULL AS UNSIGNED) AS checks_month_posted_event_id';
    }
    $params[] = $selectedYm;
    $params[] = $selectedYm;

    $prepaidReceivedExpr = schema_table_has_column('loans', 'prepaid_interest_received')
        ? 'l.prepaid_interest_received'
        : '0 AS prepaid_interest_received';

    $sql = 'SELECT l.id, l.name, l.origin_date, l.principal_amount, l.annual_interest_rate, '
        . loan_sql_select_checks_column_expressions('l.')
        . ', l.payment_type, l.prepaid_interest_amount, l.prepaid_interest_date, '
        . $prepaidReceivedExpr . ', l.funding_source, e.name AS entity_name' . $postedSelect . ' FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
        . 'WHERE l.origin_date IS NOT NULL '
        . "AND DATE_FORMAT(l.origin_date, '%Y-%m') <= ? "
        . "AND (l.maturity_date IS NULL OR DATE_FORMAT(l.maturity_date, '%Y-%m') >= ?)"
        . $statusClause
        . ' ORDER BY e.name ASC, l.name ASC';

    return dbAll($sql, $params);
}

/**
 * Expected payment total for a loan row on /checks for the given calendar month (matches GET /checks logic).
 * Returns null when there is nothing to collect (e.g. declining balance paid off for that month).
 *
 * @param array<string, mixed> $row
 */
function checks_expected_payment_total_for_row(array $row, string $selectedYm): ?string
{
    $origin = (string) ($row['origin_date'] ?? '');
    if ($origin === '') {
        return null;
    }
    $principalStr = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '0.00';
    $annualStr = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '0.000';
    $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
    if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
        $calcMethod = 'fixed';
    }
    $mppStr = $row['principal_payment_monthly'] !== null && $row['principal_payment_monthly'] !== '' ? (string) $row['principal_payment_monthly'] : '0.00';
    $monthlyIntStr = $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';

    if ($calcMethod === 'fixed') {
        if ($monthlyIntStr !== '') {
            $paymentStr = checks_normalize_money_2($monthlyIntStr);
        } else {
            $paymentStr = loan_simple_monthly_interest($principalStr, $annualStr);
        }
    } else {
        $monthsElapsed = loan_months_elapsed_to_calendar_month($origin, $selectedYm);
        $remainingStr = loan_remaining_principal_after_paydowns($principalStr, $mppStr, $monthsElapsed);
        if (extension_loaded('bcmath')) {
            $paidOff = bccomp($remainingStr, '0', 2) <= 0;
        } else {
            $paidOff = (float) $remainingStr <= 0.0;
        }
        if ($paidOff) {
            return null;
        }
        $interestStr = checks_declining_monthly_interest($remainingStr, $annualStr);
        $principalPortionStr = checks_normalize_money_2($mppStr);
        $paymentStr = checks_add_money_2($interestStr, $principalPortionStr);
    }

    if (extension_loaded('bcmath')) {
        if (bccomp($paymentStr, '0', 2) !== 1) {
            return null;
        }
    } elseif ((float) $paymentStr <= 0.0) {
        return null;
    }

    return $paymentStr;
}

/**
 * Interest vs principal portions for posting a monthly check (same math as checks_expected_payment_total_for_row).
 * Fixed-calculation loans: full expected amount is interest; principal portion is 0.
 * Declining balance: interest on remaining balance plus monthly principal paydown.
 *
 * @param array<string, mixed> $row
 *
 * @return array{interest: string, principal_in: string}|null
 */
function checks_expected_payment_interest_principal_split_for_row(array $row, string $selectedYm): ?array
{
    $origin = (string) ($row['origin_date'] ?? '');
    if ($origin === '') {
        return null;
    }
    $principalAmountStr = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '0.00';
    $annualStr = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '0.000';
    $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
    if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
        $calcMethod = 'fixed';
    }
    $mppStr = $row['principal_payment_monthly'] !== null && $row['principal_payment_monthly'] !== '' ? (string) $row['principal_payment_monthly'] : '0.00';
    $monthlyIntStr = $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';

    if ($calcMethod === 'fixed') {
        if ($monthlyIntStr !== '') {
            $interestStr = checks_normalize_money_2($monthlyIntStr);
        } else {
            $interestStr = loan_simple_monthly_interest($principalAmountStr, $annualStr);
        }
        $principalInStr = checks_normalize_money_2('0.00');
    } else {
        $monthsElapsed = loan_months_elapsed_to_calendar_month($origin, $selectedYm);
        $remainingStr = loan_remaining_principal_after_paydowns($principalAmountStr, $mppStr, $monthsElapsed);
        if (extension_loaded('bcmath')) {
            $paidOff = bccomp($remainingStr, '0', 2) <= 0;
        } else {
            $paidOff = (float) $remainingStr <= 0.0;
        }
        if ($paidOff) {
            return null;
        }
        $interestStr = checks_declining_monthly_interest($remainingStr, $annualStr);
        $principalInStr = checks_normalize_money_2($mppStr);
    }

    $interestStr = checks_normalize_money_2($interestStr);
    $principalInStr = checks_normalize_money_2($principalInStr);
    $totalStr = checks_add_money_2($interestStr, $principalInStr);
    if (extension_loaded('bcmath')) {
        if (bccomp($totalStr, '0', 2) !== 1) {
            return null;
        }
    } elseif ((float) $totalStr <= 0.0) {
        return null;
    }

    return ['interest' => $interestStr, 'principal_in' => $principalInStr];
}

/**
 * @param array<string, mixed> $row
 */
function checks_funding_source_for_row(array $row): ?string
{
    $f = (string) ($row['funding_source'] ?? '');
    if (!in_array($f, ['JPM', 'NTRS'], true)) {
        return null;
    }

    return $f;
}

/** Whether prepaid interest has already been posted from Checks for this loan. */
function checks_prepaid_interest_already_received(array $row): bool
{
    return (int) ($row['prepaid_interest_received'] ?? 0) === 1;
}

/**
 * Normalized prepaid interest amount for cash event insert, or null if missing or not positive.
 *
 * @param array<string, mixed> $row
 */
function checks_prepaid_interest_amount_db_string(array $row): ?string
{
    $raw = $row['prepaid_interest_amount'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    $s = checks_normalize_money_2((string) $raw);
    if (extension_loaded('bcmath')) {
        if (bccomp($s, '0', 2) !== 1) {
            return null;
        }
    } elseif ((float) $s <= 0.0) {
        return null;
    }

    return $s;
}

/** True when a scheduled monthly check cash event exists for this loan and calendar month. */
function checks_monthly_check_already_posted(array $row): bool
{
    $id = $row['checks_month_posted_event_id'] ?? null;
    if ($id === null || $id === '') {
        return false;
    }

    return (int) $id > 0;
}

/**
 * Insert a funding cash event: principal_out with negative amount (same sign convention as /bank), tied to the loan.
 */
function loan_insert_funding_principal_out_cash_event(int $loanId, string $principalPosStr, string $eventDateYmd, string $funding, string $loanName): void
{
    $p = checks_normalize_money_2($principalPosStr);
    $neg = extension_loaded('bcmath') ? bcmul($p, '-1', 2) : number_format(-(float) $p, 2, '.', '');
    $notes = 'Loan funding (principal_out) — ' . $loanName;
    $pdo = db();
    if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
        $st = $pdo->prepare(
            'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)'
        );
        $st->execute([$loanId, $eventDateYmd, $neg, 'principal_out', $funding, $notes]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO cash_events (loan_id, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$loanId, $eventDateYmd, $neg, 'principal_out', $funding, $notes]);
    }
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'ChecksController.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'LoansController.php';

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
        echo '<p><a href="/borrowers">Borrowers</a> · <a href="/entities">Entities</a> · <a href="/loans">Loans</a> · <a href="/checks">Checks</a> · <a href="/cash-events">Cash events</a> · <a href="/bank">Bank</a></p>';
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
        (new LoansController())->index();
    },
    'GET /checks' => static function (): void {
        (new ChecksController())->index();
    },
    'POST /checks' => static function (): void {
        csrf_verify_or_die();

        $monthParam = trim((string) ($_POST['month'] ?? ''));
        $selectedYm = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (preg_match('/^\d{4}-\d{2}$/', $monthParam) === 1) {
            $parsedMonth = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($parsedMonth instanceof DateTimeImmutable && $parsedMonth->format('Y-m') === $monthParam) {
                $selectedYm = $monthParam;
            }
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEvent = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        $eventDate = $parsedEvent instanceof DateTimeImmutable && $parsedEvent->format('Y-m-d') === $eventDateRaw
            ? $eventDateRaw
            : null;

        $loanIdsPost = $_POST['loan_ids'] ?? [];
        if (!is_array($loanIdsPost)) {
            $loanIdsPost = [];
        }
        $loanIdSet = [];
        foreach ($loanIdsPost as $raw) {
            $lid = (int) $raw;
            if ($lid > 0) {
                $loanIdSet[$lid] = true;
            }
        }
        $loanIdList = array_keys($loanIdSet);

        $prepaidIdsPost = $_POST['prepaid_loan_ids'] ?? [];
        if (!is_array($prepaidIdsPost)) {
            $prepaidIdsPost = [];
        }
        $prepaidIdSet = [];
        foreach ($prepaidIdsPost as $raw) {
            $pid = (int) $raw;
            if ($pid > 0) {
                $prepaidIdSet[$pid] = true;
            }
        }
        $prepaidIdList = array_keys($prepaidIdSet);

        if ($eventDate === null || ($loanIdList === [] && $prepaidIdList === [])) {
            header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=0');
            exit;
        }

        $rows = checks_fetch_loan_rows_for_checks_page($selectedYm);
        $eligibleMonthlyById = [];
        $eligiblePrepaidById = [];
        foreach ($rows as $row) {
            $lid = (int) ($row['id'] ?? 0);
            if ($lid < 1) {
                continue;
            }
            $ptype = (string) ($row['payment_type'] ?? '');
            if ($ptype === 'prepaid') {
                $pDateRaw = $row['prepaid_interest_date'] ?? null;
                $pDateStr = $pDateRaw !== null && $pDateRaw !== '' ? (string) $pDateRaw : null;
                if (checks_selected_month_within_prepaid_window($pDateStr, $selectedYm)) {
                    $eligiblePrepaidById[$lid] = $row;
                } else {
                    $eligibleMonthlyById[$lid] = $row;
                }
            } elseif (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                $eligibleMonthlyById[$lid] = $row;
            }
        }

        if (!schema_table_has_column('cash_events', 'scheduled_check_ym')) {
            header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=0');
            exit;
        }

        $pdo = db();
        $insertCashStmt = $pdo->prepare(
            'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $posted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($loanIdList as $loanId) {
                if (!isset($eligibleMonthlyById[$loanId])) {
                    continue;
                }
                $row = $eligibleMonthlyById[$loanId];
                if (checks_monthly_check_already_posted($row)) {
                    continue;
                }
                $split = checks_expected_payment_interest_principal_split_for_row($row, $selectedYm);
                if ($split === null) {
                    continue;
                }
                $depositTo = checks_funding_source_for_row($row);
                if ($depositTo === null) {
                    continue;
                }
                $interestAmt = $split['interest'];
                $principalAmt = $split['principal_in'];
                $interestPositive = extension_loaded('bcmath')
                    ? bccomp($interestAmt, '0', 2) === 1
                    : (float) $interestAmt > 0.0;
                $principalPositive = extension_loaded('bcmath')
                    ? bccomp($principalAmt, '0', 2) === 1
                    : (float) $principalAmt > 0.0;
                if (!$interestPositive && !$principalPositive) {
                    continue;
                }
                $baseNotes = 'Checks ' . $selectedYm . ' (from /checks)';
                $sp = 'sp_ce_' . $loanId;
                $pdo->exec('SAVEPOINT `' . $sp . '`');
                try {
                    if ($interestPositive) {
                        $insertCashStmt->execute([
                            $loanId,
                            $selectedYm,
                            $eventDate,
                            $interestAmt,
                            'interest',
                            $depositTo,
                            $baseNotes . ' — interest',
                        ]);
                    }
                    if ($principalPositive) {
                        $insertCashStmt->execute([
                            $loanId,
                            $selectedYm,
                            $eventDate,
                            $principalAmt,
                            'principal_in',
                            $depositTo,
                            $baseNotes . ' — principal',
                        ]);
                    }
                    $pdo->exec('RELEASE SAVEPOINT `' . $sp . '`');
                    ++$posted;
                } catch (PDOException $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT `' . $sp . '`');
                    $sqlState = $e->errorInfo[1] ?? null;
                    if ((int) $sqlState === 1062) {
                        continue;
                    }
                    throw $e;
                }
            }

            if ($prepaidIdList !== [] && schema_table_has_column('loans', 'prepaid_interest_received')) {
                $updPrepaid = $pdo->prepare(
                    'UPDATE loans SET prepaid_interest_received = 1 WHERE id = ? AND payment_type = \'prepaid\' AND prepaid_interest_received = 0'
                );
                $delEvent = $pdo->prepare('DELETE FROM cash_events WHERE id = ?');
                foreach ($prepaidIdList as $loanId) {
                    if (!isset($eligiblePrepaidById[$loanId])) {
                        continue;
                    }
                    $row = $eligiblePrepaidById[$loanId];
                    if (checks_prepaid_interest_already_received($row)) {
                        continue;
                    }
                    $amountStr = checks_prepaid_interest_amount_db_string($row);
                    if ($amountStr === null) {
                        continue;
                    }
                    $depositTo = checks_funding_source_for_row($row);
                    if ($depositTo === null) {
                        continue;
                    }
                    $notes = 'Prepaid interest (Checks; month viewed ' . $selectedYm . ')';
                    $insertCashStmt->execute([$loanId, null, $eventDate, $amountStr, 'interest', $depositTo, $notes]);
                    $newEventId = (int) $pdo->lastInsertId();
                    $updPrepaid->execute([$loanId]);
                    if ($updPrepaid->rowCount() !== 1) {
                        if ($newEventId > 0) {
                            $delEvent->execute([$newEventId]);
                        }

                        continue;
                    }
                    ++$posted;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=' . ($posted > 0 ? '1' : '0'));
        exit;
    },
    'GET /cash-events' => static function (): void {
        $title = 'Cash events';
        $schSel = schema_table_has_column('cash_events', 'scheduled_check_ym')
            ? 'ce.scheduled_check_ym'
            : 'CAST(NULL AS CHAR(7)) AS scheduled_check_ym';
        $rows = dbAll(
            'SELECT ce.id, ce.loan_id, ' . $schSel . ', ce.event_date, ce.amount, ce.category, ce.deposit_to, ce.notes, '
            . 'l.name AS loan_name, e.name AS entity_name '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'LEFT JOIN entities e ON e.id = l.entity_id '
            . 'ORDER BY ce.event_date DESC, ce.id DESC LIMIT 500',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-6xl space-y-4">';
        echo '<div class="flex flex-wrap items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/cash-events/new">New cash event</a>';
        echo '</div>';
        echo '<p class="text-sm text-slate-600">Ledger of cash movements. Events from <strong>Checks</strong> include the scheduled month in <code class="text-xs">scheduled_check_ym</code> when set.</p>';
        echo '<p class="text-sm"><a class="text-slate-600 underline" href="/">Dashboard</a> · <a class="text-slate-600 underline" href="/checks">Checks</a> · <a class="text-slate-600 underline" href="/loans">Loans</a></p>';
        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Loan</th>';
        echo '<th class="px-3 py-2 font-medium">Amount</th><th class="px-3 py-2 font-medium">Category</th><th class="px-3 py-2 font-medium">Deposit to</th>';
        echo '<th class="px-3 py-2 font-medium">Check month</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="9">No cash events yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $ed = (string) ($row['event_date'] ?? '');
                $ent = (string) ($row['entity_name'] ?? '');
                $loan = (string) ($row['loan_name'] ?? '');
                if ($loan === '' && ($row['loan_id'] ?? null) === null) {
                    $loan = '—';
                } elseif ($loan === '') {
                    $loan = '#' . (string) ($row['loan_id'] ?? '');
                }
                $amt = $row['amount'] !== null && $row['amount'] !== '' ? (string) $row['amount'] : '';
                $cat = (string) ($row['category'] ?? '');
                $dep = $row['deposit_to'] !== null && $row['deposit_to'] !== '' ? (string) $row['deposit_to'] : '—';
                $scm = $row['scheduled_check_ym'] !== null && $row['scheduled_check_ym'] !== '' ? (string) $row['scheduled_check_ym'] : '—';
                $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($ed) . '</td>';
                echo '<td class="px-3 py-2">' . e($ent !== '' ? $ent : '—') . '</td>';
                echo '<td class="px-3 py-2">' . e($loan) . '</td>';
                echo '<td class="px-3 py-2 font-medium">' . e($amt) . '</td>';
                echo '<td class="px-3 py-2">' . e($cat) . '</td>';
                echo '<td class="px-3 py-2">' . e($dep) . '</td>';
                echo '<td class="px-3 py-2">' . e($scm) . '</td>';
                echo '<td class="px-3 py-2 text-slate-600">' . e($notes) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/cash-events/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /cash-events/new' => static function (): void {
        $title = 'New cash event';
        $loans = dbAll(
            'SELECT l.id, l.name, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<p class="text-sm text-slate-600">Record a payment or adjustment outside the monthly Checks flow. These events are not tied to a scheduled check month.</p>';
        echo '<a class="text-sm text-slate-600 underline" href="/cash-events">Back to cash events</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please fix the highlighted fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/cash-events/new">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan (optional)</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id">';
        echo '<option value="">— None —</option>';
        foreach ($loans as $lr) {
            $lid = (string) ($lr['id'] ?? '');
            $label = e((string) ($lr['entity_name'] ?? '')) . ' — ' . e((string) ($lr['name'] ?? ''));
            echo '<option value="' . e($lid) . '">' . $label . '</option>';
        }
        echo '</select></div>';
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="event_date">Event date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" required value="' . e($today) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="amount">Amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="category">Category</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="category" name="category" required>';
        foreach (['interest', 'principal_in', 'loc_interest', 'principal_out'] as $c) {
            echo '<option value="' . e($c) . '"' . ($c === 'interest' ? ' selected' : '') . '>' . e($c) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="deposit_to">Deposit to</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="deposit_to" name="deposit_to">';
        echo '<option value="">—</option><option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3"></textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/cash-events">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /cash-events/new' => static function (): void {
        csrf_verify_or_die();

        $loanIdRaw = trim((string) ($_POST['loan_id'] ?? ''));
        $loanId = $loanIdRaw === '' ? null : (int) $loanIdRaw;
        if ($loanId !== null && $loanId < 1) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEv = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if (!$parsedEv instanceof DateTimeImmutable || $parsedEv->format('Y-m-d') !== $eventDateRaw) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $amountRaw = loan_normalize_decimal_input((string) ($_POST['amount'] ?? ''));
        $amountTrim = trim($amountRaw);
        if ($amountTrim === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountTrim)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($amountTrim, '0', 2) !== 1) {
                header('Location: /cash-events/new?invalid=1');
                exit;
            }
        } elseif ((float) $amountTrim <= 0.0) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }
        $amountStr = checks_normalize_money_2($amountTrim);

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $depRaw = trim((string) ($_POST['deposit_to'] ?? ''));
        $depositTo = $depRaw === '' ? null : $depRaw;
        if ($depositTo !== null && !in_array($depositTo, ['JPM', 'NTRS'], true)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if ($loanId !== null) {
            $exists = dbOne('SELECT id FROM loans WHERE id = ?', [$loanId]);
            if ($exists === null) {
                header('Location: /cash-events/new?invalid=1');
                exit;
            }
        }

        if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
            $stmt = db()->prepare(
                'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO cash_events (loan_id, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes]);
        }
        header('Location: /cash-events');
        exit;
    },
    'GET /cash-events/edit' => static function (): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /cash-events');
            exit;
        }
        $schCol = schema_table_has_column('cash_events', 'scheduled_check_ym');
        $schSel = $schCol
            ? 'ce.scheduled_check_ym'
            : 'CAST(NULL AS CHAR(7)) AS scheduled_check_ym';
        $event = dbOne(
            'SELECT ce.id, ce.loan_id, ' . $schSel . ', ce.event_date, ce.amount, ce.category, ce.deposit_to, ce.notes '
            . 'FROM cash_events ce WHERE ce.id = ?',
            [$id]
        );
        if ($event === null) {
            header('Location: /cash-events');
            exit;
        }
        $title = 'Edit cash event';
        $loans = dbAll(
            'SELECT l.id, l.name, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        $curLoanId = $event['loan_id'] !== null && $event['loan_id'] !== '' ? (string) (int) $event['loan_id'] : '';
        $eventDateVal = (string) ($event['event_date'] ?? '');
        $amountVal = $event['amount'] !== null && $event['amount'] !== '' ? (string) $event['amount'] : '';
        $catVal = (string) ($event['category'] ?? 'interest');
        $depVal = $event['deposit_to'] !== null && $event['deposit_to'] !== '' ? (string) $event['deposit_to'] : '';
        $notesVal = $event['notes'] !== null && $event['notes'] !== '' ? (string) $event['notes'] : '';
        $scmVal = $event['scheduled_check_ym'] !== null && $event['scheduled_check_ym'] !== '' ? (string) $event['scheduled_check_ym'] : '';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<p class="text-sm text-slate-600">Update this cash event. The scheduled check month (if any) stays linked to this row and is not changed here.</p>';
        echo '<a class="text-sm text-slate-600 underline" href="/cash-events">Back to cash events</a>';
        if ($schCol && $scmVal !== '') {
            echo '<p class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Linked check month: <code class="text-xs">' . e($scmVal) . '</code> (from Checks posting).</p>';
        }
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please fix the highlighted fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/cash-events/edit">';
        echo csrf_field();
        echo '<input type="hidden" name="id" value="' . e((string) $id) . '">';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan (optional)</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id">';
        echo '<option value=""' . ($curLoanId === '' ? ' selected' : '') . '>— None —</option>';
        foreach ($loans as $lr) {
            $lid = (string) ($lr['id'] ?? '');
            $label = e((string) ($lr['entity_name'] ?? '')) . ' — ' . e((string) ($lr['name'] ?? ''));
            $sel = $lid === $curLoanId ? ' selected' : '';
            echo '<option value="' . e($lid) . '"' . $sel . '>' . $label . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="event_date">Event date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" required value="' . e($eventDateVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="amount">Amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00" value="' . e($amountVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="category">Category</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="category" name="category" required>';
        foreach (['interest', 'principal_in', 'loc_interest', 'principal_out'] as $c) {
            $sel = $c === $catVal ? ' selected' : '';
            echo '<option value="' . e($c) . '"' . $sel . '>' . e($c) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="deposit_to">Deposit to</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="deposit_to" name="deposit_to">';
        echo '<option value=""' . ($depVal === '' ? ' selected' : '') . '>—</option>';
        echo '<option value="JPM"' . ($depVal === 'JPM' ? ' selected' : '') . '>JPM</option><option value="NTRS"' . ($depVal === 'NTRS' ? ' selected' : '') . '>NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3">' . e($notesVal) . '</textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/cash-events">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /cash-events/edit' => static function (): void {
        csrf_verify_or_die();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            header('Location: /cash-events');
            exit;
        }

        $schCol = schema_table_has_column('cash_events', 'scheduled_check_ym');
        $sel = $schCol ? 'loan_id, scheduled_check_ym, category' : 'loan_id, category';
        $existing = dbOne('SELECT ' . $sel . ' FROM cash_events WHERE id = ?', [$id]);
        if ($existing === null) {
            header('Location: /cash-events');
            exit;
        }
        $oldLoanId = $existing['loan_id'] !== null && $existing['loan_id'] !== '' ? (int) $existing['loan_id'] : null;

        $schYmVal = null;
        if ($schCol) {
            $v = $existing['scheduled_check_ym'] ?? null;
            $schYmVal = is_string($v) && $v !== '' ? $v : null;
        }

        $redirectInvalid = static function (int $eid): void {
            header('Location: /cash-events/edit?id=' . $eid . '&invalid=1');
            exit;
        };

        $loanIdRaw = trim((string) ($_POST['loan_id'] ?? ''));
        $loanId = $loanIdRaw === '' ? null : (int) $loanIdRaw;
        if ($loanId !== null && $loanId < 1) {
            $redirectInvalid($id);
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEv = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if (!$parsedEv instanceof DateTimeImmutable || $parsedEv->format('Y-m-d') !== $eventDateRaw) {
            $redirectInvalid($id);
        }

        $amountRaw = loan_normalize_decimal_input((string) ($_POST['amount'] ?? ''));
        $amountTrim = trim($amountRaw);
        if ($amountTrim === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountTrim)) {
            $redirectInvalid($id);
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($amountTrim, '0', 2) !== 1) {
                $redirectInvalid($id);
            }
        } elseif ((float) $amountTrim <= 0.0) {
            $redirectInvalid($id);
        }
        $amountStr = checks_normalize_money_2($amountTrim);

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            $redirectInvalid($id);
        }

        $depRaw = trim((string) ($_POST['deposit_to'] ?? ''));
        $depositTo = $depRaw === '' ? null : $depRaw;
        if ($depositTo !== null && !in_array($depositTo, ['JPM', 'NTRS'], true)) {
            $redirectInvalid($id);
        }

        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if ($loanId !== null) {
            $exists = dbOne('SELECT id FROM loans WHERE id = ?', [$loanId]);
            if ($exists === null) {
                $redirectInvalid($id);
            }
        }

        if ($schYmVal !== null) {
            $newLoanId = $loanId;
            if ($oldLoanId !== $newLoanId) {
                $catExisting = (string) ($existing['category'] ?? 'interest');
                $dup = dbOne(
                    'SELECT id FROM cash_events WHERE loan_id <=> ? AND scheduled_check_ym = ? AND category = ? AND id != ?',
                    [$newLoanId, $schYmVal, $catExisting, $id]
                );
                if ($dup !== null) {
                    $redirectInvalid($id);
                }
            }
        }

        $stmt = db()->prepare(
            'UPDATE cash_events SET loan_id = ?, event_date = ?, amount = ?, category = ?, deposit_to = ?, notes = ? WHERE id = ?'
        );
        $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes, $id]);
        header('Location: /cash-events');
        exit;
    },
    'GET /bank' => static function (): void {
        $title = 'Bank statement';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please correct the fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/bank">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="bank">Bank</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="bank" name="bank" required>';
        echo '<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="statement_date">Statement date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="statement_date" name="statement_date" type="date" required></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="interest_amount">Interest amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="interest_amount" name="interest_amount" type="text" inputmode="decimal" required placeholder="0.00"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00" value="0.00"></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /bank' => static function (): void {
        csrf_verify_or_die();

        $bank = (string) ($_POST['bank'] ?? '');
        if (!in_array($bank, ['JPM', 'NTRS'], true)) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $stmtDateRaw = trim((string) ($_POST['statement_date'] ?? ''));
        $parsedStmt = DateTimeImmutable::createFromFormat('Y-m-d', $stmtDateRaw);
        if (!$parsedStmt instanceof DateTimeImmutable || $parsedStmt->format('Y-m-d') !== $stmtDateRaw) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $intRaw = loan_normalize_decimal_input((string) ($_POST['interest_amount'] ?? ''));
        $prinRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $intTrim = trim($intRaw) === '' ? '0' : trim($intRaw);
        $prinTrim = trim($prinRaw) === '' ? '0' : trim($prinRaw);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $intTrim) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $prinTrim)) {
            header('Location: /bank?invalid=1');
            exit;
        }
        $intPos = checks_normalize_money_2($intTrim);
        $prinPos = checks_normalize_money_2($prinTrim);
        if (extension_loaded('bcmath')) {
            if (bccomp($intPos, '0', 2) === -1 || bccomp($prinPos, '0', 2) === -1) {
                header('Location: /bank?invalid=1');
                exit;
            }
        } elseif ((float) $intPos < 0.0 || (float) $prinPos < 0.0) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $negLoc = extension_loaded('bcmath') ? bcmul($intPos, '-1', 2) : number_format(-(float) $intPos, 2, '.', '');
        $negPrin = extension_loaded('bcmath') ? bcmul($prinPos, '-1', 2) : number_format(-(float) $prinPos, 2, '.', '');

        $notesLoc = 'Bank statement ' . $stmtDateRaw . ' (loc_interest)';
        $notesPrin = 'Bank statement ' . $stmtDateRaw . ' (principal_out)';

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
                $ins = $pdo->prepare(
                    'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)'
                );
                $ins->execute([null, $stmtDateRaw, $negLoc, 'loc_interest', $bank, $notesLoc]);
                $ins->execute([null, $stmtDateRaw, $negPrin, 'principal_out', $bank, $notesPrin]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO cash_events (loan_id, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([null, $stmtDateRaw, $negLoc, 'loc_interest', $bank, $notesLoc]);
                $ins->execute([null, $stmtDateRaw, $negPrin, 'principal_out', $bank, $notesPrin]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /bank');
        exit;
    },
    'GET /loans/new' => static function (): void {
        (new LoansController())->create();
    },
    'GET /loans/edit' => static function (): void {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /loans');
            exit;
        }
        $fpSel = schema_table_has_column('loans', 'funding_principal_out_posted')
            ? 'funding_principal_out_posted'
            : 'CAST(0 AS UNSIGNED) AS funding_principal_out_posted';
        $loan = dbOne(
            'SELECT id, entity_id, name, funding_source, origin_date, maturity_date, payment_type, principal_amount, annual_interest_rate, '
            . loan_sql_select_checks_column_expressions('')
            . ', prepaid_interest_amount, prepaid_interest_date, ' . $fpSel . ' FROM loans WHERE id = ?',
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
        $hasFundingPostedCol = schema_table_has_column('loans', 'funding_principal_out_posted');
        $fundingPosted = $hasFundingPostedCol && (int) ($loan['funding_principal_out_posted'] ?? 0) === 1;
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The loan was not saved. For interest-only or amortizing: principal must be greater than zero. Annual rate must be greater than zero unless you use <strong>fixed</strong> with <strong>monthly interest</strong> filled in (then annual rate may be blank or zero). Check number formats (use 100000.00 or 100000,00). Optional monthly amounts must be non-negative with at most two decimal places. Posting a funding transaction requires positive principal and migration <code class="text-xs">0008_loans_funding_principal_out_posted.sql</code>.</p>';
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
            echo '<p class="text-xs text-slate-500">For <strong>interest-only</strong>, <strong>amortizing</strong>, and <strong>prepaid</strong> (post-prepaid Checks). Declining balance uses monthly principal below on the Checks page.</p>';
            echo '<div><span class="mb-1 block text-sm font-medium text-slate-700">Interest calculation method</span>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="fixed" required' . $chkIcFixed . '> Fixed</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="declining_balance"' . $chkIcDecl . '> Declining balance</label></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="Leave blank to derive from principal and rate on Checks" value="' . e($mIntVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_payment_monthly">Monthly principal payment (paydown)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_payment_monthly" name="principal_payment_monthly" type="text" inputmode="decimal" placeholder="0.00 — typical for amortizing" value="' . e($mppVal) . '"></div>';
            echo '</fieldset>';
            echo '<p class="text-xs text-slate-500">Interest only and amortizing: principal required; annual rate required unless <strong>fixed</strong> with <strong>monthly interest</strong> set. Prepaid: prepaid amount and date required; principal may be zero during prepaid, but rate, monthly interest, method, and paydown are saved for Checks after prepaid expires. Optional amounts: non-negative, up to two decimal places.</p>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00" value="' . e($pamtVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date" value="' . e($pdateVal) . '"></div>';
            if (!$hasFundingPostedCol) {
                echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Funding transaction tracking requires migration <code class="text-xs">0008_loans_funding_principal_out_posted.sql</code>. Run <code class="text-xs">php bin/migrate.php</code>.</p>';
            } elseif ($fundingPosted) {
                echo '<p class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900">Funding transaction (principal_out): Posted</p>';
            } else {
                echo '<label class="flex items-start gap-2 text-sm text-slate-800"><input class="mt-1 h-4 w-4 rounded border-slate-300" type="checkbox" name="post_funding_principal_out" value="1"> <span><span class="font-medium">Post funding transaction (principal_out) now</span><span class="block text-xs font-normal text-slate-500">Uses current principal, origin date, and funding source; amount is stored negative.</span></span></label>';
            }
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /loans/new' => static function (): void {
        (new LoansController())->store();
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

        $fundingPostedFlag = false;
        if (schema_table_has_column('loans', 'funding_principal_out_posted')) {
            $fpr = dbOne('SELECT funding_principal_out_posted AS v FROM loans WHERE id = ?', [$loanId]);
            $fundingPostedFlag = $fpr !== null && (int) ($fpr['v'] ?? 0) === 1;
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

        $checksFields = loan_parse_checks_fields_from_post();
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
            $parsed = loan_principal_and_annual_for_prepaid_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect($loanId);
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect($loanId);
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        $postFunding = isset($_POST['post_funding_principal_out']);
        if ($postFunding && !schema_table_has_column('loans', 'funding_principal_out_posted')) {
            $redirect($loanId);
        }
        if ($postFunding && $fundingPostedFlag) {
            $postFunding = false;
        }
        if ($postFunding) {
            if (extension_loaded('bcmath')) {
                if (bccomp($principalStr, '0', 2) !== 1) {
                    $redirect($loanId);
                }
            } elseif ((float) $principalStr <= 0.0) {
                $redirect($loanId);
            }
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
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sqlUpd);
            $stmt->execute($updParams);
            if ($postFunding) {
                loan_insert_funding_principal_out_cash_event($loanId, $principalStr, $origin, $funding, $name);
                $mark = $pdo->prepare('UPDATE loans SET funding_principal_out_posted = 1 WHERE id = ? AND funding_principal_out_posted = 0');
                $mark->execute([$loanId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
