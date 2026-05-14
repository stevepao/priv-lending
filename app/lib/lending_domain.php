<?php

declare(strict_types=1);

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
 * True when the loan has no principal balance left for purposes of marking it closed on the edit screen.
 * Declining-balance interest-only / amortizing: remaining principal at calendar month $asOfYm (Y-m) is <= 0;
 * otherwise stored principal_amount is compared to zero.
 *
 * @param array<string, mixed> $loan
 */
function loan_principal_balance_zero_for_close(array $loan, string $asOfYm): bool
{
    $ptype = (string) ($loan['payment_type'] ?? '');
    $principalStr = $loan['principal_amount'] !== null && $loan['principal_amount'] !== '' ? (string) $loan['principal_amount'] : '0.00';
    $principalStr = checks_normalize_money_2($principalStr);
    $calcMethod = (string) ($loan['interest_calc_method'] ?? 'fixed');
    if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
        $calcMethod = 'fixed';
    }
    if ($calcMethod === 'declining_balance' && in_array($ptype, ['interest_only', 'amortizing'], true)) {
        $origin = (string) ($loan['origin_date'] ?? '');
        if ($origin === '') {
            return false;
        }
        $mppRaw = $loan['principal_payment_monthly'] ?? null;
        $mppStr = $mppRaw !== null && $mppRaw !== '' ? (string) $mppRaw : '0.00';
        $mppStr = checks_normalize_money_2($mppStr);
        $monthsElapsed = loan_months_elapsed_to_calendar_month($origin, $asOfYm);
        $remainingStr = loan_remaining_principal_after_paydowns($principalStr, $mppStr, $monthsElapsed);
        if (extension_loaded('bcmath')) {
            return bccomp($remainingStr, '0', 2) <= 0;
        }

        return (float) $remainingStr <= 0.0;
    }
    if (extension_loaded('bcmath')) {
        return bccomp($principalStr, '0', 2) <= 0;
    }

    return (float) $principalStr <= 0.0;
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
 * When closed_date exists: exclude loans for calendar months after the month of closed_date
 * (the close month remains visible on Checks).
 *
 * @return list<array<string, mixed>>
 */
function checks_fetch_loan_rows_for_checks_page(string $selectedYm): array
{
    $names = loan_loans_column_name_index();
    $statusClause = isset($names['status'])
        ? " AND (l.status IS NULL OR l.status = 'active')"
        : '';

    $closedClause = isset($names['closed_date'])
        ? " AND (l.closed_date IS NULL OR DATE_FORMAT(l.closed_date, '%Y-%m') >= ?)"
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
    if ($closedClause !== '') {
        $params[] = $selectedYm;
    }

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
        . $closedClause
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
