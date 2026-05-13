<?php

declare(strict_types=1);

final class ChecksController
{
    public function index(): void
    {
        $title = 'Interest checks';
        $monthParam = $_GET['month'] ?? '';
        $selectedYm = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $parsedMonth = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($parsedMonth instanceof DateTimeImmutable && $parsedMonth->format('Y-m') === $monthParam) {
                $selectedYm = $monthParam;
            }
        }

        $rows = checks_fetch_loan_rows_for_checks_page($selectedYm);
        $monthlyRows = [];
        $prepaidRows = [];
        foreach ($rows as $row) {
            $ptype = (string) ($row['payment_type'] ?? '');
            if ($ptype === 'prepaid') {
                $pDateRaw = $row['prepaid_interest_date'] ?? null;
                $pDateStr = $pDateRaw !== null && $pDateRaw !== '' ? (string) $pDateRaw : null;
                if (checks_selected_month_within_prepaid_window($pDateStr, $selectedYm)) {
                    $prepaidRows[] = $row;
                } else {
                    $monthlyRows[] = $row;
                }

                continue;
            }
            if (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                $monthlyRows[] = $row;
            }
        }

        $monthlyDisplayRows = $this->buildMonthlyDisplayRows($monthlyRows, $selectedYm);
        $prepaidDisplayRows = $this->buildPrepaidDisplayRows($prepaidRows);

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $showScheduledCheckMigrationBanner = !schema_table_has_column('cash_events', 'scheduled_check_ym');
        $showPrepaidMigrationBanner = !schema_table_has_column('loans', 'prepaid_interest_received');
        $showChecksCategoryUniqueMigrationBanner = schema_table_has_column('cash_events', 'scheduled_check_ym')
            && !schema_cash_events_has_scheduled_category_unique();
        $postedSuccess = isset($_GET['posted']) && (string) $_GET['posted'] === '1';
        $postedFailure = isset($_GET['posted']) && (string) $_GET['posted'] === '0';

        header('Content-Type: text/html; charset=utf-8');
        render('checks', [
            'title' => $title,
            'selectedYm' => $selectedYm,
            'monthlyDisplayRows' => $monthlyDisplayRows,
            'prepaidDisplayRows' => $prepaidDisplayRows,
            'monthlyRowsEmpty' => $monthlyRows === [],
            'prepaidRowsEmpty' => $prepaidRows === [],
            'today' => $today,
            'showScheduledCheckMigrationBanner' => $showScheduledCheckMigrationBanner,
            'showPrepaidMigrationBanner' => $showPrepaidMigrationBanner,
            'showChecksCategoryUniqueMigrationBanner' => $showChecksCategoryUniqueMigrationBanner,
            'postedSuccess' => $postedSuccess,
            'postedFailure' => $postedFailure,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $monthlyRows
     *
     * @return list<array{entityName: string, loanName: string, calcMethod: string, expectedCellHtml: string, postCell: string, statusCell: string}>
     */
    private function buildMonthlyDisplayRows(array $monthlyRows, string $selectedYm): array
    {
        $out = [];
        foreach ($monthlyRows as $row) {
            $loanId = (int) ($row['id'] ?? 0);
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
            $paidOff = false;
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
                } else {
                    $interestStr = checks_declining_monthly_interest($remainingStr, $annualStr);
                    $principalPortionStr = checks_normalize_money_2($mppStr);
                    $paymentStr = checks_add_money_2($interestStr, $principalPortionStr);
                    $expectedCellHtml = '<div class="font-medium text-slate-900">' . e($paymentStr) . '</div>'
                        . '<div class="text-xs text-slate-500">interest: $' . e($interestStr) . '</div>'
                        . '<div class="text-xs text-slate-500">principal: $' . e($principalPortionStr) . '</div>';
                }
            }

            $monthlyPosted = checks_monthly_check_already_posted($row);
            $paymentTotal = checks_expected_payment_total_for_row($row, $selectedYm);

            if ($monthlyPosted) {
                $postCell = '<span class="text-slate-400">—</span>';
                $statusCell = '<span class="font-medium text-emerald-800">Posted</span>';
            } elseif ($calcMethod === 'declining_balance' && $paidOff) {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" disabled class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">Paid off</span>';
            } elseif ($paymentTotal === null) {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" disabled class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">No payment</span>';
            } else {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" name="loan_ids[]" value="'
                    . e((string) $loanId) . '" class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">Not posted</span>';
            }

            $out[] = [
                'entityName' => $entityName,
                'loanName' => $loanName,
                'calcMethod' => $calcMethod,
                'expectedCellHtml' => $expectedCellHtml,
                'postCell' => $postCell,
                'statusCell' => $statusCell,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $prepaidRows
     *
     * @return list<array{entityName: string, loanName: string, pAmtDisp: string, postCell: string, statusCell: string}>
     */
    private function buildPrepaidDisplayRows(array $prepaidRows): array
    {
        $out = [];
        foreach ($prepaidRows as $row) {
            $loanId = (int) ($row['id'] ?? 0);
            $entityName = (string) ($row['entity_name'] ?? '');
            $loanName = (string) ($row['name'] ?? '');
            $pAmt = checks_prepaid_interest_amount_db_string($row);
            $pAmtDisp = $pAmt !== null ? $pAmt : '—';
            $received = checks_prepaid_interest_already_received($row);
            $postCell = '<span class="text-slate-400">—</span>';
            $statusCell = '<span class="text-slate-500">—</span>';
            if ($received) {
                $statusCell = '<span class="font-medium text-emerald-800">Posted</span>';
            } elseif ($pAmt === null) {
                $statusCell = '<span class="text-amber-800">Invalid amount</span>';
            } elseif (!schema_table_has_column('loans', 'prepaid_interest_received')) {
                $statusCell = '<span class="text-slate-500">Migration required</span>';
            } else {
                $statusCell = '<span class="text-slate-600">Not posted</span>';
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" name="prepaid_loan_ids[]" value="'
                    . e((string) $loanId) . '" class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post prepaid interest</span></label>';
            }

            $out[] = [
                'entityName' => $entityName,
                'loanName' => $loanName,
                'pAmtDisp' => $pAmtDisp,
                'postCell' => $postCell,
                'statusCell' => $statusCell,
            ];
        }

        return $out;
    }
}
