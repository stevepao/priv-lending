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

/** Format money for UI tables: thousands separators and two decimals (e.g. 1,234.56). Blank in → blank out. */
function checks_format_money_display_2(string $amount): string
{
    $t = trim($amount);
    if ($t === '') {
        return '';
    }

    return number_format((float) checks_normalize_money_2($t), 2, '.', ',');
}

function date_range_parse_ymd_or_null(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

    return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
}

/**
 * @return array{start: string, end: string}
 */
function date_range_preset_bounds(string $preset, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $end = $today->format('Y-m-d');
    if ($preset === 'last_full_year') {
        $y = (int) $today->format('Y') - 1;

        return ['start' => $y . '-01-01', 'end' => $y . '-12-31'];
    }
    if ($preset === 'ytd') {
        return ['start' => $today->format('Y') . '-01-01', 'end' => $end];
    }
    if ($preset === 'quarter') {
        $month = (int) $today->format('n');
        $qStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        $start = $today->setDate((int) $today->format('Y'), $qStartMonth, 1)->format('Y-m-d');

        return ['start' => $start, 'end' => $end];
    }

    $start = $today->modify('first day of -2 months')->format('Y-m-d');

    return ['start' => $start, 'end' => $end];
}

/**
 * @param array<string, mixed> $get
 *
 * @return array{range: string, start: string, end: string, dateOrderError: bool}
 */
function date_range_filter_from_get(array $get): array
{
    $today = new DateTimeImmutable('today');
    $default = date_range_preset_bounds('last_3_months', $today);
    $range = isset($get['range']) ? (string) $get['range'] : 'last_3_months';
    $valid = ['last_3_months', 'last_full_year', 'ytd', 'quarter', 'custom'];
    if (!in_array($range, $valid, true)) {
        $range = 'last_3_months';
    }

    if ($range === 'custom') {
        $start = date_range_parse_ymd_or_null(isset($get['start']) ? (string) $get['start'] : '');
        $end = date_range_parse_ymd_or_null(isset($get['end']) ? (string) $get['end'] : '');
        if ($start === null || $end === null) {
            return [
                'range' => 'last_3_months',
                'start' => $default['start'],
                'end' => $default['end'],
                'dateOrderError' => false,
            ];
        }
        if ($start > $end) {
            return [
                'range' => 'custom',
                'start' => $start,
                'end' => $end,
                'dateOrderError' => true,
            ];
        }

        return [
            'range' => 'custom',
            'start' => $start,
            'end' => $end,
            'dateOrderError' => false,
        ];
    }

    $preset = date_range_preset_bounds($range, $today);

    return [
        'range' => $range,
        'start' => $preset['start'],
        'end' => $preset['end'],
        'dateOrderError' => false,
    ];
}

/**
 * Inclusive calendar months (Y-m) from start through end dates.
 *
 * @return list<string>
 */
function report_month_ym_keys_in_range(string $startYmd, string $endYmd): array
{
    $startMonth = DateTimeImmutable::createFromFormat('Y-m-d', $startYmd);
    $endMonth = DateTimeImmutable::createFromFormat('Y-m-d', $endYmd);
    if (!$startMonth instanceof DateTimeImmutable || !$endMonth instanceof DateTimeImmutable) {
        return [];
    }
    $cur = $startMonth->modify('first day of this month');
    $end = $endMonth->modify('first day of this month');
    $keys = [];
    while ($cur <= $end) {
        $keys[] = $cur->format('Y-m');
        $cur = $cur->modify('+1 month');
    }

    return $keys;
}

/**
 * @return array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}
 */
function report_metrics_from_category_sums(string $interestInRaw, string $locSumRaw, string $principalNetSumRaw): array
{
    $interestIn = checks_normalize_money_2($interestInRaw);
    $locSumNorm = checks_normalize_money_2($locSumRaw);
    $principalPaid = checks_normalize_money_2($principalNetSumRaw);
    if (extension_loaded('bcmath')) {
        $locInterestOut = bcmul($locSumNorm, '-1', 2);
        $netIncome = bcsub($interestIn, $locInterestOut, 2);
    } else {
        $locInterestOut = number_format(-(float) $locSumNorm, 2, '.', '');
        $netIncome = number_format((float) $interestIn - (float) $locInterestOut, 2, '.', '');
    }

    return [
        'interestIn' => $interestIn,
        'locInterestOut' => $locInterestOut,
        'netIncome' => $netIncome,
        'principalPaid' => $principalPaid,
    ];
}

/**
 * Positive draw weight from SUM(principal_out.amount): only negative totals (draws) count.
 */
function report_principal_out_draw_magnitude(string $rawSum): string
{
    $n = checks_normalize_money_2($rawSum);
    if (extension_loaded('bcmath')) {
        if (bccomp($n, '0', 2) < 0) {
            return bcmul($n, '-1', 2);
        }

        return '0.00';
    }

    $f = (float) $n;

    return $f < 0 ? number_format(-$f, 2, '.', '') : '0.00';
}

/**
 * Period LOC interest as a positive “out” pool (same sign convention as report_metrics_from_category_sums).
 */
function report_loc_interest_pool_positive(string $locInterestCategorySumRaw): string
{
    $locSumNorm = checks_normalize_money_2($locInterestCategorySumRaw);
    if (extension_loaded('bcmath')) {
        return bcmul($locSumNorm, '-1', 2);
    }

    return number_format(-(float) $locSumNorm, 2, '.', '');
}

/**
 * Metrics row when LOC out is an allocated positive dollar amount (not raw loc_interest category sum).
 *
 * @return array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}
 */
function report_metrics_from_interest_principal_alloc_loc(string $interestInRaw, string $locInterestOutPositive, string $principalNetSumRaw): array
{
    $interestIn = checks_normalize_money_2($interestInRaw);
    $locInterestOut = checks_normalize_money_2($locInterestOutPositive);
    $principalPaid = checks_normalize_money_2($principalNetSumRaw);
    if (extension_loaded('bcmath')) {
        $netIncome = bcsub($interestIn, $locInterestOut, 2);
    } else {
        $netIncome = number_format((float) $interestIn - (float) $locInterestOut, 2, '.', '');
    }

    return [
        'interestIn' => $interestIn,
        'locInterestOut' => $locInterestOut,
        'netIncome' => $netIncome,
        'principalPaid' => $principalPaid,
    ];
}

/**
 * Rough LOC allocation: for each bank with pool P, split P across segments in proportion to principal_out draw weights.
 *
 * @param array<string, string> $poolsByBank        deposit_to key (empty string when null) => positive pool
 * @param array<string, array<string, string>> $weightsBySegment segment key => bank key => positive weight
 *
 * @return array<string, string> segment key => allocated positive LOC
 */
function report_allocate_loc_interest_by_principal_weights(array $poolsByBank, array $weightsBySegment): array
{
    $alloc = [];
    foreach (array_keys($weightsBySegment) as $seg) {
        $alloc[$seg] = '0.00';
    }

    foreach ($poolsByBank as $bankKey => $poolRaw) {
        $pool = checks_normalize_money_2($poolRaw);
        if (extension_loaded('bcmath')) {
            if (bccomp($pool, '0', 2) <= 0) {
                continue;
            }
        } elseif ((float) $pool <= 0) {
            continue;
        }

        $wTotal = '0.00';
        foreach ($weightsBySegment as $banks) {
            $w = checks_normalize_money_2($banks[$bankKey] ?? '0.00');
            $wTotal = checks_add_money_2($wTotal, $w);
        }

        if (extension_loaded('bcmath')) {
            if (bccomp($wTotal, '0', 2) <= 0) {
                continue;
            }
        } elseif ((float) $wTotal <= 0) {
            continue;
        }

        foreach ($weightsBySegment as $seg => $banks) {
            $w = checks_normalize_money_2($banks[$bankKey] ?? '0.00');
            if (extension_loaded('bcmath')) {
                if (bccomp($w, '0', 2) <= 0) {
                    continue;
                }
                $piece = bcdiv(bcmul($pool, $w, 4), $wTotal, 2);
            } else {
                $piece = number_format((float) $pool * (float) $w / (float) $wTotal, 2, '.', '');
            }
            $alloc[$seg] = checks_add_money_2($alloc[$seg], $piece);
        }
    }

    return $alloc;
}

/**
 * @param array<string, string> $poolsByBank
 */
function report_sum_money_map(array $poolsByBank): string
{
    $t = '0.00';
    foreach ($poolsByBank as $v) {
        $t = checks_add_money_2($t, checks_normalize_money_2($v));
    }

    return checks_normalize_money_2($t);
}

/**
 * @param array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string} $a
 * @param array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string} $b
 *
 * @return array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}
 */
function report_metrics_add(array $a, array $b): array
{
    return [
        'interestIn' => checks_add_money_2($a['interestIn'], $b['interestIn']),
        'locInterestOut' => checks_add_money_2($a['locInterestOut'], $b['locInterestOut']),
        'netIncome' => checks_add_money_2($a['netIncome'], $b['netIncome']),
        'principalPaid' => checks_add_money_2($a['principalPaid'], $b['principalPaid']),
    ];
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
 * SQL scalar subquery (correlated on outer alias `l.id`) for principal ledger balance from cash_events.
 * Sums amount for categories principal_in and principal_out only (funding stored negative, repayments positive;
 * net zero when fully repaid).
 */
function loan_sql_cash_principal_balance_subquery(): string
{
    return "(SELECT COALESCE(SUM(ce.amount), 0) FROM cash_events ce WHERE ce.loan_id = l.id AND ce.category IN ('principal_in', 'principal_out'))";
}

/**
 * Sum of cash_events.amount for principal_in and principal_out for one loan (same as loans list; 2 dp).
 */
function loan_cash_principal_ledger_balance_raw(int $loanId): string
{
    $row = dbOne(
        "SELECT COALESCE(SUM(amount), 0) AS bal FROM cash_events WHERE loan_id = ? AND category IN ('principal_in', 'principal_out')",
        [$loanId]
    );
    $raw = is_array($row) ? (string) ($row['bal'] ?? '0') : '0';

    return checks_normalize_money_2($raw);
}

/** True when principal_in + principal_out amounts net to exactly zero for the loan. */
function loan_cash_principal_ledger_balance_is_zero(int $loanId): bool
{
    $s = loan_cash_principal_ledger_balance_raw($loanId);
    if (extension_loaded('bcmath')) {
        return bccomp($s, '0', 2) === 0;
    }

    return (float) $s === 0.0;
}

/** True when the loan has at least one cash event with category principal_out. */
function loan_cash_events_has_principal_out_for_loan(int $loanId): bool
{
    $row = dbOne(
        'SELECT 1 AS ok FROM cash_events WHERE loan_id = ? AND category = ? LIMIT 1',
        [$loanId, 'principal_out']
    );

    return $row !== null;
}

/**
 * True when the loan may be marked closed from the edit screen: net principal ledger is zero
 * and there is at least one principal_out entry (funding or bank draw), so closure is tied to
 * real principal movement, not only offsetting principal_in rows.
 */
function loan_eligible_to_mark_closed_from_ledger(int $loanId): bool
{
    return loan_cash_principal_ledger_balance_is_zero($loanId)
        && loan_cash_events_has_principal_out_for_loan($loanId);
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
 * True when the selected calendar month (Y-m) is strictly after the calendar month of the loan's origin date.
 * Monthly interest/principal checks are due starting the month after origin; the origin month has no monthly check row.
 *
 * Prepaid lump posting uses {@see checks_selected_month_within_prepaid_window} instead and may include the origin month.
 */
function checks_selected_month_is_after_loan_origin_month(string $originYmd, string $selectedYm): bool
{
    $originYmd = trim($originYmd);
    if ($originYmd === '' || !preg_match('/^\d{4}-\d{2}$/', $selectedYm)) {
        return false;
    }
    if (strlen($originYmd) >= 7 && preg_match('/^\d{4}-\d{2}/', $originYmd) === 1) {
        $originYm = substr($originYmd, 0, 7);
    } else {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $originYmd);
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $originYmd) {
            return false;
        }
        $originYm = $parsed->format('Y-m');
    }

    return strcmp($selectedYm, $originYm) > 0;
}

/**
 * Loans for GET /checks: only reference optional columns when they exist so production DBs
 * that predate interest_calc_method (or other checklist fields) do not error.
 *
 * Rows are limited to loans active for the calendar month $selectedYm (Y-m): origin month must
 * be on or before that month (so prepaid-window loans still load in the origin month), maturity (if set) must not end before that month, and status must
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
        . $prepaidReceivedExpr . ', l.funding_source' . $postedSelect . ' FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
        . 'WHERE l.origin_date IS NOT NULL '
        . "AND DATE_FORMAT(l.origin_date, '%Y-%m') <= ? "
        . "AND (l.maturity_date IS NULL OR DATE_FORMAT(l.maturity_date, '%Y-%m') >= ?)"
        . $closedClause
        . $statusClause
        . ' ORDER BY l.funding_source ASC, l.name ASC';

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
    if (!checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
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
    if (!checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
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
